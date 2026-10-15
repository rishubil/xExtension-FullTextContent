<?php

declare(strict_types=1);

/**
 * Downloads and caches the obscura binary on first use.
 *
 * The download URL is a template string that may contain {arch}, which is
 * substituted with the detected host architecture (e.g. x86_64-linux).
 */
final class BinaryResolver {
	private const DEFAULT_DOWNLOAD_URL = 'https://github.com/h4ckf0r0day/obscura/releases/latest/download/obscura-{arch}.tar.gz';

	private const ARCH_MAP = [
		'x86_64' => 'x86_64-linux',
		'aarch64' => 'aarch64-linux',
		'arm64' => 'aarch64-linux',
	];

	private string $binDir;
	private string $downloadUrl;
	private string $binaryPath;

	public function __construct(string $dataDir, string $downloadUrl = '') {
		$this->binDir = rtrim($dataDir, '/') . '/bin';
		$this->downloadUrl = $downloadUrl !== '' ? $downloadUrl : self::DEFAULT_DOWNLOAD_URL;
		$this->binaryPath = $this->binDir . '/obscura';
	}

	/**
	 * Returns the path to a ready-to-execute obscura binary.
	 * Downloads and extracts if missing or $force is true.
	 *
	 * @throws RuntimeException on download or extraction failure
	 */
	public function ensure(bool $force = false): string {
		if (!$force && is_executable($this->binaryPath)) {
			return $this->binaryPath;
		}

		$arch = $this->detectArch();
		$url = str_replace('{arch}', $arch, $this->downloadUrl);

		if (!is_dir($this->binDir) && !mkdir($this->binDir, 0755, true)) {
			throw new RuntimeException('Cannot create bin directory: ' . $this->binDir);
		}

		$tmpArchive = $this->binDir . '/obscura-download.tmp.tar.gz';
		$this->download($url, $tmpArchive);

		try {
			$this->extract($tmpArchive, $this->binDir);
		} finally {
			@unlink($tmpArchive);
		}

		if (!file_exists($this->binaryPath)) {
			throw new RuntimeException('Obscura binary not found after extraction. Check that the archive contains a file named "obscura".');
		}

		chmod($this->binaryPath, 0755);
		return $this->binaryPath;
	}

	public function binaryPath(): string {
		return $this->binaryPath;
	}

	public function isDownloaded(): bool {
		return is_executable($this->binaryPath);
	}

	public function installedVersion(): ?string {
		return self::probeVersion($this->binaryPath);
	}

	/** Raw `--version` output of an obscura binary (e.g. "obscura 0.2.4"), or null. */
	public static function probeVersion(string $binary): ?string {
		if ($binary === '' || !is_executable($binary)) {
			return null;
		}
		try {
			$result = ProcRunner::run([$binary, '--version'], '', 5);
			if ($result['exit_code'] === 0) {
				return trim($result['stdout']) ?: null;
			}
		} catch (Throwable) {
			// ignore
		}
		return null;
	}

	public function defaultDownloadUrl(): string {
		return self::DEFAULT_DOWNLOAD_URL;
	}

	private function detectArch(): string {
		$raw = php_uname('m');
		$arch = self::ARCH_MAP[$raw] ?? null;
		if ($arch === null) {
			throw new RuntimeException(sprintf('Unsupported host architecture "%s". Download obscura manually and set the binary path override.', $raw));
		}
		return $arch;
	}

	private function download(string $url, string $destination): void {
		try {
			HttpClient::download($url, $destination);
		} catch (RuntimeException $e) {
			throw new RuntimeException('Failed to download obscura from ' . $url . ': ' . $e->getMessage(), 0, $e);
		}
		if ((int) @filesize($destination) < 100) {
			@unlink($destination);
			throw new RuntimeException('Failed to download obscura from ' . $url . ': response too small');
		}
	}

	private function extract(string $archive, string $targetDir): void {
		$result = ProcRunner::run(['tar', 'xzf', $archive, '-C', $targetDir], '', 30);
		if ($result['exit_code'] !== 0) {
			throw new RuntimeException('Failed to extract obscura archive: ' . trim($result['stderr']));
		}
	}
}
