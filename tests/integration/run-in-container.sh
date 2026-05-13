#!/bin/sh
# Runs inside the test Docker container.
# Real obscura and real defuddle are baked into the image at /opt/test-deps
# (or the path given by TEST_DEPS_DIR).
set -eu

EXT=/var/www/FreshRSS/extensions/xExtension-FullTextContent
FRESHRSS=/var/www/FreshRSS
TEST_DEPS_DIR="${TEST_DEPS_DIR:-/opt/test-deps}"

# ---------------------------------------------------------------------------
# 1. Sanity checks: binaries must be present in the image
# ---------------------------------------------------------------------------
echo "==> Verifying baked-in binaries..."
test -x "${TEST_DEPS_DIR}/bin/obscura"              || { echo "ERROR: ${TEST_DEPS_DIR}/bin/obscura missing"; exit 1; }
test -f "${TEST_DEPS_DIR}/node_modules/defuddle/dist/cli.js" || { echo "ERROR: defuddle missing"; exit 1; }
test -f "${TEST_DEPS_DIR}/.versions"                || { echo "ERROR: ${TEST_DEPS_DIR}/.versions missing"; exit 1; }

. "${TEST_DEPS_DIR}/.versions"  # exports DEFUDDLE_PINNED_VERSION
export DEFUDDLE_PINNED_VERSION

echo "    obscura: $("${TEST_DEPS_DIR}/bin/obscura" --help 2>&1 | head -1)"
echo "    node:    $(node --version)"
echo "    defuddle pinned to: ${DEFUDDLE_PINNED_VERSION}"
INSTALLED_DEFUDDLE=$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "${TEST_DEPS_DIR}/node_modules/defuddle/package.json" | head -1)
echo "    defuddle installed: ${INSTALLED_DEFUDDLE}"
if [ "${INSTALLED_DEFUDDLE}" != "${DEFUDDLE_PINNED_VERSION}" ]; then
    echo "ERROR: defuddle version mismatch (image=${INSTALLED_DEFUDDLE}, expected=${DEFUDDLE_PINNED_VERSION})"
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Initialise FreshRSS
# ---------------------------------------------------------------------------
echo ""
echo "==> Initialising FreshRSS (SQLite)..."
php "${FRESHRSS}/cli/do-install.php" \
    --default-user admin \
    --auth-type none \
    --environment development \
    --base-url http://localhost \
    --language en \
    --title 'FreshRSS Integration Test' \
    --db-type sqlite

echo "==> Creating admin user..."
php "${FRESHRSS}/cli/create-user.php" \
    --user admin \
    --no-default-feeds

# ---------------------------------------------------------------------------
# 3. Run tests
# ---------------------------------------------------------------------------
echo ""
echo "============================================================"
echo "  Pipeline integration tests (real obscura + real defuddle)"
echo "============================================================"
php "${EXT}/tests/integration/test_pipeline.php"
PIPELINE_EXIT=$?

echo ""
echo "============================================================"
echo "  FreshRSS-context integration tests"
echo "============================================================"
php "${EXT}/tests/integration/test_freshrss.php"
FRESHRSS_EXIT=$?

echo ""
if [ ${PIPELINE_EXIT} -eq 0 ] && [ ${FRESHRSS_EXIT} -eq 0 ]; then
    echo "=== ALL INTEGRATION TESTS PASSED ==="
    exit 0
else
    echo "=== INTEGRATION TESTS FAILED (pipeline=${PIPELINE_EXIT}, freshrss=${FRESHRSS_EXIT}) ==="
    exit 1
fi
