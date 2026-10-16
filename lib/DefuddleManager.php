<?php

declare(strict_types=1);

/**
 * Manages the defuddle npm package installation under DATA_PATH/fulltextcontent.
 *
 * Version semantics:
 *  - "latest"      → always install the newest npm published version (checked on interval).
 *  - "<semver>"    → pin to that exact version; never auto-upgrade.
 */
final class DefuddleManager {
	private const DEFAULT_REGISTRY_URL = 'https://registry.npmjs.org/defuddle/latest';
	private const LOCK_FILE = 'defuddle-install.lock';
	private const STATE_FILE = 'version.json';
	private const CLI_ENTRYPOINT = 'node_modules/defuddle/dist/cli.js';

	private string $dataDir;
	private string $configuredVersion;
	private int $checkIntervalHours;
	private string $registryUrl;

	private static bool $checked = false;

	public function __construct(
		string $dataDir,
		string $configuredVersion = 'latest',
		int $checkIntervalHours = 168,
		string $registryUrl = self::DEFAULT_REGISTRY_URL
	) {
		$this->dataDir = rtrim($dataDir, '/');
		$configuredVersion = trim($configuredVersion);
		$this->configuredVersion = $configuredVersion !== '' ? $configuredVersion : 'latest';
		$this->checkIntervalHours = max(1, $checkIntervalHours);
		$this->registryUrl = $registryUrl;
	}

	/**
	 * Returns the path to the defuddle CLI JS file.
	 * Installs / updates defuddle if needed (lazy, once per PHP process).
	 *
	 * @throws RuntimeException on install failure
	 */
	public function ensureInstalled(bool $force = false): string {
		if (!$force && self::$checked) {
			return $this->cliPath();
		}

		self::$checked = true;

		$installed = $this->installedVersion();
		$target = $this->targetVersion();

		if (!$force && $installed !== null && $installed === $target) {
			return $this->cliPath();
		}

		$this->install($target);
		return $this->cliPath();
	}

	public function cliPath(): string {
		return $this->dataDir . '/' . self::CLI_ENTRYPOINT;
	}

	public function installedVersion(): ?string {
		$pkgJson = $this->dataDir . '/node_modules/defuddle/package.json';
		if (!is_file($pkgJson)) {
			return null;
		}
		$data = json_decode((string) file_get_contents($pkgJson), true);
		return is_array($data) ? ($data['version'] ?? null) : null;
	}

	/** The configured version spec; an empty setting means "latest". */
	public function configuredVersion(): string {
		return $this->configuredVersion;
	}

	public function latestKnownVersion(): ?string {
		$state = $this->readState();
		return $state['latest_known_version'] ?? null;
	}

	public function lastCheckTimestamp(): ?int {
		$state = $this->readState();
		return isset($state['last_check_ts']) ? (int) $state['last_check_ts'] : null;
	}

	public function targetVersion(): string {
		if ($this->configuredVersion !== 'latest') {
			return $this->configuredVersion;
		}

		if (!$this->shouldCheck()) {
			$known = $this->latestKnownVersion();
			if ($known !== null) {
				return $known;
			}
		}

		$latest = $this->fetchLatestVersion();
		$this->writeState([
			'latest_known_version' => $latest,
			'last_check_ts' => time(),
			'installed_version' => $this->installedVersion(),
		]);
		return $latest;
	}

	private function shouldCheck(): bool {
		$ts = $this->lastCheckTimestamp();
		if ($ts === null) {
			return true;
		}
		return (time() - $ts) > ($this->checkIntervalHours * 3600);
	}

	private function fetchLatestVersion(): string {
		try {
			$body = HttpClient::get($this->registryUrl);
		} catch (RuntimeException $e) {
			throw new RuntimeException('Failed to query npm registry for defuddle version: ' . $e->getMessage(), 0, $e);
		}
		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['version'])) {
			throw new RuntimeException('Unexpected response from npm registry.');
		}
		return (string) $data['version'];
	}

	private function install(string $version): void {
		if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0755, true)) {
			throw new RuntimeException('Cannot create data directory: ' . $this->dataDir);
		}

		$lockFile = $this->dataDir . '/' . self::LOCK_FILE;
		$fh = fopen($lockFile, 'c');
		if (!$fh) {
			throw new RuntimeException('Cannot open defuddle lock file.');
		}

		if (!flock($fh, LOCK_EX | LOCK_NB)) {
			fclose($fh);
			// Another process is installing; wait for it and then reuse.
			$fh2 = fopen($lockFile, 'c');
			if ($fh2) {
				flock($fh2, LOCK_EX);
				flock($fh2, LOCK_UN);
				fclose($fh2);
			}
			return;
		}

		try {
			$spec = 'defuddle@' . $version;
			$result = ProcRunner::run(
				['npm', 'install', $spec, '--prefix', $this->dataDir, '--no-audit', '--no-fund', '--cache', $this->dataDir . '/.npm-cache'],
				'',
				120
			);
			if ($result['exit_code'] !== 0) {
				throw new RuntimeException('npm install failed: ' . self::summarizeNpmError($result['stderr']));
			}

			$this->writeState([
				'latest_known_version' => $this->latestKnownVersion(),
				'last_check_ts' => $this->lastCheckTimestamp(),
				'installed_version' => $this->installedVersion(),
			]);
		} finally {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	/**
	 * Condenses npm's stderr to its cause: strips the "npm ERR!" (npm <= 9) /
	 * "npm error" (npm >= 10) prefixes and the pointer to the debug log.
	 */
	public static function summarizeNpmError(string $stderr): string {
		$lines = [];
		foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
			$line = trim((string) preg_replace('/^npm (ERR!|error)\s*/', '', trim($line)));
			if ($line === '' || str_contains($line, 'complete log of this run') || str_contains($line, '/_logs/')) {
				continue;
			}
			$lines[] = $line;
		}
		$summary = implode(' ', $lines);
		if ($summary === '') {
			return 'unknown npm error';
		}
		return strlen($summary) > 400 ? substr($summary, 0, 397) . '...' : $summary;
	}

	/** @return array<string,mixed> */
	private function readState(): array {
		$path = $this->dataDir . '/' . self::STATE_FILE;
		if (!is_file($path)) {
			return [];
		}
		$data = json_decode((string) file_get_contents($path), true);
		return is_array($data) ? $data : [];
	}

	/** @param array<string,mixed> $state */
	private function writeState(array $state): void {
		if (!is_dir($this->dataDir)) {
			mkdir($this->dataDir, 0755, true);
		}
		$path = $this->dataDir . '/' . self::STATE_FILE;
		file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
	}
}
