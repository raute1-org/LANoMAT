#!/usr/bin/env python3
"""Stand-in LANoMAT server for the launcher shell smoke test (standard library only).

Serves exactly the endpoints the launcher shell talks to, so `smoke-shell.sh` can drive the real
Tauri app against a deterministic server and assert the requests it made:

    POST /api/mcp/device                     RFC 8628 device start
    POST /api/mcp/token                      first poll pending, then the token
    GET  /api/v1/me                          the paired user
    GET  /api/v1/recipes                     one UT2004 card plus a decoy the launcher filters out
    POST /api/v1/recipes/<slug>/plan         a plan whose two files are missing (fails fast)
    GET  /launcher                           the public page the footer's update check reads
    GET  /__health                           200 when the socket is up

Every request is appended to the log file (and stdout) as one line:

    REQ GET /api/v1/recipes auth=Bearer <token> body=...
    RES GET /api/v1/recipes 200

The log is the evidence the smoke test greps; it is a stub, so the fake bearer token in it is not a
secret. Run standalone for debugging:

    python3 -I app/tests/fixtures/standin_launcher.py --port 8093
"""
import argparse
import json
import os
import socket
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

RECIPE_SLUG = "unreal-tournament-2004"
DECOY_SLUG = "not-yet-published"
ISSUED_TOKEN = "lmcp_smoke_issued"
FIRST_POLL_PENDING = True

RECIPES = {
    "data": [
        {
            "slug": RECIPE_SLUG,
            "title": "Unreal Tournament 2004",
            "summary": "Flat-Pilot fuer den Smoke-Test",
            "version": "3374",
            "status": "published",
            "platforms": ["linux", "windows"],
        },
        {
            "slug": DECOY_SLUG,
            "title": "Not Yet Published",
            "version": "1",
            "status": "published",
            "platforms": ["linux"],
        },
    ]
}

ME = {
    "user": {"id": 1, "name": "Testspieler", "role": "participant", "is_orga": False},
    "server": {"name": "Smoke LAN", "url": "", "version": "smoke"},
    "modules": [],
}


def plan_for(slug):
    """Both requirements are missing, so the installer reports the labels and stops.

    That keeps the deep-link test quick: the shell still fetches the plan (proof that the UI found
    the UT2004 card), applies the failure and keeps running.
    """
    return {
        "recipe": {
            "slug": slug,
            "title": "Unreal Tournament 2004",
            "version": "3374",
        },
        "os": "linux",
        "target_dir": "~/lanomat-smoke/ut2004",
        "downloads_dir": None,
        "files": [
            {
                "requirement": "iso",
                "status": "missing",
                "label": "UT2004 DVD (ISO)",
                "required": True,
            },
            {
                "requirement": "patch",
                "status": "missing",
                "label": "OldUnreal 3374 Patch",
                "required": True,
            },
        ],
        "missing": ["UT2004 DVD (ISO)", "OldUnreal 3374 Patch"],
        "steps": [],
        "avatars": [],
    }


class State:
    def __init__(self, log_path):
        self.log_path = log_path
        self.lock = threading.Lock()
        self.polls = 0
        self.port = 0

    def log(self, line):
        with self.lock:
            with open(self.log_path, "a", encoding="utf-8") as handle:
                handle.write(line + "\n")
        print(line, flush=True)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "lanomat-standin-smoke/1"

    # The stand-in logs its own, greppable lines.
    def log_message(self, fmt, *args):
        pass

    def do_GET(self):
        self.handle_any("GET")

    def do_POST(self):
        self.handle_any("POST")

    def do_DELETE(self):
        self.handle_any("DELETE")

    def handle_any(self, method):
        state = self.server.state
        path = urlsplit(self.path).path
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        body = raw.decode("utf-8", "replace")
        auth = self.headers.get("Authorization") or "-"
        suffix = f" body={body}" if body else ""
        state.log(f"REQ {method} {path} auth={auth}{suffix}")

        try:
            status = self.route(method, path, body)
        except Exception as error:  # a bug in the stub must not hang the app
            state.log(f"ERR {method} {path} {error}")
            status = self.json(500, {"error": "stub_error", "message": str(error)})

        state.log(f"RES {method} {path} {status}")

    def route(self, method, path, body):
        state = self.server.state

        if method == "GET" and path == "/__health":
            return self.text(200, "ok")

        if method == "POST" and path == "/api/mcp/device":
            return self.json(
                200,
                {
                    "device_code": "smoke-device-code",
                    "user_code": "ABCD-EFGH",
                    "verification_uri": f"http://127.0.0.1:{state.port}/approve",
                    "verification_uri_complete": (
                        f"http://127.0.0.1:{state.port}/approve?code=ABCD-EFGH"
                    ),
                    "expires_in": 300,
                    "interval": 1,
                },
            )

        if method == "POST" and path == "/api/mcp/token":
            with state.lock:
                state.polls += 1
                first = state.polls == 1

            if first and FIRST_POLL_PENDING:
                return self.json(400, {"error": "authorization_pending"})

            return self.json(
                200,
                {
                    "access_token": ISSUED_TOKEN,
                    "token_type": "Bearer",
                    "expires_in": 3600,
                },
            )

        if method == "GET" and path == "/api/v1/me":
            return self.json(200, ME)

        if method == "GET" and path == "/api/v1/recipes":
            return self.json(200, RECIPES)

        # The shell's footer asks the public /launcher page whether a newer build exists. A page
        # without a release link means "no update hint", which is all the smoke needs.
        if method == "GET" and path == "/launcher":
            return self.html(
                200,
                "<!doctype html><html lang='de'><head><title>Launcher</title></head>"
                "<body><h1>LANoMAT Launcher</h1></body></html>",
            )

        prefix = "/api/v1/recipes/"
        suffix = "/plan"

        if method == "POST" and path.startswith(prefix) and path.endswith(suffix):
            slug = path[len(prefix) : -len(suffix)]

            if slug == RECIPE_SLUG:
                return self.json(200, plan_for(slug))

            return self.json(404, {"error": "not_found", "message": f"unknown recipe {slug}"})

        return self.json(404, {"error": "not_found", "message": f"{method} {path}"})

    def json(self, status, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        return status

    def text(self, status, payload):
        body = payload.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        return status

    def html(self, status, payload):
        body = payload.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        return status


def free_port():
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        return probe.getsockname()[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=0, help="0 picks a free port")
    parser.add_argument("--log", default="/tmp/lanomat-launcher-standin.log")
    options = parser.parse_args()

    port = options.port or free_port()
    state = State(options.log)

    with open(state.log_path, "w", encoding="utf-8"):
        pass  # truncate per run, so a grep never sees a previous run

    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    server.state = state
    state.port = server.server_address[1]

    print(f"READY http://127.0.0.1:{state.port}", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
