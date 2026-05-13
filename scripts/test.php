<?php

declare(strict_types=1);

// Standalone test script — no FreshRSS bootstrap needed.
// Run: php scripts/test.php

require_once __DIR__ . '/../lib/ProcRunner.php';
require_once __DIR__ . '/../lib/BinaryResolver.php';
require_once __DIR__ . '/../lib/DefuddleManager.php';
require_once __DIR__ . '/../lib/Parsedown.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] $label\n";
        $passed++;
    } else {
        echo "  [FAIL] $label\n";
        $failed++;
    }
}

function section(string $name): void {
    echo "\n=== $name ===\n";
}

// ---------------------------------------------------------------------------
// ProcRunner
// ---------------------------------------------------------------------------
section('ProcRunner');

$result = ProcRunner::run(['echo', 'hello']);
ok('exit code 0 for echo', $result['exit_code'] === 0);
ok('stdout captured', trim($result['stdout']) === 'hello');
ok('stderr empty', $result['stderr'] === '');

$result2 = ProcRunner::run(['cat'], 'piped-stdin');
ok('stdin passed through cat', trim($result2['stdout']) === 'piped-stdin');

$result3 = ProcRunner::run(['false']);
ok('non-zero exit code propagated', $result3['exit_code'] !== 0);

try {
    ProcRunner::run(['sleep', '10'], '', 1);
    ok('timeout raises RuntimeException', false);
} catch (RuntimeException $e) {
    ok('timeout raises RuntimeException', str_contains($e->getMessage(), 'timed out'));
}

// ---------------------------------------------------------------------------
// BinaryResolver
// ---------------------------------------------------------------------------
section('BinaryResolver');

$tmpDir = sys_get_temp_dir() . '/ftc_test_' . getmypid();
@mkdir($tmpDir . '/bin', 0755, true);

$resolver = new BinaryResolver($tmpDir, '');
ok('binaryPath returns expected path', str_ends_with($resolver->binaryPath(), '/bin/obscura'));
ok('isDownloaded false when binary absent', !$resolver->isDownloaded());
ok('defaultDownloadUrl contains {arch}', str_contains($resolver->defaultDownloadUrl(), '{arch}'));

// Simulate a pre-existing executable binary
$fakeBin = $tmpDir . '/bin/obscura';
file_put_contents($fakeBin, '#!/bin/sh' . PHP_EOL . 'echo ok');
chmod($fakeBin, 0755);
ok('isDownloaded true when executable exists', $resolver->isDownloaded());

// ensure() returns path without re-downloading when already executable
$path = $resolver->ensure(false);
ok('ensure() returns binary path when already present', $path === $fakeBin);

// Cleanup
@unlink($fakeBin);
@rmdir($tmpDir . '/bin');

// ---------------------------------------------------------------------------
// DefuddleManager
// ---------------------------------------------------------------------------
section('DefuddleManager');

$dmDir = sys_get_temp_dir() . '/ftc_dm_' . getmypid();
@mkdir($dmDir, 0755, true);

$dm = new DefuddleManager($dmDir, '1.2.3', 168);
ok('installedVersion null when not installed', $dm->installedVersion() === null);
ok('latestKnownVersion null with no state file', $dm->latestKnownVersion() === null);
ok('lastCheckTimestamp null with no state file', $dm->lastCheckTimestamp() === null);
ok('cliPath ends with cli.js', str_ends_with($dm->cliPath(), 'cli.js'));

// targetVersion returns pinned version without network call
$target = $dm->targetVersion();
ok('targetVersion returns pinned semver', $target === '1.2.3');

// Simulate a state file for "latest" mode
$stateData = ['latest_known_version' => '0.9.9', 'last_check_ts' => time(), 'installed_version' => null];
file_put_contents($dmDir . '/version.json', json_encode($stateData));

$dmLatest = new DefuddleManager($dmDir, 'latest', 168);
ok('latestKnownVersion read from state file', $dmLatest->latestKnownVersion() === '0.9.9');
ok('targetVersion uses cached version within interval', $dmLatest->targetVersion() === '0.9.9');

// Cleanup
@unlink($dmDir . '/version.json');
@rmdir($dmDir);

// ---------------------------------------------------------------------------
// Parsedown
// ---------------------------------------------------------------------------
section('Parsedown');

$pd = new Parsedown();
$pd->setSafeMode(true);

$html = $pd->text('# Hello');
ok('h1 renders correctly', str_contains($html, '<h1>Hello</h1>'));

$html2 = $pd->text('**bold** and _italic_');
ok('bold renders correctly', str_contains($html2, '<strong>bold</strong>'));
ok('italic renders correctly', str_contains($html2, '<em>italic</em>'));

// Safe mode: script tags should be escaped / stripped
$html3 = $pd->text('<script>alert(1)</script>');
ok('safe mode strips script tag', !str_contains($html3, '<script>'));

$html4 = $pd->text("[link](javascript:alert(1))");
ok('safe mode neutralises javascript: href', !str_contains($html4, 'javascript:'));

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- Results: $passed/$total passed";
if ($failed > 0) {
    echo ", $failed FAILED";
}
echo " ---\n";
exit($failed > 0 ? 1 : 0);
