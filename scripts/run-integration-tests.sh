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
  DOCKER_HOST       Docker socket path (default: unix:///var/run/docker.sock)
  FRESHRSS_VERSIONS  Space-separated FreshRSS versions to test (default: the
                     freshrss_min_version from metadata.json and 1.30.1)
  TRACE=1           Enable xtrace debug output
'
    exit
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/tests/integration/docker-compose.test.yml"

# shellcheck source=scripts/freshrss-versions.sh
source "${SCRIPT_DIR}/freshrss-versions.sh"

DOCKER_HOST="${DOCKER_HOST:-unix:///var/run/docker.sock}"
export DOCKER_HOST

# ---------------------------------------------------------------------------
# Shell-script test helpers
# ---------------------------------------------------------------------------
SHELL_TEST_PASS=0
SHELL_TEST_FAIL=0

run_shell_test() {
    local label="$1"
    shift
    local result=0
    "$@" >/dev/null 2>&1 || result=$?
    if [[ "${result}" -eq 0 ]]; then
        echo "  [PASS] ${label}"
        (( SHELL_TEST_PASS++ )) || true
    else
        echo "  [FAIL] ${label}"
        (( SHELL_TEST_FAIL++ )) || true
    fi
}

# ---------------------------------------------------------------------------
# Run shell script tests against the real FreshRSS images
# ---------------------------------------------------------------------------
echo ""
echo "==> Running shell script tests..."

for version in ${FRESHRSS_VERSIONS}; do
for image in "freshrss/freshrss:${version}" "freshrss/freshrss:${version}-alpine"; do
    echo ""
    echo "=== Shell Script Tests (${image}) ==="

    run_shell_test "install-node.sh: installs node and npm" \
        docker run --rm \
            -v "${PROJECT_ROOT}/scripts:/scripts:ro" \
            "${image}" \
            sh -c 'sh /scripts/install-node.sh >/dev/null 2>&1 && command -v node && command -v npm'

    run_shell_test "install-node.sh: skips when already installed" \
        docker run --rm \
            -v "${PROJECT_ROOT}/scripts:/scripts:ro" \
            "${image}" \
            sh -c 'sh /scripts/install-node.sh >/dev/null 2>&1
                   output=$(sh /scripts/install-node.sh 2>&1)
                   echo "$output" | grep -q "already installed"'

    run_shell_test "entrypoint.sh: install-node.sh is called before exec" \
        docker run --rm \
            -v "${PROJECT_ROOT}/scripts:/scripts:ro" \
            "${image}" \
            sh -c '(timeout 300 sh /scripts/entrypoint.sh >/dev/null 2>&1 || true)
                   command -v node && command -v npm'
done
done

echo ""
total_shell=$(( SHELL_TEST_PASS + SHELL_TEST_FAIL ))
printf -- "--- Shell script results: %d/%d passed" "${SHELL_TEST_PASS}" "${total_shell}"
[[ "${SHELL_TEST_FAIL}" -gt 0 ]] && printf ", %d FAILED" "${SHELL_TEST_FAIL}"
printf " ---\n"

# ---------------------------------------------------------------------------
# Run the integration test suite in a fresh container, once per version
# ---------------------------------------------------------------------------
cleanup() {
    echo ""
    echo "==> Tearing down test container..."
    docker compose \
        --file "${COMPOSE_FILE}" \
        down --volumes --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

INTEGRATION_EXIT=0
INTEGRATION_FAILED_VERSIONS=""
for version in ${FRESHRSS_VERSIONS}; do
    export FRESHRSS_VERSION="${version}"
    # Remove state left behind by an interrupted previous run (stale volume
    # makes do-install.php fail with "already installed").
    cleanup >/dev/null

    echo ""
    echo "==> Building test image for FreshRSS ${version}..."
    docker compose --file "${COMPOSE_FILE}" build

    echo ""
    echo "==> Running integration tests on FreshRSS ${version} (Docker host: ${DOCKER_HOST})"
    echo ""
    if ! docker compose --file "${COMPOSE_FILE}" run --rm tests; then
        INTEGRATION_EXIT=1
        INTEGRATION_FAILED_VERSIONS="${INTEGRATION_FAILED_VERSIONS} ${version}"
    fi
done

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "==> Overall Results"
echo ""

shell_total=$(( SHELL_TEST_PASS + SHELL_TEST_FAIL ))
printf "    Shell script tests:  %d/%d passed" "${SHELL_TEST_PASS}" "${shell_total}"
[[ "${SHELL_TEST_FAIL}" -gt 0 ]] && printf ", %d FAILED" "${SHELL_TEST_FAIL}"
printf "\n"

if [[ "${INTEGRATION_EXIT}" -eq 0 ]]; then
    printf "    Integration tests:   PASSED (FreshRSS %s)\n" "${FRESHRSS_VERSIONS}"
else
    printf "    Integration tests:   FAILED on FreshRSS%s\n" "${INTEGRATION_FAILED_VERSIONS}"
fi

echo ""
EXIT_CODE=0
[[ "${SHELL_TEST_FAIL}" -gt 0 ]] && EXIT_CODE=1
[[ "${INTEGRATION_EXIT}" -ne 0 ]] && EXIT_CODE=1

if [[ "${EXIT_CODE}" -eq 0 ]]; then
    echo "All tests PASSED."
else
    echo "Some tests FAILED."
fi
exit "${EXIT_CODE}"
