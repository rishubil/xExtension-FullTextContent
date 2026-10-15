# xExtension-FullTextContent

> **⚠️ Work In Progress**
> This extension is currently under active development and is **not yet ready for production use**.
> Functionality may be incomplete, APIs may change without notice, and bugs are expected.

A [FreshRSS](https://freshrss.org) extension that replaces each feed entry's body with cleaned full-text content fetched from the source URL.

## How it works

For each new entry in a feed that has the extension enabled, the pipeline:

1. Fetches the full page HTML using [obscura](https://github.com/h4ckf0r0day/obscura) (a headless browser binary that renders JavaScript).
2. Extracts and converts the article body to Markdown using [defuddle](https://github.com/kepano/defuddle).
3. Converts the Markdown back to HTML using [Parsedown](https://parsedown.org).
4. Replaces the entry's content in FreshRSS.

This runs at feed refresh time (`EntryBeforeInsert`), so extracted content is persisted to the database.

## Requirements

- **FreshRSS 1.29.0+** (self-hosted). Integration and end-to-end tests run against both the declared minimum (`freshrss_min_version` in `metadata.json`, currently **1.29.0**) and **FreshRSS 1.30.1**, with **obscura 0.2.4** and **defuddle 0.19.5**.
- **PHP cURL extension** (bundled with the official FreshRSS images). FreshRSS 1.30+ disables PHP's `http`/`https` stream wrappers, so the extension downloads obscura and queries npm via cURL.
- **Linux x86_64 or aarch64** host (for the obscura binary).
  > **Note:** The pre-built obscura binary from the upstream GitHub releases is dynamically linked against **glibc**. It will not run on Alpine-based images (which use musl libc). On Alpine you must build obscura from source and point the extension to the resulting binary via **Obscura binary path override** (see [Configuration](#configuration)).
- **obscura 0.2.4+** and **defuddle 0.19.5+** — the versions the test suites verify (`lib/VersionRequirements.php`). The configure page shows a warning when an older obscura (including a binary set as override) or defuddle is installed, or when the defuddle version is pinned below the minimum.
- **Node.js + npm** — required to run defuddle. See [Docker setup](#docker-setup) below.

## Installation

1. Copy this directory into your FreshRSS `extensions/` folder as `xExtension-FullTextContent`.
2. Enable the extension in FreshRSS under **Administration → Extensions**.
3. Install Node.js (see below), then open the extension's configuration page to download obscura and defuddle.

## Docker setup

The FreshRSS official Docker image does not include Node.js. Use the provided entrypoint wrapper to install it automatically on container start.

### Option A — entrypoint override (recommended)

Debian-based (freshrss/freshrss:latest)

```yaml
# docker-compose.yml
services:
  freshrss:
    image: freshrss/freshrss:latest
    entrypoint: /var/www/FreshRSS/extensions/xExtension-FullTextContent/scripts/entrypoint.sh
    command: |
      sh -c '([ -z "$$CRON_MIN" ] || cron) && \
      . /etc/apache2/envvars && \
      exec apache2 -D FOREGROUND $$([ -n "$$OIDC_ENABLED" ] && [ "$$OIDC_ENABLED" -ne 0 ] && echo "-D OIDC_ENABLED")'
    volumes:
      - ./extensions:/var/www/FreshRSS/extensions
      - ./data:/var/www/FreshRSS/data
```

Alpine-based (freshrss/freshrss:alpine)

> **Warning:** The default obscura binary (glibc) does not run on Alpine (musl libc). You must build obscura from source and set the **Obscura binary path override** in the extension's configuration page.

```yaml
# docker-compose.yml
services:
  freshrss:
    image: freshrss/freshrss:alpine
    entrypoint: /var/www/FreshRSS/extensions/xExtension-FullTextContent/scripts/entrypoint.sh
    command: |
      sh -c '([ -z "$$CRON_MIN" ] || crond -d 6) && \
      exec httpd -D FOREGROUND $([ -n "$$OIDC_ENABLED" ] && [ "$$OIDC_ENABLED" -ne 0 ] && echo "-D OIDC_ENABLED")'
    volumes:
      - ./extensions:/var/www/FreshRSS/extensions
      - ./data:/var/www/FreshRSS/data
```

The `entrypoint.sh` script installs node/npm (idempotent: skips if already present) then delegates to `/var/www/FreshRSS/Docker/entrypoint.sh`.

### Option B — custom Dockerfile

Debian-based (freshrss/freshrss:latest)

```dockerfile
FROM freshrss/freshrss:latest
RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm \
    && rm -rf /var/lib/apt/lists/*
```

Alpine-based (freshrss/freshrss:alpine)

> **Warning:** The default obscura binary (glibc) does not run on Alpine (musl libc). You must build obscura from source and set the **Obscura binary path override** in the extension's configuration page.

```dockerfile
FROM freshrss/freshrss:alpine
RUN apk add --no-cache nodejs npm
```

## Configuration

Open **Administration → Extensions → Full Text Content → Configure**.

### Global settings

| Field | Default | Description |
|---|---|---|
| Node.js binary path | `node` | Path to the `node` executable inside the container. |
| Obscura download URL template | upstream GitHub URL | Template for downloading the obscura binary. `{arch}` is replaced with the host architecture (e.g. `x86_64-linux`) and `{variant}` with `-stealth` or nothing, depending on **Use the obscura stealth build**. |
| Obscura binary path override | *(auto)* | Override the resolved binary path. Leave blank to use the auto-downloaded binary. |
| Defuddle version | `latest` | `latest` always upgrades to the newest npm release. Any other value (e.g. `0.6.2`) pins to that exact version. |
| Defuddle update check interval | `168` hours | How often to check for a newer defuddle version when set to `latest`. Ignored when pinned. |
| Fetch timeout | `30` seconds | Maximum time allowed for obscura to fetch and render a URL. |
| Allow private network access | off | Passes `--allow-private-network` to obscura so it can fetch articles hosted on loopback, RFC 1918 or link-local addresses (e.g. a self-hosted site on your LAN). obscura 0.2+ blocks these by default as an SSRF guard. When enabled it applies to every fetch, including resources the article page loads. |
| Use the obscura stealth build | off | Downloads obscura's stealth build instead of the standard one. See [Stealth](#stealth) below. Changing it replaces the binary on the next fetch (or immediately via **Redownload obscura binary**). |

> **Local feeds on FreshRSS 1.30+:** FreshRSS itself also blocks feeds on private networks unless they are listed in *Administration → System configuration → Internal host allowlist* (or the `INTERNAL_HOST_ALLOWLIST` environment variable). Full-text fetching of such articles additionally needs **Allow private network access** above.

### Status & actions

- **Update defuddle now** — installs or upgrades defuddle immediately regardless of the check interval.
- **Redownload obscura binary** — forces a fresh download of the obscura binary using the configured URL.

Saving and both actions show a confirmation, or the error (e.g. `HTTP 404` from the download URL, or npm's reason) when they fail. The status table reports the obscura binary actually in use (the override when set) and warns about versions below the supported minimum.

### Stealth

obscura's `--stealth` flag (the per-feed **Stealth** option) behaves differently depending on the build:

| Build | Per-feed Stealth does |
|---|---|
| standard (default) | masks the browser fingerprint only (randomised GPU/canvas/audio/screen values, `navigator.webdriver` hidden, Chrome-like `userAgentData`) |
| stealth | the above **plus** Chrome TLS fingerprint impersonation and blocking of ~3,500 tracker domains |

Most article pages do not need any of this: obscura is mainly used to render JavaScript. Stealth only matters for sites behind bot protection, which frequently rejects non-browser TLS fingerprints before any JavaScript runs — that part requires the stealth build. Both builds report the same `--version`, so the extension records which one it downloaded; the configure page shows it and warns when feeds use Stealth with the standard build. Bypassing bot protection may conflict with a site's terms of use.

### Per-feed opt-in

Full-text fetching is disabled by default for all feeds. Toggle it per feed in the extension's configuration page.

## Testing

### Unit tests

Runs standalone — no FreshRSS required. Covers `ProcRunner`, `BinaryResolver`, `DefuddleManager`, and `Parsedown`.

Use the `test.sh` wrapper, which prefers a host `php` binary and falls back to a Docker `php` container when PHP is not installed on the host:

```bash
bash scripts/test.sh

# Force the Docker path even if host php exists, or override the image
FORCE_DOCKER=1 bash scripts/test.sh
PHP_IMAGE=php:8.2-cli bash scripts/test.sh
```

If PHP 8.1+ is installed on the host you can also run the script directly:

```bash
php scripts/test.php
```

### Shell script tests

Verifies that `install-node.sh` and `entrypoint.sh` work correctly inside the Debian and Alpine FreshRSS images of every tested version. These run automatically as part of `run-integration-tests.sh`.

### Integration tests

Runs the **real** obscura binary and the **real** defuddle npm package (no mocks) inside a custom Docker image built on top of the official FreshRSS image, once per tested FreshRSS version. The runner initialises FreshRSS via its CLI, then exercises both the extraction pipeline and the FreshRSS extension lifecycle end-to-end.

**Requirements:** Docker daemon only. No host-side `curl`, `npm`, or `tar` needed — all dependencies are installed inside the image at build time.

```bash
# Default socket path
bash scripts/run-integration-tests.sh

# Custom socket (e.g. rootless Docker or a sandboxed environment)
DOCKER_HOST=unix:///tmp/docker.sock bash scripts/run-integration-tests.sh
```

The script:

1. **Builds the test image** (`tests/integration/Dockerfile`) — installs Node.js, downloads obscura, and installs obscura and defuddle at the pinned versions (`v0.2.4` / `0.19.5`); the runner fails if the installed FreshRSS, obscura or defuddle version differs from the pin. Docker layer caching makes subsequent runs fast.
2. **Runs shell script tests** against the Debian and Alpine images of every tested version using real package managers.
3. Starts a temporary container from the built image.
4. Initialises FreshRSS via `cli/do-install.php` + `cli/create-user.php`.
5. Runs both test suites against `file:///…/tests/integration/fixtures/sample.html` (obscura supports `file://` URLs, so no network or local HTTP server is needed inside the container).
6. Tears the container and volumes down on exit.

The fixture exercises real defuddle behaviour: the suite asserts that article paragraphs, bold/italic, links, and `<h2>` are preserved while site banners, sidebars, footers, and inline `<script>` content are stripped.

### End-to-end tests (Playwright)

Drives a real FreshRSS instance (once per tested version) through a browser with [Playwright](https://playwright.dev) 1.64.1. Everything runs in Docker (`tests/e2e/docker-compose.e2e.yml`):

- **freshrss** — FreshRSS + Node.js with this repository mounted read-only as the extension. obscura and defuddle are *not* pre-installed: the extension downloads/installs them itself.
- **fixtures** — a small stateful HTTP server (`tests/e2e/fixtures/server.py`) serving RSS feeds whose items the tests publish on demand, article pages (static, JavaScript-rendered, delayed content, a hit counter) and the obscura release tarball.
- **playwright** — the official `mcr.microsoft.com/playwright` image running `tests/e2e/tests/*.spec.ts`.

```bash
bash scripts/run-e2e-tests.sh                 # whole suite
bash scripts/run-e2e-tests.sh -g "re-fetch"   # extra args go to `playwright test`
E2E_KEEP=1 bash scripts/run-e2e-tests.sh      # keep the stack up (FreshRSS on http://localhost:18080, admin / e2e-password)
FRESHRSS_VERSIONS=1.30.1 bash scripts/run-e2e-tests.sh   # test a single version
```

#### Tested FreshRSS versions

Both `run-integration-tests.sh` and `run-e2e-tests.sh` test, by default, the `freshrss_min_version` read from `metadata.json` **and** the newest pinned version (`FRESHRSS_LATEST_TESTED_VERSION` in `scripts/freshrss-versions.sh`). Raising the minimum in `metadata.json` therefore changes what is tested automatically. Override with `FRESHRSS_VERSIONS="1.29.0 1.30.1"`.

Covered: enabling the extension, the configure page (global and per-feed settings persistence and independence, "Update defuddle now", "Redownload obscura binary", success/error notifications, minimum-version warnings, no horizontal overflow at phone width), full-text replacement on refresh (boilerplate stripping, JavaScript rendering, relative URL resolution, per-feed `wait`/`selector` options capturing late content that is otherwise missed, fallback to the feed summary on fetch errors), the re-fetch button (success, persistence, error reporting), the private-network opt-in, and refetch endpoint access control. The HTML report is written to `tests/e2e/playwright-report/`.

## Data files

Runtime files are stored under `<DATA_PATH>/fulltextcontent/`:

```
<DATA_PATH>/fulltextcontent/
├── bin/obscura          # downloaded on first use
├── node_modules/        # defuddle and its dependencies
└── version.json         # defuddle version state
```

## License

MIT
