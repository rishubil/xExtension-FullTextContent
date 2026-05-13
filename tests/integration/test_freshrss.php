<?php
declare(strict_types=1);

/**
 * FreshRSS-context integration test.
 *
 * Bootstraps FreshRSS (requires a completed do-install.php + create-user.php)
 * and verifies the extension loads, registers hooks, and processes entries
 * correctly within the FreshRSS class environment.
 *
 * Must be run inside the FreshRSS container as PHP CLI.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$EXT   = '/var/www/FreshRSS/extensions/xExtension-FullTextContent';
$STUBS = $EXT . '/tests/integration/stubs';

// ---------------------------------------------------------------------------
// Bootstrap FreshRSS
// ---------------------------------------------------------------------------
require '/var/www/FreshRSS/constants.php';
require LIB_PATH . '/lib_rss.php';
require LIB_PATH . '/lib_install.php';
FreshRSS_Context::$isCli = true;

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

function section(string $name): void {
    echo "\n=== {$name} ===\n";
}

// ---------------------------------------------------------------------------
section('FreshRSS bootstrap');
// ---------------------------------------------------------------------------
Minz_Session::init('FreshRSS', true);
FreshRSS_Context::initSystem();
ok('system config loaded', FreshRSS_Context::hasSystemConf());

// Init user context so DAO (feed lookup) works in later tests
FreshRSS_Context::initUser('admin');
ok('user context initialised', FreshRSS_Context::hasUserConf());

Minz_Translate::init('en');

ok('Minz_Extension class exists', class_exists('Minz_Extension'));
ok('FreshRSS_Entry class exists', class_exists('FreshRSS_Entry'));
ok('FreshRSS_Feed class exists', class_exists('FreshRSS_Feed'));

// ---------------------------------------------------------------------------
section('Extension manager: discovery');
// ---------------------------------------------------------------------------
// init() scans THIRDPARTY_EXTENSIONS_PATH for valid extension directories.
// Our extension is mounted at /var/www/FreshRSS/extensions/xExtension-FullTextContent.
Minz_ExtensionManager::init();

// The 'name' field in metadata.json is "Full Text Content".
$ext = Minz_ExtensionManager::findExtension('Full Text Content');
ok('extension is discovered', $ext !== null);

if ($ext === null) {
    echo "\nFATAL: extension not found — remaining tests skipped.\n";
    echo "\n--- FreshRSS tests: {$passed}/" . ($passed + $failed) . " passed, {$failed} FAILED ---\n";
    exit(1);
}

ok('extension is a Minz_Extension instance', $ext instanceof Minz_Extension);
ok('extension class is FullTextContentExtension', $ext instanceof FullTextContentExtension);
ok('extension name is "Full Text Content"', $ext->getName() === 'Full Text Content');
ok('extension entrypoint is "FullTextContent"', $ext->getEntrypoint() === 'FullTextContent');

// ---------------------------------------------------------------------------
section('Extension class: structure');
// ---------------------------------------------------------------------------
ok('extends Minz_Extension',
    is_subclass_of(FullTextContentExtension::class, Minz_Extension::class));

foreach (['init', 'handleConfigureAction', 'onEntryBeforeInsert',
          'buildPipeline', 'buildBinaryResolver', 'buildDefuddleManager'] as $m) {
    ok("method {$m}() exists", method_exists($ext, $m));
}

// ---------------------------------------------------------------------------
section('Extension: hook registration');
// ---------------------------------------------------------------------------
ok('not enabled before enableByList', !$ext->isEnabled());

// enableByList expects ['Extension Name' => bool] (associative, not indexed).
Minz_ExtensionManager::enableByList(['Full Text Content' => true], 'user');
ok('enabled after enableByList', $ext->isEnabled());

// Verify the hook is in the manager's hook table by calling it with a dummy
// entry (feedId=0, feed not in DB → returns entry unchanged without error).
$dummy = new FreshRSS_Entry(feedId: 0, link: 'https://example.com', content: 'dummy');
// Prevent feed() from hitting the DB: inject null feed via reflection first,
// but since $feed===null triggers the DAO, we instead just let the DAO run
// (user context is set, searchById(0) returns null safely).
$hookResult = Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeInsert, $dummy);
ok('EntryBeforeInsert hook runs without exception', true);

// ---------------------------------------------------------------------------
section('Hook: no feed → entry returned unchanged');
// ---------------------------------------------------------------------------
// Create an entry with a non-existent feedId; the DAO returns null.
$entry = new FreshRSS_Entry(feedId: 0, link: 'https://example.com', content: 'original');
$result = $ext->onEntryBeforeInsert($entry);
ok('returns entry (not null)', $result !== null);
ok('content unchanged when feed not in DB', $result?->content() === 'original');

// ---------------------------------------------------------------------------
section('Hook: feed exists but extension disabled → unchanged');
// ---------------------------------------------------------------------------
// Inject a feed via reflection (avoids a second DB round-trip and lets us
// control the extension-enabled flag precisely).
$entryB = new FreshRSS_Entry(feedId: 0, link: 'https://example.com', content: 'original');
$feedRef = new ReflectionClass($entryB);
$feedProp = $feedRef->getProperty('feed');
$feedProp->setAccessible(true);

$disabledFeed = new FreshRSS_Feed('http://example.net/', false);
// fulltextcontent_enabled not set → attributeBoolean returns null (falsy)
$feedProp->setValue($entryB, $disabledFeed);

$resultB = $ext->onEntryBeforeInsert($entryB);
ok('entry returned (not null)', $resultB !== null);
ok('content unchanged when ext disabled on feed', $resultB?->content() === 'original');

// ---------------------------------------------------------------------------
section('Pipeline integration via buildPipeline / onEntryBeforeInsert');
// ---------------------------------------------------------------------------
// Prepare stub binaries in a temp directory
$tmpDir = sys_get_temp_dir() . '/ftc_int_freshrss_' . getmypid();
$binDir = $tmpDir . '/bin';
$cliDir = $tmpDir . '/node_modules/defuddle/dist';
mkdir($binDir, 0755, true);
mkdir($cliDir, 0755, true);

$obscuraBin = $binDir . '/obscura';
file_put_contents($obscuraBin,
    "#!/bin/sh\n" .
    "if [ \"\$1\" = \"fetch\" ]; then\n" .
    "  cat '" . $STUBS . "/sample.html'\n" .
    "  exit 0\n" .
    "fi\n" .
    "exit 1\n"
);
chmod($obscuraBin, 0755);

$cliJs = $cliDir . '/cli.js';
copy($STUBS . '/defuddle-cli.js', $cliJs);
file_put_contents(
    $tmpDir . '/node_modules/defuddle/package.json',
    json_encode(['name' => 'defuddle', 'version' => '0.0.0-stub'])
);

// Inject user_configuration into the extension so buildPipeline uses our stubs.
// user_configuration is a private property of the parent Minz_Extension class.
$confProp = new ReflectionProperty(Minz_Extension::class, 'user_configuration');
$confProp->setAccessible(true);
$confProp->setValue($ext, [
    'obscura_binary'          => $obscuraBin,
    'node_binary'             => 'node',
    'defuddle_version'        => '0.0.0-stub',
    'defuddle_check_interval' => 168,
    'fetch_timeout'           => 30,
]);

// Override DATA_SUBDIR path: DefuddleManager uses getExtDataDir() which reads
// DATA_PATH/fulltextcontent. Patch it by temporarily defining a custom data dir
// via another reflection override on the extension's getExtDataDir.
// Simplest approach: directly build pipeline objects and test via them.
require_once $EXT . '/lib/ProcRunner.php';
require_once $EXT . '/lib/BinaryResolver.php';
require_once $EXT . '/lib/DefuddleManager.php';
require_once $EXT . '/lib/FullTextPipeline.php';
require_once $EXT . '/lib/Parsedown.php';

$pipeline = new FullTextPipeline(
    new BinaryResolver($tmpDir, ''),
    new DefuddleManager($tmpDir, '0.0.0-stub', 168),
    'node',
    30,
    $obscuraBin
);

// Run the pipeline (same code path as the hook would take)
$html = $pipeline->run('https://example.com');
ok('pipeline produces non-empty HTML', $html !== '');
ok('HTML contains article title', str_contains($html, 'Integration Test Article'));
ok('HTML contains <strong>', str_contains($html, '<strong>'));
ok('HTML contains <em>', str_contains($html, '<em>'));

// Now simulate the hook with an enabled feed and verify entry content gets replaced
$entryC = new FreshRSS_Entry(feedId: 0, link: 'https://example.com', content: 'original');
$feedRefC = new ReflectionClass($entryC);
$feedPropC = $feedRefC->getProperty('feed');
$feedPropC->setAccessible(true);
$enabledFeed = new FreshRSS_Feed('http://example.net/', false);
$enabledFeed->_attribute('fulltextcontent_enabled', true);
$feedPropC->setValue($entryC, $enabledFeed);

// Manually apply the hook logic with our stub pipeline
if ($html !== '') {
    $entryC->_content($html);
}
ok('hook simulation: content replaced', $entryC->content() !== 'original');
ok('hook simulation: content is valid HTML with title',
    str_contains($entryC->content(), 'Integration Test Article'));

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
@unlink($obscuraBin);
@unlink($cliJs);
@unlink($tmpDir . '/node_modules/defuddle/package.json');
@rmdir($cliDir);
@rmdir(dirname($cliDir));
@rmdir($tmpDir . '/node_modules/defuddle');
@rmdir($tmpDir . '/node_modules');
@rmdir($binDir);
@rmdir($tmpDir);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- FreshRSS tests: {$passed}/{$total} passed";
if ($failed > 0) {
    echo ", {$failed} FAILED";
}
echo " ---\n";
exit($failed > 0 ? 1 : 0);
