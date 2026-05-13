#!/bin/sh
# Thin wrapper around the FreshRSS container entrypoint.
# Runs install-node.sh (idempotent) before handing off to the original entrypoint.

set -e
set -u
if [ "${TRACE-0}" = "1" ]; then
    set -x
fi

cd "$(dirname "$0")"

sh ./install-node.sh || {
    echo "[FullTextContent] node installation failed; continuing without node." >&2
}

cd /var/www/FreshRSS
exec /var/www/FreshRSS/Docker/entrypoint.sh "$@"
