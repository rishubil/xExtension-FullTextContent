FROM python:3.13-slim

ARG OBSCURA_VERSION=v0.2.4
ENV OBSCURA_VERSION=${OBSCURA_VERSION}

# Serve the real obscura release tarball locally so the extension's download
# code path runs against a deterministic, network-free URL during the tests.
RUN python3 - <<'PY'
import os, platform, urllib.request
arch = {"x86_64": "x86_64-linux", "aarch64": "aarch64-linux"}[platform.machine()]
os.makedirs("/srv/obscura", exist_ok=True)
for variant in ("", "-stealth"):
    name = f"obscura-{arch}{variant}.tar.gz"
    url = f"https://github.com/h4ckf0r0day/obscura/releases/download/{os.environ['OBSCURA_VERSION']}/{name}"
    urllib.request.urlretrieve(url, f"/srv/obscura/{name}")

# A stub "obscura" that reports an unsupported old version, to exercise the
# minimum-version warning on the configure page.
import io, tarfile
stub = ("#!/bin/sh\n# stub obscura used by the e2e suite" + " " * 120 + "\n"
        "[ \"$1\" = --version ] && { echo 'obscura 0.1.0'; exit 0; }\nexit 1\n").encode()
with tarfile.open(f"/srv/obscura/obscura-old-{arch}.tar.gz", "w:gz") as tar:
    info = tarfile.TarInfo("obscura")
    info.size = len(stub)
    info.mode = 0o755
    tar.addfile(info, io.BytesIO(stub))
PY

COPY server.py /srv/fixtures/server.py
COPY articles /srv/fixtures/articles
COPY static /srv/fixtures/static

ENV PYTHONUNBUFFERED=1
EXPOSE 8080
CMD ["python3", "/srv/fixtures/server.py"]
