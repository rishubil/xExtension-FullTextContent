<?php

declare(strict_types=1);

// Standalone test script — no FreshRSS bootstrap needed.
// Run: php scripts/test.php
// Or use the wrapper (falls back to Docker when host php is absent): bash scripts/test.sh

require_once __DIR__ . '/../lib/ProcRunner.php';
require_once __DIR__ . '/../lib/BinaryResolver.php';
require_once __DIR__ . '/../lib/DefuddleManager.php';
require_once __DIR__ . '/../lib/FullTextPipeline.php';
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
// ProcRunner — extended
// ---------------------------------------------------------------------------
section('ProcRunner (extended)');

$prBoth = ProcRunner::run(['sh', '-c', 'echo out; echo err 1>&2']);
ok('stdout and stderr captured separately',
    trim($prBoth['stdout']) === 'out' && trim($prBoth['stderr']) === 'err');

// Large output forces the non-blocking drain loop (exceeds a 64K pipe buffer).
$prBig = ProcRunner::run(['sh', '-c', 'i=0; while [ $i -lt 20000 ]; do echo LINE$i; i=$((i+1)); done']);
ok('large stdout fully captured across reads', substr_count($prBig['stdout'], 'LINE') === 20000);

// Array argv is passed to execvp directly — no shell word-splitting.
$prLiteral = ProcRunner::run(['printf', '%s', 'a b c']);
ok('args passed literally without shell splitting', $prLiteral['stdout'] === 'a b c');

$prExit = ProcRunner::run(['sh', '-c', 'exit 3']);
ok('specific non-zero exit code propagated', $prExit['exit_code'] === 3);

// ---------------------------------------------------------------------------
// BinaryResolver — download & extract (offline via file:// URLs)
// ---------------------------------------------------------------------------
section('BinaryResolver (download/extract)');

$archMap = ['x86_64' => 'x86_64-linux', 'aarch64' => 'aarch64-linux', 'arm64' => 'aarch64-linux'];
$hostArch = $archMap[php_uname('m')] ?? null;

if ($hostArch === null) {
    ok('SKIP download/extract tests (unsupported host arch)', true);
} else {
    $dlBase = sys_get_temp_dir() . '/ftc_dl_' . getmypid();
    @mkdir($dlBase . '/src', 0755, true);
    // A >100 byte payload so BinaryResolver::download does not reject it as too small.
    file_put_contents($dlBase . '/src/obscura', "#!/bin/sh\necho stub-obscura " . str_repeat('x', 300) . "\n");
    ProcRunner::run(['tar', 'czf', $dlBase . '/obscura-' . $hostArch . '.tar.gz', '-C', $dlBase . '/src', 'obscura']);

    // Success: {arch} substitution -> download -> extract -> chmod.
    $okResolver = new BinaryResolver($dlBase . '/data', 'file://' . $dlBase . '/obscura-{arch}.tar.gz');
    $okPath = $okResolver->ensure();
    ok('ensure() downloads and extracts obscura', is_executable($okPath));
    ok('ensure() reports downloaded afterwards', $okResolver->isDownloaded());

    // Archive missing an "obscura" entry -> post-extraction existence check fails.
    @mkdir($dlBase . '/src2', 0755, true);
    file_put_contents($dlBase . '/src2/notobscura', str_repeat('y', 300));
    ProcRunner::run(['tar', 'czf', $dlBase . '/obscura-' . $hostArch . '.tar.gz', '-C', $dlBase . '/src2', 'notobscura']);
    $missResolver = new BinaryResolver($dlBase . '/data2', 'file://' . $dlBase . '/obscura-{arch}.tar.gz');
    try {
        $missResolver->ensure();
        ok('missing binary in archive raises RuntimeException', false);
    } catch (RuntimeException $e) {
        ok('missing binary in archive raises RuntimeException',
            str_contains($e->getMessage(), 'not found after extraction'));
    }

    // Unreachable URL -> download failure.
    $failResolver = new BinaryResolver($dlBase . '/data3', 'file://' . $dlBase . '/does-not-exist-{arch}.tar.gz');
    try {
        @$failResolver->ensure();
        ok('download failure raises RuntimeException', false);
    } catch (RuntimeException $e) {
        ok('download failure raises RuntimeException',
            str_contains($e->getMessage(), 'Failed to download'));
    }

    ProcRunner::run(['rm', '-rf', $dlBase]);
}

