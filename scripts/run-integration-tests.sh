#!/bin/bash
# Runs the Docker-based integration test suite using *real* obscura and
# *real* defuddle (no mocks). Both are pre-fetched on the host so the
# container does not need internet access.
#
# Usage: bash scripts/run-integration-tests.sh
# Override the Docker socket via DOCKER_HOST=unix:///path/to/docker.sock
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/tests/integration/docker-compose.test.yml"
CACHE_DIR="${PROJECT_ROOT}/tests/integration/.cache"

# Pinned versions used by the integration tests
OBSCURA_TARBALL_URL="https://github.com/h4ckf0r0day/obscura/releases/latest/download/obscura-x86_64-linux.tar.gz"
DEFUDDLE_VERSION="0.18.1"

DOCKER_HOST="${DOCKER_HOST:-unix:///var/run/docker.sock}"
export DOCKER_HOST

# ---------------------------------------------------------------------------
# 1. Pre-fetch real binaries on the host (cached for re-runs)
# ---------------------------------------------------------------------------
mkdir -p "${CACHE_DIR}/bin"

if [ ! -x "${CACHE_DIR}/bin/obscura" ]; then
    echo "==> Downloading obscura binary..."
    TMP_TGZ="$(mktemp)"
    curl -fsSL --max-time 180 -o "${TMP_TGZ}" "${OBSCURA_TARBALL_URL}"
    tar -xzf "${TMP_TGZ}" -C "${CACHE_DIR}/bin"
    rm -f "${TMP_TGZ}"
    chmod +x "${CACHE_DIR}/bin/obscura" "${CACHE_DIR}/bin/obscura-worker" 2>/dev/null || true
    echo "    obscura cached at ${CACHE_DIR}/bin/obscura"
else
    echo "==> obscura already cached"
fi

INSTALLED_DEFUDDLE=""
if [ -f "${CACHE_DIR}/node_modules/defuddle/package.json" ]; then
    INSTALLED_DEFUDDLE="$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "${CACHE_DIR}/node_modules/defuddle/package.json" | head -1)"
fi
if [ "${INSTALLED_DEFUDDLE}" != "${DEFUDDLE_VERSION}" ]; then
    echo "==> Installing defuddle@${DEFUDDLE_VERSION} into cache..."
    rm -rf "${CACHE_DIR}/node_modules" "${CACHE_DIR}/package.json" "${CACHE_DIR}/package-lock.json"
    npm install --no-audit --no-fund --silent --prefix "${CACHE_DIR}" "defuddle@${DEFUDDLE_VERSION}"
    echo "    defuddle@${DEFUDDLE_VERSION} cached at ${CACHE_DIR}/node_modules/defuddle"
else
    echo "==> defuddle@${DEFUDDLE_VERSION} already cached"
fi

# Expose the pinned version to the in-container test runner via env file
echo "DEFUDDLE_PINNED_VERSION=${DEFUDDLE_VERSION}" > "${CACHE_DIR}/.versions"

# ---------------------------------------------------------------------------
# 2. Run the test suite in a fresh container
# ---------------------------------------------------------------------------
cleanup() {
    echo ""
    echo "==> Tearing down test container..."
    DOCKER_HOST="${DOCKER_HOST}" docker compose \
        -f "${COMPOSE_FILE}" \
        down --volumes --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo ""
echo "==> Running integration tests (Docker host: ${DOCKER_HOST})"
echo ""

set +e
DOCKER_HOST="${DOCKER_HOST}" docker compose \
    -f "${COMPOSE_FILE}" \
    run --rm tests
EXIT_CODE=$?
set -e

echo ""
if [ ${EXIT_CODE} -eq 0 ]; then
    echo "Integration tests PASSED."
else
    echo "Integration tests FAILED (exit code ${EXIT_CODE})."
fi
exit ${EXIT_CODE}
