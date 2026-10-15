<?php

declare(strict_types=1);

/**
 * Minimal cURL-based HTTP client.
 *
 * FreshRSS >= 1.30 unregisters the http/https stream wrappers for web
 * requests (lib_rss.php: unregister_unsafe_protocols()), so
 * file_get_contents('https://…') cannot be used inside the extension.
 * Downloads are streamed to disk so large archives (the obscura tarball is
 * ~70 MB) do not have to fit within PHP's memory_limit.
 */
final class HttpClient {
	private const USER_AGENT = 'xExtension-FullTextContent';
	private const ALLOWED_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS | CURLPROTO_FILE;

	/**
	 * Streams $url into $destination.
	 *
	 * @throws RuntimeException on transport errors or non-2xx HTTP status;
	 *         the message is the reason only, callers add context
	 */
	public static function download(string $url, string $destination, int $timeoutSec = 300): void {
		$fh = fopen($destination, 'wb');
		if ($fh === false) {
			throw new RuntimeException('Cannot open for writing: ' . $destination);
		}

		$ch = self::init($url, $timeoutSec);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		try {
			self::exec($ch, $url);
		} catch (RuntimeException $e) {
			fclose($fh);
			@unlink($destination);
			throw $e;
		}
		fclose($fh);
	}

	/**
	 * Returns the response body of $url.
	 *
	 * @throws RuntimeException on transport errors or non-2xx HTTP status
	 */
	public static function get(string $url, int $timeoutSec = 10): string {
		$ch = self::init($url, $timeoutSec);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		return (string) self::exec($ch, $url);
	}

	private static function init(string $url, int $timeoutSec): CurlHandle {
		if (!function_exists('curl_init')) {
			throw new RuntimeException('The PHP cURL extension is required.');
		}
		$ch = curl_init($url);
		if ($ch === false) {
			throw new RuntimeException('Failed to initialise cURL for: ' . $url);
		}
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => min(30, $timeoutSec),
			CURLOPT_TIMEOUT => $timeoutSec,
			CURLOPT_USERAGENT => self::USER_AGENT,
			CURLOPT_PROTOCOLS => self::ALLOWED_PROTOCOLS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		]);
		return $ch;
	}

	private static function exec(CurlHandle $ch, string $url): string|bool {
		$result = curl_exec($ch);
		$error = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

		if ($result === false) {
			throw new RuntimeException($error !== '' ? $error : 'request failed');
		}
		if (in_array($scheme, ['http', 'https'], true) && ($status < 200 || $status >= 300)) {
			throw new RuntimeException(sprintf('HTTP %d', $status));
		}
		return $result;
	}
}
