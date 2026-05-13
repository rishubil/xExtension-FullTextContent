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

- **FreshRSS** (self-hosted).
- **Linux x86_64** host (for the obscura binary; aarch64-linux builds are not available upstream yet).
- **Node.js + npm** — required to run defuddle. See [Docker setup](#docker-setup) below.

## Installation

1. Copy this directory into your FreshRSS `extensions/` folder as `xExtension-FullTextContent`.
2. Enable the extension in FreshRSS under **Administration → Extensions**.
3. Install Node.js (see below), then open the extension's configuration page to download obscura and defuddle.

## Docker setup

The FreshRSS official Docker image does not include Node.js. Use the provided entrypoint wrapper to install it automatically on container start.

### Option A — entrypoint override (recommended)

```yaml
# docker-compose.yml
services:
  freshrss:
    image: freshrss/freshrss:latest
    entrypoint: /var/www/FreshRSS/extensions/xExtension-FullTextContent/scripts/entrypoint.sh
    command: ["apache2-foreground"]
    volumes:
      - ./extensions:/var/www/FreshRSS/extensions
      - ./data:/var/www/FreshRSS/data
```

The `entrypoint.sh` script installs node/npm (idempotent: skips if already present) then delegates to the original `/entrypoint.sh`.

### Option B — custom Dockerfile

```dockerfile
FROM freshrss/freshrss:latest
RUN apk add --no-cache nodejs npm
```

## Configuration

Open **Administration → Extensions → Full Text Content → Configure**.

### Global settings

| Field | Default | Description |
|---|---|---|
| Node.js binary path | `node` | Path to the `node` executable inside the container. |
| Obscura download URL template | upstream GitHub URL | Template for downloading the obscura binary. `{arch}` is replaced with the host architecture (e.g. `x86_64-linux`). |
| Obscura binary path override | *(auto)* | Override the resolved binary path. Leave blank to use the auto-downloaded binary. |
| Defuddle version | `latest` | `latest` always upgrades to the newest npm release. Any other value (e.g. `0.6.2`) pins to that exact version. |
| Defuddle update check interval | `168` hours | How often to check for a newer defuddle version when set to `latest`. Ignored when pinned. |
| Fetch timeout | `30` seconds | Maximum time allowed for obscura to fetch and render a URL. |

### Status & actions

- **Update defuddle now** — installs or upgrades defuddle immediately regardless of the check interval.
- **Redownload obscura binary** — forces a fresh download of the obscura binary using the configured URL.

### Per-feed opt-in

Full-text fetching is disabled by default for all feeds. Toggle it per feed in the extension's configuration page.

## Testing

### Unit tests

Runs standalone (no FreshRSS or Docker required). PHP 8.1+ is the only dependency.

```bash
php scripts/test.php
```

### Integration tests

Runs the **real** obscura binary and the **real** defuddle npm package (no mocks) inside a custom Docker image built on top of `freshrss/freshrss:latest`. The runner initialises FreshRSS via its CLI, then exercises both the extraction pipeline and the FreshRSS extension lifecycle end-to-end.

**Requirements:** Docker daemon only. No host-side `curl`, `npm`, or `tar` needed — all dependencies are installed inside the image at build time.

```bash
# Default socket path
bash scripts/run-integration-tests.sh

# Custom socket (e.g. rootless Docker or a sandboxed environment)
DOCKER_HOST=unix:///tmp/docker.sock bash scripts/run-integration-tests.sh
```

The script:

1. **Builds the test image** (`tests/integration/Dockerfile`) — installs Node.js, downloads obscura, and installs defuddle at the pinned version (`0.18.1`). Docker layer caching makes subsequent runs fast.
2. Starts a temporary container from the built image.
3. Initialises FreshRSS via `cli/do-install.php` + `cli/create-user.php`.
4. Runs both test suites against `file:///…/tests/integration/fixtures/sample.html` (obscura supports `file://` URLs, so no network or local HTTP server is needed inside the container).
5. Tears the container and volumes down on exit.

The fixture exercises real defuddle behaviour: the suite asserts that article paragraphs, bold/italic, links, and `<h2>` are preserved while site banners, sidebars, footers, and inline `<script>` content are stripped.

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