// ---------------------------------------------------------------------------
// DefuddleManager — extended
// ---------------------------------------------------------------------------
section('DefuddleManager (extended)');

$dmDir2 = sys_get_temp_dir() . '/ftc_dm2_' . getmypid();
@mkdir($dmDir2 . '/node_modules/defuddle', 0755, true);

file_put_contents($dmDir2 . '/node_modules/defuddle/package.json', json_encode(['version' => '2.5.0']));
ok('installedVersion reads package.json version',
    (new DefuddleManager($dmDir2, '2.5.0', 168))->installedVersion() === '2.5.0');

file_put_contents($dmDir2 . '/node_modules/defuddle/package.json', 'not-json');
ok('installedVersion null on malformed package.json',
    (new DefuddleManager($dmDir2, '2.5.0', 168))->installedVersion() === null);

file_put_contents($dmDir2 . '/version.json', 'garbage{');
$dmBadState = new DefuddleManager($dmDir2, 'latest', 168);
ok('latestKnownVersion null on malformed state file', $dmBadState->latestKnownVersion() === null);
ok('lastCheckTimestamp null on malformed state file', $dmBadState->lastCheckTimestamp() === null);

// Empty configured version defaults to "latest"; a fresh cached state avoids any network call.
file_put_contents($dmDir2 . '/version.json',
    json_encode(['latest_known_version' => '3.1.4', 'last_check_ts' => time(), 'installed_version' => null]));
ok('empty version defaults to latest and uses cached version',
    (new DefuddleManager($dmDir2, '', 168))->targetVersion() === '3.1.4');

// ensureInstalled short-circuits (no npm) when installed version already equals target.
file_put_contents($dmDir2 . '/node_modules/defuddle/package.json', json_encode(['version' => '2.5.0']));
$checkedProp = new ReflectionProperty(DefuddleManager::class, 'checked');
$checkedProp->setAccessible(true);
$checkedProp->setValue(null, false);
$dmMatch = new DefuddleManager($dmDir2, '2.5.0', 168);
ok('ensureInstalled short-circuits on version match',
    $dmMatch->ensureInstalled() === $dmDir2 . '/node_modules/defuddle/dist/cli.js');

ProcRunner::run(['rm', '-rf', $dmDir2]);

// ---------------------------------------------------------------------------
// FullTextPipeline — command construction, URL resolution, hooks (stub binaries)
// ---------------------------------------------------------------------------
section('FullTextPipeline (stubbed binaries)');

$stubDir = sys_get_temp_dir() . '/ftc_pipe_' . getmypid();
@mkdir($stubDir . '/dm/node_modules/defuddle', 0755, true);

// Stub obscura: logs its argv to $FTC_ARGS_LOG and emits non-empty HTML.
$obscuraStub = $stubDir . '/obscura';
file_put_contents($obscuraStub,
    "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$FTC_ARGS_LOG\"\nprintf '<html><body>stub</body></html>\\n'\n");
chmod($obscuraStub, 0755);

// Stub "node": ignores the defuddle CLI path and emits fixed Markdown with mixed links.
$nodeStub = $stubDir . '/node';
file_put_contents($nodeStub,
    "#!/bin/sh\nprintf '%s\\n' 'Text [rel](/root) and [ext](https://other.com/a) and [rel2](sub/page).'\n");
chmod($nodeStub, 0755);

// DefuddleManager that short-circuits (installed == pinned) so no npm runs.
file_put_contents($stubDir . '/dm/node_modules/defuddle/package.json', json_encode(['version' => '9.9.9']));
$stubDm = new DefuddleManager($stubDir . '/dm', '9.9.9', 168);

$pipeline = new FullTextPipeline(
    new BinaryResolver($stubDir . '/unused', ''),
    $stubDm,
    $nodeStub,       // nodeBinary
    30,
    $obscuraStub     // obscura override — BinaryResolver is bypassed
);

$argsLog = $stubDir . '/obscura_args.log';
putenv('FTC_ARGS_LOG=' . $argsLog);
$baseUrl = 'https://example.com/blog/post.html';

