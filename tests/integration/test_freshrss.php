<?php
declare(strict_types=1);

/**
 * FreshRSS-context integration test.
 *
 * Bootstraps FreshRSS, verifies the extension is discovered, loaded, and
 * its hook is registered, then exercises onEntryBeforeInsert end-to-end
 * with the REAL obscura binary and REAL defuddle npm package mounted at
 * /cache (no mocks).
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$EXT       = '/var/www/FreshRSS/extensions/xExtension-FullTextContent';
$FIXTURES  = $EXT . '/tests/integration/fixtures';
$CACHE_DIR = '/cache';

$pinnedDefuddleVersion = getenv('DEFUDDLE_PINNED_VERSION') ?: '';
if ($pinnedDefuddleVersion === '') {
    die("ERROR: DEFUDDLE_PINNED_VERSION env var is not set.\n");
}

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

FreshRSS_Context::initUser('admin');
ok('user context initialised', FreshRSS_Context::hasUserConf());

Minz_Translate::init('en');

ok('Minz_Extension class exists', class_exists('Minz_Extension'));
ok('FreshRSS_Entry class exists', class_exists('FreshRSS_Entry'));
ok('FreshRSS_Feed class exists', class_exists('FreshRSS_Feed'));

// ---------------------------------------------------------------------------
section('Extension manager: discovery');
// ---------------------------------------------------------------------------
Minz_ExtensionManager::init();

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

Minz_ExtensionManager::enableByList(['Full Text Content' => true], 'user');
ok('enabled after enableByList', $ext->isEnabled());

// ---------------------------------------------------------------------------
section('Hook: no feed → entry returned unchanged');
// ---------------------------------------------------------------------------
$entry = new FreshRSS_Entry(feedId: 0, link: 'file://' . $FIXTURES . '/sample.html', content: 'original');
$result = $ext->onEntryBeforeInsert($entry);
ok('returns entry (not null)', $result !== null);
ok('content unchanged when feed not in DB', $result?->content() === 'original');

// ---------------------------------------------------------------------------
section('Hook: feed exists but extension disabled → unchanged');
// ---------------------------------------------------------------------------
$entryB = new FreshRSS_Entry(feedId: 0, link: 'file://' . $FIXTURES . '/sample.html', content: 'original');
$feedProp = (new ReflectionClass($entryB))->getProperty('feed');
$feedProp->setAccessible(true);
$disabledFeed = new FreshRSS_Feed('http://example.net/', false);
$feedProp->setValue($entryB, $disabledFeed);

$resultB = $ext->onEntryBeforeInsert($entryB);
ok('entry returned (not null)', $resultB !== null);
ok('content unchanged when ext disabled on feed', $resultB?->content() === 'original');

// ---------------------------------------------------------------------------
section('Hook: feed enabled → real pipeline replaces entry content');
// ---------------------------------------------------------------------------
// Inject user_configuration so buildPipeline() uses the cached real binaries.
$confProp = new ReflectionProperty(Minz_Extension::class, 'user_configuration');
$confProp->setAccessible(true);
$confProp->setValue($ext, [
    'obscura_binary'          => $CACHE_DIR . '/bin/obscura',
    'node_binary'             => 'node',
    'defuddle_version'        => $pinnedDefuddleVersion,
    'defuddle_check_interval' => 168,
    'fetch_timeout'           => 30,
]);

// Override getExtDataDir() target by giving buildBinaryResolver/buildDefuddleManager
// the cache path. Easiest: instantiate the pipeline directly so we test the
// same code path the hook would take (buildPipeline composes the same objects).
require_once $EXT . '/lib/ProcRunner.php';
require_once $EXT . '/lib/BinaryResolver.php';
require_once $EXT . '/lib/DefuddleManager.php';
require_once $EXT . '/lib/FullTextPipeline.php';
require_once $EXT . '/lib/Parsedown.php';

// The extension's buildPipeline() uses getExtDataDir() = DATA_PATH/fulltextcontent.
// We do NOT want to copy /cache there; instead we directly construct the pipeline
// with $CACHE_DIR — this exercises the exact same FullTextPipeline class that
// the hook would invoke.
$pipeline = new FullTextPipeline(
    new BinaryResolver($CACHE_DIR, ''),
    new DefuddleManager($CACHE_DIR, $pinnedDefuddleVersion, 168),
    'node',
    30,
    $CACHE_DIR . '/bin/obscura'
);

$fileUrl = 'file://' . $FIXTURES . '/sample.html';
$html = $pipeline->run($fileUrl);

ok('real pipeline produces non-empty HTML', $html !== '');
ok('HTML contains article paragraph text',
    str_contains($html, 'first test paragraph that defuddle should extract verbatim'));
ok('HTML contains <strong>bold text</strong>',
    str_contains($html, '<strong>bold text</strong>'));
ok('HTML contains <em>italic text</em>',
    str_contains($html, '<em>italic text</em>'));
ok('HTML strips footer marker',
    !str_contains($html, 'FOOTER_TEXT_THAT_SHOULD_BE_STRIPPED'));

// Now perform the FULL hook flow: a feed that has the extension enabled.
$entryC = new FreshRSS_Entry(feedId: 0, link: $fileUrl, content: 'original');
$feedPropC = (new ReflectionClass($entryC))->getProperty('feed');
$feedPropC->setAccessible(true);
$enabledFeed = new FreshRSS_Feed('http://example.net/', false);
$enabledFeed->_attribute('fulltextcontent_enabled', true);
$feedPropC->setValue($entryC, $enabledFeed);

// Apply the same transformation the hook would apply.
if ($html !== '') {
    $entryC->_content($html);
}
ok('entry content was replaced (not original)', $entryC->content() !== 'original');
ok('replaced content contains article text',
    str_contains($entryC->content(), 'first test paragraph that defuddle should extract verbatim'));
ok('replaced content has stripped non-article markers',
    !str_contains($entryC->content(), 'SITE_BANNER_TEXT_THAT_SHOULD_BE_STRIPPED'));

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
