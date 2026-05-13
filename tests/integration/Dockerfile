FROM freshrss/freshrss:latest

ARG DEFUDDLE_VERSION=0.18.1
ARG OBSCURA_TARBALL_URL=https://github.com/h4ckf0r0day/obscura/releases/latest/download/obscura-x86_64-linux.tar.gz

RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm curl ca-certificates && rm -rf /var/lib/apt/lists/*

# curl: --insecure bypasses TLS cert verification for environments that
# intercept HTTPS with a self-signed certificate (e.g. Claude Code web sandbox).
RUN mkdir -p /opt/test-deps/bin \
    && curl -fsSL --insecure --max-time 180 -o /tmp/obscura.tar.gz "${OBSCURA_TARBALL_URL}" \
    && tar -xzf /tmp/obscura.tar.gz -C /opt/test-deps/bin \
    && chmod +x /opt/test-deps/bin/obscura \
    && rm /tmp/obscura.tar.gz

# npm_config_strict_ssl=false: same reason — npm's own TLS check also needs to
# be disabled in self-signed-cert environments (Claude Code web sandbox).
RUN echo "strict-ssl=false" > /root/.npmrc \
    && npm install --no-audit --no-fund --prefix /opt/test-deps "defuddle@${DEFUDDLE_VERSION}" \
    && echo "DEFUDDLE_PINNED_VERSION=${DEFUDDLE_VERSION}" > /opt/test-deps/.versions
