<?php

declare(strict_types=1);

/**
 * Minimum supported versions of the external tools.
 *
 * They are the versions the integration and end-to-end suites pin and verify
 * (scripts/test.php fails if the two drift apart). Older versions are not
 * tested, so the configure page warns when one is installed.
 */
final class VersionRequirements {
	public const OBSCURA_MIN = '0.2.4';
	public const DEFUDDLE_MIN = '0.19.5';

	/** Extracts the first MAJOR.MINOR.PATCH from e.g. "obscura 0.2.4" or "0.1.0-dev+abc". */
	public static function parse(?string $raw): ?string {
		if ($raw === null || preg_match('/(\d+\.\d+\.\d+)/', $raw, $m) !== 1) {
			return null;
		}
		return $m[1];
	}

	/** True when $version is known and at least $minimum. */
	public static function meets(?string $version, string $minimum): bool {
		$parsed = self::parse($version);
		return $parsed !== null && version_compare($parsed, $minimum, '>=');
	}
}
