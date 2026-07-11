#!/usr/bin/env bash
# Runs the standalone PHP unit test suite (scripts/test.php). Prefers a host
# `php` binary and falls back to a Docker `php` container when php is not
# installed, so the host only needs either a PHP CLI or a Docker daemon.

set -o errexit
set -o nounset
set -o pipefail
if [[ "${TRACE-0}" == "1" ]]; then
    set -o xtrace
fi

if [[ "${1-}" =~ ^-*h(elp)?$ ]]; then
    echo 'Usage: bash scripts/test.sh

Runs the standalone PHP unit test suite (scripts/test.php). Prefers a host
`php` binary and falls back to a Docker `php` container when php is not
installed on the host.

Environment variables:
  PHP_IMAGE      Docker image to use when falling back (default: php:8.3-cli)
  FORCE_DOCKER   Set to 1 to force Docker even when a host php exists
  TRACE=1        Enable xtrace debug output
'
    exit
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

PHP_IMAGE="${PHP_IMAGE:-php:8.3-cli}"

if command -v php >/dev/null 2>&1 && [[ "${FORCE_DOCKER-0}" != "1" ]]; then
    echo "==> Running unit tests with host php" >&2
    php "${PROJECT_ROOT}/scripts/test.php"
else
    echo "==> Running unit tests in Docker (${PHP_IMAGE})" >&2
    # The repo is mounted read-only because test.php only writes to the system
    # temp dir inside the container, never the repo.
    docker run --rm \
        --volume "${PROJECT_ROOT}:/app:ro" \
        --workdir /app \
        "${PHP_IMAGE}" \
        php scripts/test.php
fi
