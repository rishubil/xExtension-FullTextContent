#!/usr/bin/env bash
# Runs the Docker-based integration test suite using *real* obscura and
# *real* defuddle (no mocks). Both are baked into a custom Docker image
# at build time so the host only needs a Docker daemon — no curl, npm, or
# tar required.

set -o errexit
set -o nounset
set -o pipefail
if [[ "${TRACE-0}" == "1" ]]; then
    set -o xtrace
fi

if [[ "${1-}" =~ ^-*h(elp)?$ ]]; then
    echo 'Usage: bash scripts/run-integration-tests.sh

Runs the Docker-based integration test suite using real obscura and real
defuddle (no mocks). Both are baked into a custom Docker image at build
time so the host only needs a Docker daemon.

Environment variables:
  DOCKER_HOST   Docker socket path (default: unix:///var/run/docker.sock)
  TRACE=1       Enable xtrace debug output
'
    exit
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/tests/integration/docker-compose.test.yml"

DOCKER_HOST="${DOCKER_HOST:-unix:///var/run/docker.sock}"
export DOCKER_HOST

# ---------------------------------------------------------------------------
# 1. Build the test image (Docker layer cache makes re-runs fast)
# ---------------------------------------------------------------------------
echo "==> Building test image..."
docker compose \
    --file "${COMPOSE_FILE}" \
    build

# ---------------------------------------------------------------------------
# 2. Run the test suite in a fresh container
# ---------------------------------------------------------------------------
cleanup() {
    echo ""
    echo "==> Tearing down test container..."
    docker compose \
        --file "${COMPOSE_FILE}" \
        down --volumes --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo ""
echo "==> Running integration tests (Docker host: ${DOCKER_HOST})"
echo ""

EXIT_CODE=0
docker compose \
    --file "${COMPOSE_FILE}" \
    run --rm tests || EXIT_CODE=$?

echo ""
if [[ "${EXIT_CODE}" -eq 0 ]]; then
    echo "Integration tests PASSED."
else
    echo "Integration tests FAILED (exit code ${EXIT_CODE})."
fi
exit "${EXIT_CODE}"
