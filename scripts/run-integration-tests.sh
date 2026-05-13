#!/bin/bash
# Runs the Docker-based integration test suite.
# Usage: bash scripts/run-integration-tests.sh [--docker-host <socket>]
#
# The script starts a temporary FreshRSS container, runs all integration tests
# inside it, then tears the container down. Exit code mirrors the test result.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/tests/integration/docker-compose.test.yml"

# Allow overriding the Docker socket (e.g. in CI or sandboxed envs)
DOCKER_HOST="${DOCKER_HOST:-unix:///var/run/docker.sock}"
export DOCKER_HOST

cleanup() {
    echo ""
    echo "==> Cleaning up..."
    DOCKER_HOST="${DOCKER_HOST}" docker compose \
        -f "${COMPOSE_FILE}" \
        down --volumes --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Running integration tests (Docker host: ${DOCKER_HOST})"
echo "    Compose file: ${COMPOSE_FILE}"
echo ""

DOCKER_HOST="${DOCKER_HOST}" docker compose \
    -f "${COMPOSE_FILE}" \
    run --rm tests

EXIT_CODE=$?
echo ""
if [ $EXIT_CODE -eq 0 ]; then
    echo "Integration tests PASSED."
else
    echo "Integration tests FAILED (exit code ${EXIT_CODE})."
fi
exit $EXIT_CODE
