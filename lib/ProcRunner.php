<?php

declare(strict_types=1);

/**
 * Runs a subprocess with a timeout, capturing stdout and stderr.
 */
final class ProcRunner {
	/**
	 * @param string[] $argv
	 * @return array{stdout: string, stderr: string, exit_code: int}
	 * @throws RuntimeException on timeout or process failure to start
	 */
	public static function run(array $argv, string $stdin = '', int $timeoutSec = 30): array {
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($argv, $descriptors, $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Failed to start process: ' . implode(' ', $argv));
		}

		if ($stdin !== '') {
			fwrite($pipes[0], $stdin);
		}
		fclose($pipes[0]);

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = microtime(true) + $timeoutSec;
		$timedOut = false;

		while (true) {
			$status = proc_get_status($process);
			if (!$status['running']) {
				$stdout .= stream_get_contents($pipes[1]);
				$stderr .= stream_get_contents($pipes[2]);
				break;
			}

			if (microtime(true) > $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				break;
			}

			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			if (stream_select($read, $write, $except, 0, 100000) > 0) {
				foreach ($read as $pipe) {
					if ($pipe === $pipes[1]) {
						$stdout .= fread($pipe, 65536);
					} else {
						$stderr .= fread($pipe, 65536);
					}
				}
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		if ($timedOut) {
			throw new RuntimeException(sprintf('Process timed out after %ds: %s', $timeoutSec, implode(' ', $argv)));
		}

		return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
	}
}