// Default run: no optional flags.
@unlink($argsLog);
$pipeHtml = $pipeline->run($baseUrl);
ok('pipeline returns non-empty HTML', $pipeHtml !== '');
ok('root-relative link resolved to absolute', str_contains($pipeHtml, 'https://example.com/root'));
ok('path-relative link resolved against base dir', str_contains($pipeHtml, 'https://example.com/blog/sub/page'));
ok('already-absolute link left unchanged', str_contains($pipeHtml, 'https://other.com/a'));

$log1 = (string) @file_get_contents($argsLog);
ok('obscura called with fetch/--dump html/--quiet',
    str_contains($log1, 'fetch') && str_contains($log1, '--dump html') && str_contains($log1, '--quiet'));
ok('no --wait flag by default', !str_contains($log1, '--wait'));
ok('no --stealth flag by default', !str_contains($log1, '--stealth'));
ok('no --selector flag by default', !str_contains($log1, '--selector'));

// Run with every optional flag set.
@unlink($argsLog);
$pipeline->run($baseUrl, 3, 'networkidle0', '#main', true, 15);
$log2 = (string) @file_get_contents($argsLog);
ok('--wait passed through', str_contains($log2, '--wait 3'));
ok('--wait-until passed through', str_contains($log2, '--wait-until networkidle0'));
ok('--selector passed through', str_contains($log2, '--selector #main'));
ok('--stealth passed through', str_contains($log2, '--stealth'));
ok('--timeout passed through', str_contains($log2, '--timeout 15'));

// Empty URL short-circuits before invoking obscura.
ok('empty URL returns empty string', $pipeline->run('') === '');

// Markdown hook runs between defuddle and Parsedown.
$pipeline->onMarkdown(static fn (string $md): string => $md . "\n\nHOOK_MARKER.");
$hookHtml = $pipeline->run($baseUrl);
ok('markdown hook output reaches final HTML', str_contains($hookHtml, 'HOOK_MARKER'));

// ---------------------------------------------------------------------------
// FullTextPipeline::resolveRelativeUrlsInMarkdown — direct (reflection)
// ---------------------------------------------------------------------------
section('FullTextPipeline URL resolution (direct)');

$resolveMethod = new ReflectionMethod(FullTextPipeline::class, 'resolveRelativeUrlsInMarkdown');
$resolveMethod->setAccessible(true);
$resolve = static fn (string $md, string $base = 'https://example.com/blog/post.html'): string =>
    $resolveMethod->invoke($pipeline, $md, $base);

ok('root-relative -> origin-absolute',
    str_contains($resolve('[a](/root)'), '[a](https://example.com/root)'));
ok('path-relative -> base-dir-absolute',
    str_contains($resolve('[a](sub/page)'), '[a](https://example.com/blog/sub/page)'));
ok('parent traversal normalised',
    str_contains($resolve('![i](../img.png)'), '![i](https://example.com/img.png)'));
ok('protocol-relative gets scheme',
    str_contains($resolve('[a](//cdn.example.com/y)'), '[a](https://cdn.example.com/y)'));
ok('absolute URL unchanged',
    str_contains($resolve('[a](https://other.com/z)'), '[a](https://other.com/z)'));
ok('fragment-only unchanged',
    str_contains($resolve('[a](#sec)'), '[a](#sec)'));
ok('mailto scheme unchanged',
    str_contains($resolve('[a](mailto:x@y.com)'), 'mailto:x@y.com'));
ok('data URI unchanged',
    str_contains($resolve('![i](data:image/png;base64,AAAA)'), 'data:image/png;base64,AAAA'));
ok('reference-style definition resolved',
    str_contains($resolve('[ref]: /path/to'), '[ref]: https://example.com/path/to'));
ok('link title preserved during resolution',
    str_contains($resolve('[a](/x "Title")'), '[a](https://example.com/x "Title")'));
ok('empty base URL leaves markdown unchanged',
    $resolve('[a](/root)', '') === '[a](/root)');
ok('host-less base URL leaves markdown unchanged',
    $resolve('[a](/root)', 'relative/only') === '[a](/root)');
ok('empty markdown returns empty',
    $resolve('', 'https://example.com/') === '');

ProcRunner::run(['rm', '-rf', $stubDir]);

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
