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

	/** @var array<int,array{host:string,wait?:int,wait_until?:string}> */
	private array $fetchRules = [];

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
	 * @param array<int,array{host:string,wait?:int,wait_until?:string}> $rules
	 */
	public function setFetchRules(array $rules): void {
		$this->fetchRules = $rules;
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
	 * @throws RuntimeException on unrecoverable errors
	 */
	public function run(string $url): string {
		if ($url === '') {
			return '';
		}

		$obscuraBin = $this->obscuraBinaryOverride !== ''
			? $this->obscuraBinaryOverride
			: $this->binaryResolver->ensure();

		$cliPath = $this->defuddleManager->ensureInstalled();

		// Stage 1: fetch HTML with obscura
		$fetchResult = ProcRunner::run(
			array_merge([$obscuraBin, 'fetch', $url, '--dump', 'html'], $this->resolveWaitArgs($url)),
			'',
			$this->fetchTimeoutSec
		);
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

		// Stage 3: Markdown → HTML
		$parsedown = new Parsedown();
		$parsedown->setSafeMode(true);
		return $parsedown->text($markdown);
	}

	/**
	 * Returns extra CLI args for obscura fetch based on the first matching rule.
	 *
	 * @return string[]
	 */
	private function resolveWaitArgs(string $url): array {
		$host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
		foreach ($this->fetchRules as $rule) {
			$pattern = trim((string) ($rule['host'] ?? ''));
			if ($pattern === '' || !$this->hostMatches($host, $pattern)) {
				continue;
			}
			$args = [];
			if (isset($rule['wait']) && (int) $rule['wait'] > 0) {
				$args[] = '--wait';
				$args[] = (string) (int) $rule['wait'];
			}
			$waitUntil = trim((string) ($rule['wait_until'] ?? ''));
			if ($waitUntil !== '') {
				$args[] = '--wait-until';
				$args[] = $waitUntil;
			}
			return $args;
		}
		return [];
	}

	private function hostMatches(string $host, string $pattern): bool {
		if (str_starts_with($pattern, '*.')) {
			$suffix = substr($pattern, 2);
			return $host === $suffix || str_ends_with($host, '.' . $suffix);
		}
		return $host === $pattern;
	}
}
