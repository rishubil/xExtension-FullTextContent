#!/bin/sh
# Runs inside the FreshRSS Docker container.
# Sets up Node.js, installs FreshRSS, and runs all integration tests.
set -eu

EXT=/var/www/FreshRSS/extensions/xExtension-FullTextContent
FRESHRSS=/var/www/FreshRSS

# ---------------------------------------------------------------------------
# 1. Install Node.js
# ---------------------------------------------------------------------------
echo "==> Installing Node.js..."
sh "${EXT}/scripts/install-node.sh"
echo "    node $(node --version), npm $(npm --version)"

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
echo "  Pipeline integration tests"
echo "============================================================"
php "${EXT}/tests/integration/test_pipeline.php"
PIPELINE_EXIT=$?

echo ""
echo "============================================================"
echo "  FreshRSS-context integration tests"
echo "============================================================"
php "${EXT}/tests/integration/test_freshrss.php"
FRESHRSS_EXIT=$?

# ---------------------------------------------------------------------------
# 4. Final result
# ---------------------------------------------------------------------------
echo ""
if [ $PIPELINE_EXIT -eq 0 ] && [ $FRESHRSS_EXIT -eq 0 ]; then
    echo "=== ALL INTEGRATION TESTS PASSED ==="
    exit 0
else
    echo "=== INTEGRATION TESTS FAILED (pipeline=${PIPELINE_EXIT}, freshrss=${FRESHRSS_EXIT}) ==="
    exit 1
fi
