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
# 1. Build the test image (Docker layer cache makes re-runs fast)
# ---------------------------------------------------------------------------
echo "==> Building test image..."
docker compose \
    --file "${COMPOSE_FILE}" \
    build

# ---------------------------------------------------------------------------
# 2. Run shell script tests against the real FreshRSS images
# ---------------------------------------------------------------------------
echo ""
echo "==> Running shell script tests..."

for image in freshrss/freshrss:latest freshrss/freshrss:alpine; do
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
            sh -c '(timeout 30 sh /scripts/entrypoint.sh 2>/dev/null || true)
                   command -v node && command -v npm'
done

echo ""
total_shell=$(( SHELL_TEST_PASS + SHELL_TEST_FAIL ))
printf -- "--- Shell script results: %d/%d passed" "${SHELL_TEST_PASS}" "${total_shell}"
[[ "${SHELL_TEST_FAIL}" -gt 0 ]] && printf ", %d FAILED" "${SHELL_TEST_FAIL}"
printf " ---\n"

# ---------------------------------------------------------------------------
# 3. Run the integration test suite in a fresh container
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

INTEGRATION_EXIT=0
docker compose \
    --file "${COMPOSE_FILE}" \
    run --rm tests || INTEGRATION_EXIT=$?

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
    printf "    Integration tests:   PASSED\n"
else
    printf "    Integration tests:   FAILED (exit %d)\n" "${INTEGRATION_EXIT}"
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
