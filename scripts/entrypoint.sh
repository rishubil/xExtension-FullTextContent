#!/bin/sh
# Thin wrapper around the FreshRSS container entrypoint.
# Runs install-node.sh (idempotent) before handing off to the original entrypoint.
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

sh "${SCRIPT_DIR}/install-node.sh" || {
	echo "[FullTextContent] node installation failed; continuing without node." >&2
}

# The FreshRSS official image uses /entrypoint.sh.
# Adjust if your base image uses a different entrypoint.
exec /entrypoint.sh "$@"
