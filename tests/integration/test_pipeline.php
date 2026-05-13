<?php
declare(strict_types=1);

/**
 * Pipeline integration test.
 *
 * Runs standalone inside the FreshRSS container (no FreshRSS bootstrap needed).
 * Uses stub binaries (stubs/obscura, stubs/defuddle-cli.js) so no real
 * network access or npm install is required.
 */

$EXT = '/var/www/FreshRSS/extensions/xExtension-FullTextContent';
$STUBS = $EXT . '/tests/integration/stubs';

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

// ---------------------------------------------------------------------------
// Prepare a writable temp data directory with pre-seeded fake defuddle
// ---------------------------------------------------------------------------
$tmpDir = sys_get_temp_dir() . '/ftc_int_pipeline_' . getmypid();
$binDir = $tmpDir . '/bin';
$cliDir = $tmpDir . '/node_modules/defuddle/dist';

mkdir($binDir, 0755, true);
mkdir($cliDir, 0755, true);

// Stub obscura: shell script that cat's sample.html
$obscuraBin = $binDir . '/obscura';
file_put_contents($obscuraBin,
    "#!/bin/sh\n" .
    "if [ \"\$1\" = \"fetch\" ]; then\n" .
    "  cat '" . $STUBS . "/sample.html'\n" .
    "  exit 0\n" .
    "fi\n" .
    "printf 'unknown command: %s\\n' \"\$1\" >&2\n" .
    "exit 1\n"
);
chmod($obscuraBin, 0755);

// Stub defuddle CLI: copy JS from stubs dir
$cliJs = $cliDir . '/cli.js';
copy($STUBS . '/defuddle-cli.js', $cliJs);

// Fake package.json so DefuddleManager thinks the pinned version is installed
file_put_contents(
    $tmpDir . '/node_modules/defuddle/package.json',
    json_encode(['name' => 'defuddle', 'version' => '0.0.0-stub'])
);

// DefuddleManager pinned to stub version so it skips npm install
$defuddleManager = new DefuddleManager($tmpDir, '0.0.0-stub', 168);
$binaryResolver  = new BinaryResolver($tmpDir, '');

$pipeline = new FullTextPipeline(
    $binaryResolver,
    $defuddleManager,
    'node',
    30,
    $obscuraBin   // bypass download; use stub directly
);

// ---------------------------------------------------------------------------
section('Prerequisites');
// ---------------------------------------------------------------------------
$nodeVer = ProcRunner::run(['node', '--version']);
ok('node is available', $nodeVer['exit_code'] === 0, trim($nodeVer['stdout']));
echo "    node: " . trim($nodeVer['stdout']) . "\n";

// ---------------------------------------------------------------------------
section('Stub: obscura');
// ---------------------------------------------------------------------------
$o = ProcRunner::run([$obscuraBin, 'fetch', 'https://example.com', '--dump', 'html']);
ok('stub obscura exits 0', $o['exit_code'] === 0);
ok('stub obscura returns DOCTYPE', str_contains($o['stdout'], '<!DOCTYPE html'));
ok('stub obscura includes article heading', str_contains($o['stdout'], 'Integration Test Article'));

// ---------------------------------------------------------------------------
section('Stub: defuddle CLI');
// ---------------------------------------------------------------------------
$d = ProcRunner::run(['node', $cliJs, 'parse', $STUBS . '/sample.html', '--markdown']);
ok('stub defuddle exits 0', $d['exit_code'] === 0);
ok('stub defuddle returns h1 markdown', str_contains($d['stdout'], '# Integration Test Article'));
ok('stub defuddle keeps first paragraph', str_contains($d['stdout'], 'first test paragraph'));
ok('stub defuddle converts bold', str_contains($d['stdout'], '**bold text**'));
ok('stub defuddle converts italic', str_contains($d['stdout'], '*italic text*'));

// ---------------------------------------------------------------------------
section('DefuddleManager: version pinning (no npm install)');
// ---------------------------------------------------------------------------
ok('installedVersion matches stub', $defuddleManager->installedVersion() === '0.0.0-stub');
ok('targetVersion returns pinned version', $defuddleManager->targetVersion() === '0.0.0-stub');
$cliPath = $defuddleManager->ensureInstalled();
ok('ensureInstalled returns cli.js path', str_ends_with($cliPath, 'cli.js'));
ok('cli.js file exists', file_exists($cliPath));

// ---------------------------------------------------------------------------
section('FullTextPipeline: end-to-end');
// ---------------------------------------------------------------------------
$html = $pipeline->run('https://example.com');

ok('pipeline returns non-empty string', $html !== '');
ok('output contains <h1>', preg_match('/<h1[^>]*>/', $html) === 1, substr($html, 0, 120));
ok('output contains article title', str_contains($html, 'Integration Test Article'));
ok('output contains <p>', preg_match('/<p[^>]*>/', $html) === 1);
ok('output contains first paragraph text', str_contains($html, 'first test paragraph'));
ok('output contains <strong> for bold', str_contains($html, '<strong>bold text</strong>'));
ok('output contains <em> for italic', str_contains($html, '<em>italic text</em>'));
ok('XSS safe-mode: no raw <script> in output',
    !str_contains($html, '<script'));

// ---------------------------------------------------------------------------
section('FullTextPipeline: edge cases');
// ---------------------------------------------------------------------------
ok('empty URL returns empty string', $pipeline->run('') === '');

// Markdown hook surface
$pipeline->onMarkdown(function (string $md): string {
    return $md . "\n\nHook injected paragraph.";
});
$htmlWithHook = $pipeline->run('https://example.com');
ok('markdown hook output is appended', str_contains($htmlWithHook, 'Hook injected paragraph'));

// Timeout: use a fake binary that sleeps longer than the timeout
$slowBin = $binDir . '/slow-obscura';
file_put_contents($slowBin, "#!/bin/sh\nsleep 10\n");
chmod($slowBin, 0755);
$slowPipeline = new FullTextPipeline($binaryResolver, $defuddleManager, 'node', 1, $slowBin);
try {
    $slowPipeline->run('https://example.com');
    ok('slow fetch raises RuntimeException', false);
} catch (RuntimeException $e) {
    ok('slow fetch raises RuntimeException', true, $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ([$slowBin, $obscuraBin] as $f) { @unlink($f); }
@unlink($cliJs);
@unlink($tmpDir . '/node_modules/defuddle/package.json');
@rmdir($cliDir);
@rmdir($tmpDir . '/node_modules/defuddle/dist');
@rmdir($tmpDir . '/node_modules/defuddle');
@rmdir($tmpDir . '/node_modules');
@rmdir($binDir);
@rmdir($tmpDir);

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
