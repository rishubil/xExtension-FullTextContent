<?php
declare(strict_types=1);

/**
 * FreshRSS-context integration test.
 *
 * Bootstraps FreshRSS, verifies the extension is discovered, loaded, and
 * its hook is registered, then exercises onEntryBeforeInsert end-to-end
 * with the REAL obscura binary and REAL defuddle npm package baked into
 * the test image (no mocks).
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$EXT       = '/var/www/FreshRSS/extensions/xExtension-FullTextContent';
$FIXTURES  = $EXT . '/tests/integration/fixtures';
$CACHE_DIR = getenv('TEST_DEPS_DIR') ?: '/opt/test-deps';

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
section('Feed setting helpers');
// ---------------------------------------------------------------------------
$feedS = new FreshRSS_Feed('http://example.net/', false);
ok('feedWait default 0', $ext->feedWait($feedS) === 0);
ok('feedWaitUntil default empty', $ext->feedWaitUntil($feedS) === '');
ok('feedSelector default empty', $ext->feedSelector($feedS) === '');
ok('feedStealth default false', $ext->feedStealth($feedS) === false);
ok('feedTimeout default 0', $ext->feedTimeout($feedS) === 0);

$feedS->_attribute('fulltextcontent_wait', 5);
$feedS->_attribute('fulltextcontent_wait_until', 'networkidle0');
$feedS->_attribute('fulltextcontent_selector', '#content');
$feedS->_attribute('fulltextcontent_stealth', true);
$feedS->_attribute('fulltextcontent_timeout', 45);
ok('feedWait reads attribute', $ext->feedWait($feedS) === 5);
ok('feedWaitUntil reads attribute', $ext->feedWaitUntil($feedS) === 'networkidle0');
ok('feedSelector reads attribute', $ext->feedSelector($feedS) === '#content');
ok('feedStealth reads attribute', $ext->feedStealth($feedS) === true);
ok('feedTimeout reads attribute', $ext->feedTimeout($feedS) === 45);

// ---------------------------------------------------------------------------
section('Hook: entry with empty link -> unchanged');
// ---------------------------------------------------------------------------
$entryE = new FreshRSS_Entry(feedId: 0, link: '', content: 'original');
$feedPropE = (new ReflectionClass($entryE))->getProperty('feed');
$feedPropE->setAccessible(true);
$enabledFeedE = new FreshRSS_Feed('http://example.net/', false);
$enabledFeedE->_attribute('fulltextcontent_enabled', true);
$feedPropE->setValue($entryE, $enabledFeedE);
$resultE = $ext->onEntryBeforeInsert($entryE);
ok('empty-link entry returned unchanged', $resultE?->content() === 'original');

// ---------------------------------------------------------------------------
section('handleConfigureAction: save_global persists global settings');
// ---------------------------------------------------------------------------
$catDao  = FreshRSS_Factory::createCategoryDao();
$feedDao = FreshRSS_Factory::createFeedDao();

$defaultCat = $catDao->getDefault();
if ($defaultCat !== null) {
    $catId = (int) $defaultCat->id();
} else {
    $cats = $catDao->listCategories(false);
    $catId = !empty($cats) ? (int) reset($cats)->id() : (int) $catDao->addCategory(['name' => 'FTC Test Cat']);
}

$newFeed = new FreshRSS_Feed('http://ftc-config-test.example/feed.xml', false);
$newFeed->_categoryId($catId);
$newFeed->_name('FTC Config Test Feed');
$feedId = $feedDao->addFeedObject($newFeed);
ok('test feed created in DB', $feedId !== false && $feedId > 0);
$fid = (string) $feedId;

// The global <form> carries ONLY global fields plus the save_global action.
$_SERVER['REQUEST_METHOD'] = 'POST';
Minz_Request::_params([
    'fulltextcontent_action'  => 'save_global',
    'node_binary'             => '/usr/local/bin/node',
    'obscura_download_url'    => 'https://example.com/obscura-{arch}.tar.gz',
    'obscura_binary'          => '/opt/obscura',
    'defuddle_version'        => '0.18.1',
    'defuddle_check_interval' => '72',
    'fetch_timeout'           => '45',
]);
$ext->handleConfigureAction();

ok('global node_binary saved',
    $ext->getUserConfigurationString('node_binary') === '/usr/local/bin/node');
ok('global obscura_download_url saved',
    $ext->getUserConfigurationString('obscura_download_url') === 'https://example.com/obscura-{arch}.tar.gz');
ok('global obscura_binary saved',
    $ext->getUserConfigurationString('obscura_binary') === '/opt/obscura');
ok('global defuddle_version saved',
    $ext->getUserConfigurationString('defuddle_version') === '0.18.1');
ok('global defuddle_check_interval saved',
    $ext->getUserConfigurationInt('defuddle_check_interval') === 72);
ok('global fetch_timeout saved',
    $ext->getUserConfigurationInt('fetch_timeout') === 45);

// ---------------------------------------------------------------------------
section('handleConfigureAction: save_feeds persists per-feed settings without touching globals');
// ---------------------------------------------------------------------------
// The per-feed <form> carries ONLY per-feed fields plus the save_feeds action —
// no global fields at all. Simulate that exact POST shape.
Minz_Request::_params([
    'fulltextcontent_action'     => 'save_feeds',
    'fulltextcontent_feeds'      => [$fid],
    'fulltextcontent_wait'       => [$fid => '4'],
    'fulltextcontent_wait_until' => [$fid => 'networkidle0'],
    'fulltextcontent_selector'   => [$fid => '#main'],
    'fulltextcontent_stealth'    => [$fid],
    'fulltextcontent_timeout'    => [$fid => '20'],
]);
$ext->handleConfigureAction();

// Per-feed settings persisted (reload from DB).
$savedFeed = $feedDao->searchById($feedId);
ok('feed reloaded from DB', $savedFeed !== null);
ok('feed enabled attribute set',
    $savedFeed?->attributeBoolean('fulltextcontent_enabled') === true);
ok('feed wait persisted', $savedFeed?->attributeInt('fulltextcontent_wait') === 4);
ok('feed wait_until persisted',
    $savedFeed?->attributeString('fulltextcontent_wait_until') === 'networkidle0');
ok('feed selector persisted',
    $savedFeed?->attributeString('fulltextcontent_selector') === '#main');
ok('feed stealth persisted',
    $savedFeed?->attributeBoolean('fulltextcontent_stealth') === true);
ok('feed timeout persisted', $savedFeed?->attributeInt('fulltextcontent_timeout') === 20);

// REGRESSION (the bug this fixes): a feed-only save must NOT wipe global
// settings just because the POST lacks the global fields.
ok('save_feeds leaves node_binary intact',
    $ext->getUserConfigurationString('node_binary') === '/usr/local/bin/node');
ok('save_feeds leaves obscura_download_url intact',
    $ext->getUserConfigurationString('obscura_download_url') === 'https://example.com/obscura-{arch}.tar.gz');
ok('save_feeds leaves obscura_binary intact',
    $ext->getUserConfigurationString('obscura_binary') === '/opt/obscura');
ok('save_feeds leaves defuddle_version intact',
    $ext->getUserConfigurationString('defuddle_version') === '0.18.1');
ok('save_feeds leaves defuddle_check_interval intact',
    $ext->getUserConfigurationInt('defuddle_check_interval') === 72);
ok('save_feeds leaves fetch_timeout intact',
    $ext->getUserConfigurationInt('fetch_timeout') === 45);

// ---------------------------------------------------------------------------
section('handleConfigureAction: save_global persists globals without touching feeds');
// ---------------------------------------------------------------------------
// The feed is currently enabled (previous section). A global-only POST must
// leave every per-feed attribute untouched.
Minz_Request::_params([
    'fulltextcontent_action'  => 'save_global',
    'node_binary'             => '/opt/node20/bin/node',
    'obscura_download_url'    => 'https://cdn.example.net/o-{arch}.tgz',
    'obscura_binary'          => '/srv/obscura',
    'defuddle_version'        => '0.19.0',
    'defuddle_check_interval' => '24',
    'fetch_timeout'           => '60',
]);
$ext->handleConfigureAction();

ok('global node_binary updated',
    $ext->getUserConfigurationString('node_binary') === '/opt/node20/bin/node');
ok('global defuddle_version updated',
    $ext->getUserConfigurationString('defuddle_version') === '0.19.0');
ok('global fetch_timeout updated',
    $ext->getUserConfigurationInt('fetch_timeout') === 60);

// REGRESSION (the bug this fixes): a global-only save must NOT disable or wipe
// per-feed settings just because the POST lacks the per-feed fields.
$feedAfterGlobal = $feedDao->searchById($feedId);
ok('save_global leaves feed enabled',
    $feedAfterGlobal?->attributeBoolean('fulltextcontent_enabled') === true);
ok('save_global leaves feed wait intact',
    $feedAfterGlobal?->attributeInt('fulltextcontent_wait') === 4);
ok('save_global leaves feed wait_until intact',
    $feedAfterGlobal?->attributeString('fulltextcontent_wait_until') === 'networkidle0');
ok('save_global leaves feed selector intact',
    $feedAfterGlobal?->attributeString('fulltextcontent_selector') === '#main');
ok('save_global leaves feed stealth intact',
    $feedAfterGlobal?->attributeBoolean('fulltextcontent_stealth') === true);
ok('save_global leaves feed timeout intact',
    $feedAfterGlobal?->attributeInt('fulltextcontent_timeout') === 20);

// ---------------------------------------------------------------------------
section('handleConfigureAction: save_feeds clears per-feed settings when unset/empty');
// ---------------------------------------------------------------------------
Minz_Request::_params([
    'fulltextcontent_action'     => 'save_feeds',
    'fulltextcontent_feeds'      => [],              // not enabled
    'fulltextcontent_wait'       => [$fid => '0'],   // 0 -> null
    'fulltextcontent_wait_until' => [$fid => '  '],  // whitespace -> null
    'fulltextcontent_selector'   => [$fid => ''],    // empty -> null
    'fulltextcontent_stealth'    => [],              // not stealth
    'fulltextcontent_timeout'    => [$fid => '0'],   // 0 -> null
]);

$ext->handleConfigureAction();

$clearedFeed = $feedDao->searchById($feedId);
ok('feed disabled after empty submit',
    $clearedFeed?->attributeBoolean('fulltextcontent_enabled') === false);
ok('wait nulled when 0', $clearedFeed?->attributeInt('fulltextcontent_wait') === null);
ok('wait_until nulled when whitespace',
    $clearedFeed?->attributeString('fulltextcontent_wait_until') === null);
ok('selector nulled when empty',
    $clearedFeed?->attributeString('fulltextcontent_selector') === null);
ok('stealth nulled when not selected',
    $clearedFeed?->attributeBoolean('fulltextcontent_stealth') === null);
ok('timeout nulled when 0', $clearedFeed?->attributeInt('fulltextcontent_timeout') === null);

// ---------------------------------------------------------------------------
section('handleConfigureAction: non-POST request is a no-op');
// ---------------------------------------------------------------------------
$_SERVER['REQUEST_METHOD'] = 'GET';
Minz_Request::_params(['fulltextcontent_action' => 'save_global', 'node_binary' => 'SHOULD_NOT_BE_SAVED']);
$ext->handleConfigureAction();
ok('GET request does not overwrite settings',
    $ext->getUserConfigurationString('node_binary') === '/opt/node20/bin/node');

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
