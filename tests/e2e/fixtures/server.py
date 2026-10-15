"""Stateful fixture server for the Playwright end-to-end suite.

Serves RSS feeds whose items are published on demand by the tests, the
article pages those items link to, and the obscura release tarball so the
extension's "download obscura" code path can run without GitHub access.

Control API (JSON bodies):
  POST /_control/publish  {"feed": "f", "id": "x", "article": "standard"}
  POST /_control/fail     {"id": "x", "fail": true}
  GET  /_control/hits?id=x  -> {"hits": n}
"""

import json
import os
import threading
from email.utils import formatdate
from html import escape
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from pathlib import Path
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parent
ARTICLES = ROOT / "articles"
STATIC = ROOT / "static"
OBSCURA_DIR = Path(os.environ.get("OBSCURA_DIR", "/srv/obscura"))
BASE_URL = os.environ.get("BASE_URL", "http://fixtures:8080").rstrip("/")

lock = threading.Lock()
feeds: dict[str, list[dict]] = {}
hits: dict[str, int] = {}
failing: set[str] = set()


def render_feed(name: str) -> bytes:
    with lock:
        items = list(feeds.get(name, []))
    parts = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<rss version="2.0"><channel>',
        f"<title>E2E feed {escape(name)}</title>",
        f"<link>{BASE_URL}/</link>",
        "<description>Fixture feed</description>",
    ]
    for item in reversed(items):
        parts.append(
            "<item>"
            f"<title>{escape(item['title'])}</title>"
            f"<link>{escape(item['link'])}</link>"
            f'<guid isPermaLink="false">{escape(item["id"])}</guid>'
            f"<pubDate>{item['date']}</pubDate>"
            f"<description>RSS_SUMMARY_{escape(item['id'])}</description>"
            "</item>"
        )
    parts.append("</channel></rss>")
    return "\n".join(parts).encode()


def render_counter(item_id: str, count: int) -> bytes:
    body = (ARTICLES / "counter.html").read_text()
    return body.replace("{{COUNT}}", str(count)).replace("{{ID}}", escape(item_id)).encode()


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # quieter logs
        if os.environ.get("FIXTURES_VERBOSE"):
            super().log_message(fmt, *args)

    def send(self, status: int, body: bytes, ctype: str) -> None:
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def send_json(self, data, status: int = 200) -> None:
        self.send(status, json.dumps(data).encode(), "application/json")

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        url = urlparse(self.path)
        query = parse_qs(url.query)
        path = url.path

        if path == "/health":
            return self.send(200, b"ok", "text/plain")

        if path.startswith("/feeds/") and path.endswith(".xml"):
            return self.send(200, render_feed(path[len("/feeds/"):-len(".xml")]), "application/rss+xml")

        if path.startswith("/articles/"):
            article = path[len("/articles/"):]
            item_id = query.get("id", [""])[0]
            with lock:
                hits[item_id] = hits.get(item_id, 0) + 1
                count = hits[item_id]
                fail = item_id in failing
            if fail:
                return self.send(500, b"", "text/html")
            if article == "counter":
                return self.send(200, render_counter(item_id, count), "text/html; charset=utf-8")
            file = ARTICLES / f"{article}.html"
            if file.is_file():
                return self.send(200, file.read_bytes(), "text/html; charset=utf-8")
            return self.send(404, b"not found", "text/plain")

        if path.startswith("/static/"):
            file = STATIC / Path(path).name
            if file.is_file():
                ctype = "image/png" if file.suffix == ".png" else "application/octet-stream"
                return self.send(200, file.read_bytes(), ctype)
            return self.send(404, b"not found", "text/plain")

        if path.startswith("/obscura/"):
            file = OBSCURA_DIR / Path(path).name
            if file.is_file():
                return self.send(200, file.read_bytes(), "application/gzip")
            return self.send(404, b"not found", "text/plain")

        if path == "/_control/hits":
            item_id = query.get("id", [""])[0]
            with lock:
                return self.send_json({"hits": hits.get(item_id, 0)})

        return self.send(404, b"not found", "text/plain")

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        data = json.loads(self.rfile.read(length) or b"{}")
        path = urlparse(self.path).path

        if path == "/_control/publish":
            item_id = data["id"]
            article = data.get("article", "standard")
            item = {
                "id": item_id,
                "title": data.get("title", f"Article {item_id}"),
                "link": f"{BASE_URL}/articles/{article}?id={item_id}",
                "date": formatdate(usegmt=True),
            }
            with lock:
                feeds.setdefault(data["feed"], []).append(item)
            return self.send_json(item)

        if path == "/_control/fail":
            with lock:
                if data.get("fail", True):
                    failing.add(data["id"])
                else:
                    failing.discard(data["id"])
            return self.send_json({"ok": True})

        return self.send_json({"error": "unknown"}, 404)


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()
