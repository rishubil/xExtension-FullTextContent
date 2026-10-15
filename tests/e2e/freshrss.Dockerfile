# FreshRSS + Node.js, as documented in README "Option B — custom Dockerfile".
# obscura and defuddle are NOT baked in: the extension downloads/installs them
# itself, which is part of what the end-to-end suite verifies.
ARG FRESHRSS_VERSION=1.30.1
FROM freshrss/freshrss:${FRESHRSS_VERSION}

RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm \
    && rm -rf /var/lib/apt/lists/*

COPY config.custom.php /var/www/FreshRSS/data/config.custom.php
