#!/usr/bin/env python3
"""Stand-in for the LANoMAT native voice API (docs/infra/native-voice-client-api.md), for the
headless smoke test of the desktop app. Loopback only, standard library only, no real data.

It answers the device-code flow and the bearer API like the real server and mints LiveKit join
tokens (HS256 JWT) for a LiveKit server started with `--dev`. The LiveKit key pair comes from the
environment only (LIVEKIT_API_KEY, LIVEKIT_API_SECRET), never from a file in the repository.

    standin_server.py --port 8099 --livekit-url ws://127.0.0.1:7880 \
        --peer-token-file /tmp/peer-token [--approve-after 4] [--deny]

A device request is approved `--approve-after` seconds after it was made (or denied with --deny,
or never with --approve-after -1). A second, pre-approved user ("Peer") exists for the peer CLI;
its token is written to --peer-token-file at start.
"""
import argparse
import base64
import hashlib
import hmac
import json
import os
import secrets
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOMS = [
    {"id": 1, "name": "Lobby", "description": "Allgemeiner Sprachraum", "kind": "general"},
    {"id": 2, "name": "Turnierbüro", "description": "Absprachen mit der Orga", "kind": "general"},
    {"id": 3, "name": "Match: Rot gegen Blau", "description": "Finale, Runde 2", "kind": "match"},
]
AUDIO = {
    "bitrate": 24000, "dtx": True, "red": True, "echo_cancellation": True,
    "noise_suppression": True, "auto_gain_control": True, "jitter_buffer_target_ms": None,
}


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def mint(key: str, secret: str, identity: str, name: str, room: str, can_publish: bool) -> str:
    now = int(time.time())
    header = {"alg": "HS256", "typ": "JWT"}
    claims = {
        "iss": key, "sub": identity, "name": name, "nbf": now - 5, "exp": now + 600,
        "video": {"room": room, "roomJoin": True, "canPublish": can_publish,
                  "canSubscribe": True, "canPublishData": True},
    }
    signing = b64url(json.dumps(header).encode()) + "." + b64url(json.dumps(claims).encode())
    sig = hmac.new(secret.encode(), signing.encode(), hashlib.sha256).digest()
    return signing + "." + b64url(sig)


class State:
    def __init__(self, args):
        self.args = args
        self.lock = threading.Lock()
        self.requests = {}  # device_code -> requested_at
        self.tokens = {}  # access token -> (id, name)
        self.peer_token = "lvc_" + secrets.token_hex(24)
        self.tokens[self.peer_token] = (2, "Peer")
        self.key = os.environ["LIVEKIT_API_KEY"]
        self.secret = os.environ["LIVEKIT_API_SECRET"]


def make_handler(state: State):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, fmt, *args):  # quiet, one line per request on stderr
            sys.stderr.write("standin: " + (fmt % args) + "\n")

        def send(self, status, body=None, headers=None):
            raw = b"" if body is None else json.dumps(body).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            for k, v in (headers or {}).items():
                self.send_header(k, v)
            self.end_headers()
            self.wfile.write(raw)

        def body(self):
            n = int(self.headers.get("Content-Length") or 0)
            try:
                return json.loads(self.rfile.read(n) or b"{}")
            except ValueError:
                return {}

        def user(self):
            auth = self.headers.get("Authorization", "")
            if auth.startswith("Bearer "):
                return state.tokens.get(auth[7:])
            return None

        def do_POST(self):
            path = self.path.split("?")[0]
            payload = self.body()  # always drain the body (keep-alive connections)
            if path == "/api/voice-client/device":
                code = "dc_" + secrets.token_hex(16)
                with state.lock:
                    state.requests[code] = time.time()
                base = f"http://127.0.0.1:{state.args.port}"
                return self.send(200, {
                    "device_code": code, "user_code": "ABCD-EFGH",
                    "verification_url": f"{base}/talk/connect",
                    "verification_url_complete": f"{base}/talk/connect?code=ABCD-EFGH",
                    "expires_in": 600, "interval": 1,
                })
            if path == "/api/voice-client/token":
                code = payload.get("device_code")
                with state.lock:
                    asked = state.requests.get(code)
                    if asked is None:
                        return self.send(400, {"error": "invalid_grant"})
                    after = state.args.approve_after
                    if state.args.deny and after >= 0 and time.time() - asked >= after:
                        del state.requests[code]
                        return self.send(400, {"error": "access_denied"})
                    if after < 0 or time.time() - asked < after:
                        return self.send(400, {"error": "authorization_pending"})
                    del state.requests[code]
                    token = "lvc_" + secrets.token_hex(24)
                    state.tokens[token] = (1, "Tester")
                return self.send(200, {"access_token": token, "token_type": "Bearer", "expires_in": 7776000})
            if path.startswith("/api/voice-client/v1/rooms/") and path.endswith("/token"):
                user = self.user()
                if not user:
                    return self.send(401, {"message": "Unauthenticated."}, {"WWW-Authenticate": "Bearer"})
                try:
                    room_id = int(path.split("/")[-2])
                except ValueError:
                    return self.send(404, {"message": "Not found."})
                if not any(r["id"] == room_id for r in ROOMS):
                    return self.send(404, {"message": "Not found."})
                uid, name = user
                muted = state.args.moderator_muted == uid
                jwt = mint(state.key, state.secret, f"u{uid}", name, f"room-{room_id}", not muted)
                return self.send(200, {
                    "url": state.args.livekit_url, "token": jwt, "identity": f"u{uid}",
                    "muted": muted, "audio": AUDIO,
                })
            self.send(404, {"message": "Not found."})

        def do_GET(self):
            path = self.path.split("?")[0]
            if not path.startswith("/api/voice-client/v1/"):
                return self.send(404, {"message": "Not found."})
            user = self.user()
            if not user:
                return self.send(401, {"message": "Unauthenticated."}, {"WWW-Authenticate": "Bearer"})
            uid, name = user
            route = path[len("/api/voice-client/v1/"):]
            if route == "me":
                return self.send(200, {"id": uid, "name": name, "role": "participant", "identity": f"u{uid}"})
            if route == "config":
                return self.send(200, {"api_version": 1, "livekit_url": state.args.livekit_url,
                                       "voice_ready": True, "audio": AUDIO})
            if route == "rooms":
                if state.args.no_rooms:
                    return self.send(200, {"rooms": []})
                return self.send(200, {"rooms": ROOMS})
            self.send(404, {"message": "Not found."})

    return Handler


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--port", type=int, default=8099)
    p.add_argument("--livekit-url", default="ws://127.0.0.1:7880")
    p.add_argument("--peer-token-file", required=True)
    p.add_argument("--approve-after", type=float, default=4.0)
    p.add_argument("--deny", action="store_true")
    p.add_argument("--no-rooms", action="store_true")
    p.add_argument("--moderator-muted", type=int, default=0, help="user id that gets muted:true")
    args = p.parse_args()
    state = State(args)
    fd = os.open(args.peer_token_file, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as f:
        f.write(state.peer_token)
    server = ThreadingHTTPServer(("127.0.0.1", args.port), make_handler(state))
    print(f"standin: listening on 127.0.0.1:{args.port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
