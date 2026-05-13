#!/usr/bin/env bash
# Thin wrapper around the FreshRSS container entrypoint.
# Runs install-node.sh (idempotent) before handing off to the original entrypoint.

set -o errexit
set -o nounset
set -o pipefail
if [[ "${TRACE-0}" == "1" ]]; then
    set -o xtrace
fi

cd "$(dirname "${BASH_SOURCE[0]}")"

bash ./install-node.sh || {
    echo "[FullTextContent] node installation failed; continuing without node." >&2
}

# The FreshRSS official image uses /entrypoint.sh.
# Adjust if your base image uses a different entrypoint.
exec /entrypoint.sh "$@"
