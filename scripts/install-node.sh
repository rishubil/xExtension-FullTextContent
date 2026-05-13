#!/bin/sh
# Idempotent: exits immediately if node and npm are already available.

set -e
set -u
if [ "${TRACE-0}" = "1" ]; then
    set -x
fi

if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
    echo "[FullTextContent] node $(node --version) and npm $(npm --version) already installed."
    exit 0
fi

echo "[FullTextContent] Installing Node.js and npm..."

if command -v apk >/dev/null 2>&1; then
    apk add --no-cache nodejs npm
elif command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install --yes --no-install-recommends nodejs npm
    rm -rf /var/lib/apt/lists/*
else
    echo "[FullTextContent] Unsupported package manager. Please install node and npm manually." >&2
    exit 1
fi

echo "[FullTextContent] node $(node --version) and npm $(npm --version) installed."
