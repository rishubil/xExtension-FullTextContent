<?php
declare(strict_types=1);

/**
 * Pipeline integration test — uses the REAL obscura binary and the REAL
 * defuddle npm package (no mocks). Both are baked into the test image at
 * /opt/test-deps (or the path given by the TEST_DEPS_DIR env var).
 *
 * The fixture HTML is loaded via a file:// URL so the container does not
 * require network access. obscura supports file:// out of the box.
 */

$EXT       = '/var/www/FreshRSS/extensions/xExtension-FullTextContent';
$FIXTURES  = $EXT . '/tests/integration/fixtures';
$CACHE_DIR = getenv('TEST_DEPS_DIR') ?: '/opt/test-deps';

require_once $EXT . '/lib/ProcRunner.php';
require_once $EXT . '/lib/BinaryResolver.php';
require_once $EXT . '/lib/DefuddleManager.php';
require_once $EXT . '/lib/FullTextPipeline.php';
require_once $EXT . '/lib/Parsedown.php';

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

$pinnedDefuddleVersion = getenv('DEFUDDLE_PINNED_VERSION') ?: '';
if ($pinnedDefuddleVersion === '') {
    die("ERROR: DEFUDDLE_PINNED_VERSION env var is not set.\n");
}

// ---------------------------------------------------------------------------
section('Real binaries are present in /cache');
// ---------------------------------------------------------------------------
ok('obscura binary exists and is executable', is_executable("{$CACHE_DIR}/bin/obscura"));
ok('defuddle CLI exists', is_file("{$CACHE_DIR}/node_modules/defuddle/dist/cli.js"));
ok('defuddle package.json exists', is_file("{$CACHE_DIR}/node_modules/defuddle/package.json"));

$pkgData = json_decode(
    (string) file_get_contents("{$CACHE_DIR}/node_modules/defuddle/package.json"),
    true
);
ok('defuddle is at the pinned version',
    is_array($pkgData) && ($pkgData['version'] ?? '') === $pinnedDefuddleVersion,
    'expected ' . $pinnedDefuddleVersion . ', got ' . ($pkgData['version'] ?? 'null'));

// ---------------------------------------------------------------------------
section('Real obscura CLI smoke test');
// ---------------------------------------------------------------------------
$obscuraHelp = ProcRunner::run([$CACHE_DIR . '/bin/obscura', '--help']);
ok('obscura --help exits 0', $obscuraHelp['exit_code'] === 0);
ok('obscura banner present', str_contains($obscuraHelp['stdout'], 'Obscura'));

$fileUrl = 'file://' . $FIXTURES . '/sample.html';
$obscuraFetch = ProcRunner::run(
    [$CACHE_DIR . '/bin/obscura', 'fetch', $fileUrl, '--dump', 'html'],
    '',
    30
);
ok('obscura fetch of file:// URL exits 0', $obscuraFetch['exit_code'] === 0,
    trim($obscuraFetch['stderr']));
ok('obscura output contains title', str_contains($obscuraFetch['stdout'], 'Integration Test Article'));

// ---------------------------------------------------------------------------
section('Real defuddle CLI smoke test');
// ---------------------------------------------------------------------------
$cliJs = $CACHE_DIR . '/node_modules/defuddle/dist/cli.js';
$defuddleHelp = ProcRunner::run(['node', $cliJs, '--help']);
ok('defuddle --help exits 0', $defuddleHelp['exit_code'] === 0);
ok('defuddle help mentions "parse"', str_contains($defuddleHelp['stdout'], 'parse'));

// ---------------------------------------------------------------------------
section('FullTextPipeline end-to-end (real obscura + real defuddle)');
// ---------------------------------------------------------------------------
$binaryResolver  = new BinaryResolver($CACHE_DIR, '');
$defuddleManager = new DefuddleManager($CACHE_DIR, $pinnedDefuddleVersion, 168);

ok('BinaryResolver detects cached obscura', $binaryResolver->isDownloaded());
ok('DefuddleManager sees pinned installed version',
    $defuddleManager->installedVersion() === $pinnedDefuddleVersion);

// ensureInstalled() must short-circuit on version match (no npm install attempt)
$cliPath = $defuddleManager->ensureInstalled();
ok('DefuddleManager::ensureInstalled returns cli.js path',
    $cliPath === $CACHE_DIR . '/node_modules/defuddle/dist/cli.js');

$pipeline = new FullTextPipeline(
    $binaryResolver,
    $defuddleManager,
    'node',
    30
);

$html = $pipeline->run($fileUrl);

ok('pipeline returns non-empty HTML', $html !== '');
ok('output contains first paragraph text',
    str_contains($html, 'first test paragraph that defuddle should extract verbatim'));
ok('output preserves <strong>bold text</strong>',
    str_contains($html, '<strong>bold text</strong>'));
ok('output preserves <em>italic text</em>',
    str_contains($html, '<em>italic text</em>'));
ok('output preserves reference link',
    preg_match('#<a[^>]*href="https://example\.org/ref"#', $html) === 1);
ok('output preserves <h2> Subheading',
    str_contains($html, '<h2>Subheading</h2>'));

// Defuddle should have stripped non-article content
ok('site banner is stripped',
    !str_contains($html, 'SITE_BANNER_TEXT_THAT_SHOULD_BE_STRIPPED'));
ok('sidebar links are stripped',
    !str_contains($html, 'SIDEBAR_LINK_1') && !str_contains($html, 'SIDEBAR_LINK_2'));
ok('footer text is stripped',
    !str_contains($html, 'FOOTER_TEXT_THAT_SHOULD_BE_STRIPPED'));
ok('inline <script> content is stripped',
    !str_contains($html, 'SCRIPT_BLOCK_THAT_SHOULD_BE_STRIPPED'));

// Parsedown safe-mode: no raw <script> tag in final output
ok('Parsedown safe-mode: no raw <script> tag', !str_contains($html, '<script'));

// ---------------------------------------------------------------------------
section('FullTextPipeline edge cases');
// ---------------------------------------------------------------------------
ok('empty URL returns empty string', $pipeline->run('') === '');

// Markdown hook surface (intercepts between defuddle and Parsedown)
$pipeline->onMarkdown(function (string $md): string {
    return $md . "\n\nHOOK_INJECTED_PARAGRAPH.";
});
$htmlWithHook = $pipeline->run($fileUrl);
ok('markdown hook output is appended',
    str_contains($htmlWithHook, 'HOOK_INJECTED_PARAGRAPH'));

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- Pipeline tests: {$passed}/{$total} passed";
if ($failed > 0) {
    echo ", {$failed} FAILED";
}
echo " ---\n";
exit($failed > 0 ? 1 : 0);
