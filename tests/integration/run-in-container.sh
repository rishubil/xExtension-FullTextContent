#!/bin/sh
# Runs inside the FreshRSS Docker container.
# Real obscura and real defuddle are mounted at /cache (pre-fetched on the host).
set -eu

EXT=/var/www/FreshRSS/extensions/xExtension-FullTextContent
FRESHRSS=/var/www/FreshRSS

# ---------------------------------------------------------------------------
# 1. Ensure Node.js is available (mounted /opt/node22 in compose; otherwise
#    fall back to the extension's idempotent installer, which uses apt/apk
#    if the container has network access).
# ---------------------------------------------------------------------------
if ! command -v node >/dev/null 2>&1; then
    echo "==> Node.js not on PATH — invoking install-node.sh"
    sh "${EXT}/scripts/install-node.sh"
fi
command -v node >/dev/null 2>&1 || { echo "ERROR: node still missing after install attempt"; exit 1; }

# ---------------------------------------------------------------------------
# 2. Sanity checks: real binaries are in the cache mount
# ---------------------------------------------------------------------------
echo "==> Verifying host-pre-fetched binaries are available..."
test -x /cache/bin/obscura || { echo "ERROR: /cache/bin/obscura missing"; exit 1; }
test -f /cache/node_modules/defuddle/dist/cli.js || { echo "ERROR: defuddle missing"; exit 1; }
test -f /cache/.versions || { echo "ERROR: /cache/.versions missing"; exit 1; }

. /cache/.versions  # exports DEFUDDLE_PINNED_VERSION
export DEFUDDLE_PINNED_VERSION

echo "    obscura: $(/cache/bin/obscura --help 2>&1 | head -1)"
echo "    node:    $(node --version)"
echo "    defuddle pinned to: ${DEFUDDLE_PINNED_VERSION}"
INSTALLED_DEFUDDLE=$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' /cache/node_modules/defuddle/package.json | head -1)
echo "    defuddle installed: ${INSTALLED_DEFUDDLE}"
if [ "${INSTALLED_DEFUDDLE}" != "${DEFUDDLE_PINNED_VERSION}" ]; then
    echo "ERROR: defuddle version mismatch (cache=${INSTALLED_DEFUDDLE}, expected=${DEFUDDLE_PINNED_VERSION})"
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
