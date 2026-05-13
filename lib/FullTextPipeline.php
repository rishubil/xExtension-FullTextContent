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
			[$obscuraBin, 'fetch', $url, '--dump', 'html'],
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
}
