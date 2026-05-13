FROM freshrss/freshrss:latest

ARG DEFUDDLE_VERSION=0.18.1
ARG OBSCURA_TARBALL_URL=https://github.com/h4ckf0r0day/obscura/releases/latest/download/obscura-x86_64-linux.tar.gz

RUN apk add --no-cache nodejs npm curl

RUN mkdir -p /opt/test-deps/bin \
    && curl -fsSL --max-time 180 -o /tmp/obscura.tar.gz "${OBSCURA_TARBALL_URL}" \
    && tar -xzf /tmp/obscura.tar.gz -C /opt/test-deps/bin \
    && chmod +x /opt/test-deps/bin/obscura \
    && rm /tmp/obscura.tar.gz

RUN npm install --no-audit --no-fund --prefix /opt/test-deps "defuddle@${DEFUDDLE_VERSION}" \
    && echo "DEFUDDLE_PINNED_VERSION=${DEFUDDLE_VERSION}" > /opt/test-deps/.versions
