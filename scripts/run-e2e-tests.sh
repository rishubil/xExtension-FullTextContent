#!/usr/bin/env bash
# Runs the Playwright end-to-end suite against a real FreshRSS instance with
# the extension mounted, a fixture server for feeds/articles, and the real
# obscura binary + defuddle package installed by the extension itself.

set -o errexit
set -o nounset
set -o pipefail
if [[ "${TRACE-0}" == "1" ]]; then
    set -o xtrace
fi

if [[ "${1-}" =~ ^-*h(elp)?$ ]]; then
    echo 'Usage: bash scripts/run-e2e-tests.sh [playwright args...]

Builds and starts FreshRSS + the fixture server with Docker Compose, runs the
Playwright suite in the official Playwright image, then tears everything down.
Extra arguments are passed to `playwright test` (e.g. -g "re-fetch").

Environment variables:
  DOCKER_HOST         Docker socket path (default: unix:///var/run/docker.sock)
  E2E_PORT            Host port for FreshRSS (default: 18080)
  E2E_FIXTURES_PORT   Host port for the fixture server (default: 18081)
  E2E_KEEP=1          Keep the stack running after the tests (for debugging;
                      the stack of the last tested version is kept)
  FRESHRSS_VERSIONS   Space-separated FreshRSS versions to test (default: the
                      freshrss_min_version from metadata.json and 1.30.1)
  TRACE=1             Enable xtrace debug output
'
    exit
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
COMPOSE=(docker compose --file "${PROJECT_ROOT}/tests/e2e/docker-compose.e2e.yml")

# shellcheck source=scripts/freshrss-versions.sh
source "${SCRIPT_DIR}/freshrss-versions.sh"

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
export HOST_UID HOST_GID

cleanup() {
    if [[ "${E2E_KEEP-0}" == "1" ]]; then
        echo "==> E2E_KEEP=1: stack left running (FreshRSS on http://localhost:${E2E_PORT:-18080})"
        return
    fi
    echo "==> Tearing down e2e stack..."
    "${COMPOSE[@]}" --profile test down --volumes --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT

FAILED_VERSIONS=""
for version in ${FRESHRSS_VERSIONS}; do
    export FRESHRSS_VERSION="${version}"
    # Start from a fresh FreshRSS instance for every version.
    "${COMPOSE[@]}" --profile test down --volumes --remove-orphans >/dev/null 2>&1 || true

    echo "==> [FreshRSS ${version}] Building and starting FreshRSS + fixtures..."
    "${COMPOSE[@]}" up --detach --build --wait freshrss fixtures

    echo "==> [FreshRSS ${version}] Running Playwright..."
    # `compose run` replaces the service command, so pass the full command here.
    if ! "${COMPOSE[@]}" run --rm playwright \
        sh -c 'npm ci --no-audit --no-fund && npx playwright test "$@"' -- "$@"; then
        FAILED_VERSIONS="${FAILED_VERSIONS} ${version}"
        # Keep the report of the failing version.
        rm -rf "${PROJECT_ROOT}/tests/e2e/playwright-report-${version}"
        cp -r "${PROJECT_ROOT}/tests/e2e/playwright-report" "${PROJECT_ROOT}/tests/e2e/playwright-report-${version}" 2>/dev/null || true
    fi
done

echo ""
if [[ -n "${FAILED_VERSIONS}" ]]; then
    echo "==> E2E FAILED on FreshRSS${FAILED_VERSIONS}"
    exit 1
fi
echo "==> E2E PASSED on FreshRSS ${FRESHRSS_VERSIONS}"
