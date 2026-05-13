<?php

declare(strict_types=1);

/**
 * Orchestrates full-text extraction for a single URL.
 *
 * Stage 1: obscura fetch <url> --dump html  → raw HTML (stdout)
 * Stage 2: defuddle parse <tmp.html> --markdown → Markdown (stdout)
 * Stage 3: Parsedown::text($markdown) → clean HTML
 *
 * A markdown hook surface is provided between stages 2 and 3 so future
 * transform steps (e.g. translation) can plug in without restructuring.
 */
final class FullTextPipeline {
	private BinaryResolver $binaryResolver;
	private DefuddleManager $defuddleManager;
	private string $nodeBinary;
	private int $fetchTimeoutSec;
	private string $obscuraBinaryOverride;

	/** @var callable[] */
	private array $markdownHooks = [];

	public function __construct(
		BinaryResolver $binaryResolver,
		DefuddleManager $defuddleManager,
		string $nodeBinary = 'node',
		int $fetchTimeoutSec = 30,
		string $obscuraBinaryOverride = ''
	) {
		$this->binaryResolver = $binaryResolver;
		$this->defuddleManager = $defuddleManager;
		$this->nodeBinary = $nodeBinary !== '' ? $nodeBinary : 'node';
		$this->fetchTimeoutSec = max(5, $fetchTimeoutSec);
		$this->obscuraBinaryOverride = $obscuraBinaryOverride;
	}

	/**
	 * Registers a callable invoked with the extracted Markdown string.
	 * The callable receives string $markdown and must return string.
	 */
	public function onMarkdown(callable $hook): void {
		$this->markdownHooks[] = $hook;
	}

	/**
	 * Runs the full pipeline for $url and returns clean HTML, or '' on failure.
	 *
	 * @param int    $wait      Extra seconds to wait after page load (0 = obscura default).
	 * @param string $waitUntil Navigation event to wait for ('' = obscura default "load").
	 *                          Valid values: load, domcontentloaded, networkidle0
	 * @param string $selector  CSS selector to wait for before capturing ('' = disabled).
	 * @param bool   $stealth   Enable obscura stealth / anti-detection mode.
	 * @param int    $timeout   Navigation timeout in seconds (0 = obscura default 30s).
	 * @throws RuntimeException on unrecoverable errors
	 */
	public function run(
		string $url,
		int $wait = 0,
		string $waitUntil = '',
		string $selector = '',
		bool $stealth = false,
		int $timeout = 0
	): string {
		if ($url === '') {
			return '';
		}

		$obscuraBin = $this->obscuraBinaryOverride !== ''
			? $this->obscuraBinaryOverride
			: $this->binaryResolver->ensure();

		$cliPath = $this->defuddleManager->ensureInstalled();

		// Stage 1: fetch HTML with obscura
		$fetchCmd = [$obscuraBin, 'fetch', $url, '--dump', 'html', '--quiet'];
		if ($wait > 0) {
			$fetchCmd[] = '--wait';
			$fetchCmd[] = (string) $wait;
		}
		if ($waitUntil !== '') {
			$fetchCmd[] = '--wait-until';
			$fetchCmd[] = $waitUntil;
		}
		if ($selector !== '') {
			$fetchCmd[] = '--selector';
			$fetchCmd[] = $selector;
		}
		if ($stealth) {
			$fetchCmd[] = '--stealth';
		}
		if ($timeout > 0) {
			$fetchCmd[] = '--timeout';
			$fetchCmd[] = (string) $timeout;
		}

		$fetchResult = ProcRunner::run($fetchCmd, '', $this->fetchTimeoutSec);
		if ($fetchResult['exit_code'] !== 0 || trim($fetchResult['stdout']) === '') {
			throw new RuntimeException('obscura fetch failed for ' . $url . ': ' . trim($fetchResult['stderr']));
		}

		$html = $fetchResult['stdout'];

		// Stage 2: write HTML to temp file and run defuddle
		$tmpFile = tempnam(sys_get_temp_dir(), 'ftc_') . '.html';
		try {
			file_put_contents($tmpFile, $html);

			$defuddleResult = ProcRunner::run(
				[$this->nodeBinary, $cliPath, 'parse', $tmpFile, '--markdown'],
				'',
				30
			);
			if ($defuddleResult['exit_code'] !== 0 || trim($defuddleResult['stdout']) === '') {
				throw new RuntimeException('defuddle parse failed: ' . trim($defuddleResult['stderr']));
			}

			$markdown = $defuddleResult['stdout'];
		} finally {
			@unlink($tmpFile);
		}

		// Markdown hook surface (no-op by default)
		foreach ($this->markdownHooks as $hook) {
			$markdown = $hook($markdown);
		}

		// Stage 2.5: resolve relative URLs in Markdown before rendering
		$markdown = $this->resolveRelativeUrlsInMarkdown($markdown, $url);

		// Stage 3: Markdown → HTML
		$parsedown = new Parsedown();
		$parsedown->setSafeMode(true);
		return $parsedown->text($markdown);
	}

	/**
	 * Rewrites relative URLs in defuddle-generated Markdown to absolute URLs
	 * using $baseUrl as the reference. Handles:
	 *   - inline links/images:      [text](url)  ![alt](url)
	 *   - reference definitions:    [ref]: url
	 */
	private function resolveRelativeUrlsInMarkdown(string $markdown, string $baseUrl): string {
		if ($markdown === '' || $baseUrl === '') {
			return $markdown;
		}

		$parsed = parse_url($baseUrl);
		if ($parsed === false || empty($parsed['host'])) {
			return $markdown;
		}

		$scheme   = $parsed['scheme'] ?? 'https';
		$host     = $parsed['host'];
		$port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
		$origin   = $scheme . '://' . $host . $port;
		$path     = $parsed['path'] ?? '/';
		$basePath = substr($path, 0, (int) strrpos($path, '/') + 1);

		$resolve = static function (string $rel) use ($origin, $basePath, $scheme): string {
			if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $rel)) {
				return $rel; // already has a scheme (http:, mailto:, data:, …)
			}
			if (str_starts_with($rel, '#')) {
				return $rel; // fragment-only
			}
			if (str_starts_with($rel, '//')) {
				return $scheme . ':' . $rel; // protocol-relative
			}
			if (str_starts_with($rel, '/')) {
				return $origin . $rel; // root-relative
			}
			// path-relative: resolve against base directory, then normalise
			$abs   = $basePath . $rel;
			$parts = [];
			foreach (explode('/', $abs) as $seg) {
				if ($seg === '..') {
					array_pop($parts);
				} elseif ($seg !== '.') {
					$parts[] = $seg;
				}
			}
			return $origin . '/' . ltrim(implode('/', $parts), '/');
		};

		// Inline links and images: [text](url "optional title") or ![alt](url)
		$markdown = preg_replace_callback(
			'/(!?\[[^\]]*\])\((\S+?)((?:\s+"[^"]*")?\s*)\)/',
			static function (array $m) use ($resolve): string {
				return $m[1] . '(' . $resolve($m[2]) . $m[3] . ')';
			},
			$markdown
		) ?? $markdown;

		// Reference-style link definitions: [ref]: url optional-title
		$markdown = preg_replace_callback(
			'/^(\s*\[[^\]]+\]:\s*)(\S+)/m',
			static function (array $m) use ($resolve): string {
				return $m[1] . $resolve($m[2]);
			},
			$markdown
		) ?? $markdown;

		return $markdown;
	}
}
