"""Feature endpoints of the LANoMAT native client API for the headless command self-test:
config (modules, realtime), events, the "now" feed, tournaments and matches, game servers, polls,
catering, files (signed download with Range, tus upload with a forced mid-chunk drop),
notifications and text chat, plus a small Pusher-protocol WebSocket server (HMAC-signed private
channel auth, like Reverb). Loopback only, standard library only, no real data.

Plugged into standin_server.py (`features.handle(...)`); see docs/infra/native-voice-client-api.md
for the shapes. Every refusal carries the documented body.
"""
import base64
import hashlib
import hmac
import json
import re
import secrets
import socket
import struct
import sys
import threading
import time
from datetime import datetime, timedelta, timezone

EVENT = "lan-party-2026"
APP_KEY = "standin-app-key"  # the public Reverb app key (not a secret)
FILE_SIZE = 6 * 1024 * 1024
DL_CHUNK = 64 * 1024


def iso(dt):
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def log(msg):
    sys.stderr.write("standin: " + msg + "\n")
    sys.stderr.flush()


def file_bytes(n=FILE_SIZE):
    return bytes((i % 251) for i in range(n))


class PusherServer:
    """Pusher protocol 7 over a hand-rolled RFC 6455 server. Private channels need
    `auth = <key>:<hmac_sha256(secret, "<socket_id>:<channel>")>`; the secret stays in memory."""

    def __init__(self, port):
        self.secret = secrets.token_hex(16).encode()
        self.port = port
        self.lock = threading.Lock()
        self.subs = {}  # channel -> set(conn)
        self.listener = socket.socket()
        self.listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.listener.bind(("127.0.0.1", port))
        self.listener.listen(8)
        threading.Thread(target=self._accept, daemon=True).start()

    def sign(self, socket_id, channel):
        mac = hmac.new(self.secret, f"{socket_id}:{channel}".encode(), hashlib.sha256)
        return f"{APP_KEY}:{mac.hexdigest()}"

    def publish(self, channel, event, data):
        frame = self._frame(json.dumps({"event": event, "channel": channel, "data": json.dumps(data)}))
        with self.lock:
            conns = list(self.subs.get(channel, ()))
        for c in conns:
            try:
                c.sendall(frame)
            except OSError:
                pass
        log(f"pusher publish {event} to {len(conns)} subscriber(s) of {channel}")

    @staticmethod
    def _frame(text, opcode=1):
        payload = text.encode() if isinstance(text, str) else text
        n = len(payload)
        head = bytes([0x80 | opcode])
        if n < 126:
            head += bytes([n])
        elif n < 65536:
            head += bytes([126]) + struct.pack(">H", n)
        else:
            head += bytes([127]) + struct.pack(">Q", n)
        return head + payload

    def _accept(self):
        while True:
            try:
                conn, _ = self.listener.accept()
            except OSError:
                return
            threading.Thread(target=self._serve, args=(conn,), daemon=True).start()

    @staticmethod
    def _read(conn, n):
        buf = b""
        while len(buf) < n:
            chunk = conn.recv(n - len(buf))
            if not chunk:
                raise ConnectionError
            buf += chunk
        return buf

    def _serve(self, conn):
        socket_id = f"{secrets.randbelow(10**6)}.{secrets.randbelow(10**6)}"
        mine = set()
        try:
            head = b""
            while b"\r\n\r\n" not in head:
                chunk = conn.recv(4096)
                if not chunk:
                    return
                head += chunk
            m = re.search(rb"Sec-WebSocket-Key:\s*(\S+)", head, re.I)
            if not m or f"/app/{APP_KEY}".encode() not in head.split(b"\r\n")[0]:
                conn.sendall(b"HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n")
                return
            accept = base64.b64encode(
                hashlib.sha1(m.group(1) + b"258EAFA5-E914-47DA-95CA-C5AB0DC85B11").digest()
            )
            conn.sendall(
                b"HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                b"Sec-WebSocket-Accept: " + accept + b"\r\n\r\n"
            )
            conn.sendall(self._frame(json.dumps({
                "event": "pusher:connection_established",
                "data": json.dumps({"socket_id": socket_id, "activity_timeout": 30}),
            })))
            log(f"pusher connection {socket_id}")
            while True:
                b0, b1 = self._read(conn, 2)
                opcode, masked, n = b0 & 0x0F, b1 & 0x80, b1 & 0x7F
                if n == 126:
                    n = struct.unpack(">H", self._read(conn, 2))[0]
                elif n == 127:
                    n = struct.unpack(">Q", self._read(conn, 8))[0]
                mask = self._read(conn, 4) if masked else b"\0\0\0\0"
                data = bytearray(self._read(conn, n))
                for i in range(n):
                    data[i] ^= mask[i % 4]
                if opcode == 8:
                    return
                if opcode == 9:
                    conn.sendall(self._frame(bytes(data), 10))
                    continue
                if opcode != 1:
                    continue
                msg = json.loads(data.decode())
                event = msg.get("event")
                if event == "pusher:ping":
                    conn.sendall(self._frame(json.dumps({"event": "pusher:pong", "data": "{}"})))
                elif event == "pusher:subscribe":
                    d = msg.get("data") or {}
                    ch, auth = d.get("channel", ""), d.get("auth", "")
                    if not hmac.compare_digest(auth, self.sign(socket_id, ch)):
                        log(f"pusher subscribe REFUSED for {ch} (bad signature)")
                        conn.sendall(self._frame(json.dumps({
                            "event": "pusher:error",
                            "data": json.dumps({"code": 4009, "message": "Connection is unauthorized"}),
                        })))
                        continue
                    with self.lock:
                        self.subs.setdefault(ch, set()).add(conn)
                    mine.add(ch)
                    conn.sendall(self._frame(json.dumps({
                        "event": "pusher_internal:subscription_succeeded", "channel": ch, "data": "{}",
                    })))
                    log(f"pusher subscribed {ch} (signature verified for {socket_id})")
        except (ConnectionError, OSError, ValueError):
            pass
        finally:
            with self.lock:
                for ch in mine:
                    self.subs.get(ch, set()).discard(conn)
            try:
                conn.close()
            except OSError:
                pass


class Features:
    def __init__(self, args, base_url):
        self.args = args
        self.base = base_url
        self.lock = threading.Lock()
        self.pusher = PusherServer(args.pusher_port)
        self.messages = {1: [], 2: [], 3: []}
        self.next_msg = 100
        self.tickets = set()
        self.uploads = {}  # id -> {"data": bytearray, "length": int, "meta": dict}
        self.patches = 0
        self.dropped = False
        self.content = file_bytes()
        self.notifications = [
            {"id": "00000000-0000-4000-8000-000000000001", "category": "match", "title": "Dein Match startet",
             "body": "Gleich geht es los.", "read": False, "read_at": None,
             "created_at": iso(datetime.now(timezone.utc) - timedelta(minutes=2))},
        ]

    # ---- helpers ----

    @staticmethod
    def _msg_dto(m):
        return m

    def _server_dto(self):
        return {
            "id": 21, "title": "CS2 Cup Runde 2", "game": "Counter-Strike 2", "matchLabel": "CS2 Cup, Runde 2",
            "description": None, "status": "ready", "players": 4, "maxPlayers": 10, "map": "de_mirage",
            "checkedAt": iso(datetime.now(timezone.utc)), "address": "203.0.113.5", "port": 27015,
            "connectString": "steam://connect/203.0.113.5:27015", "connectIsLink": True,
            "installHint": {"steamUrl": "steam://store/730", "shareUrl": None, "versionNote": None},
            "joinUrl": "steam://connect/203.0.113.5:27015", "copyText": "203.0.113.5:27015",
        }

    def _match_dto(self):
        return {
            "id": 88, "phase": "active", "status": "ready",
            "tournament": {"id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "status": "live"},
            "round": 2, "bracket": "winners", "scheduledAt": None, "warmupStartedAt": None,
            "updatedAt": iso(datetime.now(timezone.utc)),
            "me": {"entryId": 31, "name": "Team Rot"}, "opponent": {"entryId": 35, "name": "Team Blau"},
            "score": None, "won": None, "lockVersion": 0, "pendingReport": None, "voiceRoomId": 3,
            "server": self._server_dto(),
            "can": {"report": True, "confirm": False, "dispute": False, "withdrawReport": False, "checkIn": False},
        }

    def _poll_dto(self):
        return {
            "id": 3, "event_slug": EVENT, "question": "Welche Map?", "kind": "standard", "status": "open",
            "is_open": True, "closes_at": iso(datetime.now(timezone.utc) + timedelta(minutes=5)),
            "total_votes": 3,
            "options": [{"id": 12, "label": "Dust", "count": 2, "percent": 66.7},
                        {"id": 13, "label": "Mirage", "count": 1, "percent": 33.3}],
            "my_vote_option_id": None,
        }

    def _file_dto(self):
        return {"id": 21, "name": "maps.zip", "size_bytes": len(self.content), "uploader_name": "Ada",
                "visibility": "approved", "created_at": iso(datetime.now(timezone.utc)),
                "mine": False, "can_download": True, "can_delete": False}

    def handle(self, h, method, path, query, payload, user):
        """Returns True when the request was answered."""
        uid, name = user
        route = path[len("/api/voice-client/v1/"):]
        send = h.send

        if method == "GET" and route == "config":
            send(200, {
                "api_version": 1, "livekit_url": None, "voice_ready": False, "audio": {},
                "modules": {"game_servers": True, "catering": True, "voting": True, "files": True,
                            "tournaments": True, "schedule": False, "lfg": False},
                "realtime": {"driver": "reverb", "key": APP_KEY, "host": "127.0.0.1",
                             "port": self.args.pusher_port, "scheme": "http",
                             "auth_endpoint": "/api/voice-client/v1/broadcasting/auth"},
            })
            return True
        if method == "GET" and route == "events":
            send(200, {"current_event_slug": EVENT, "events": [{
                "id": 7, "slug": EVENT, "name": "LAN Party 2026", "status": "live",
                "starts_at": "2026-11-06T16:00:00+00:00", "ends_at": "2026-11-08T12:00:00+00:00",
                "location": "Stadthalle", "current": True,
                "registration": {"status": "confirmed", "ticket_type": "standard", "paid": True, "checked_in": True},
            }]})
            return True
        m = re.fullmatch(r"events/([^/]+)/(.+)", route)
        if m and m.group(1) != EVENT:
            send(404, {"message": "Not Found"})
            return True
        if m:
            sub = m.group(2)
            if method == "GET" and sub == "now":
                at = iso(datetime.now(timezone.utc) + timedelta(minutes=5))
                send(200, {"cards": [
                    {"kind": "match", "id": "match:88", "state": "ready", "title": "Dein Match ist bereit",
                     "body": "gegen Team Blau", "at": None, "data": {"match": self._match_dto()}},
                    {"kind": "vote", "id": "vote:3", "state": "open", "title": "Abstimmung", "body": None,
                     "at": at, "data": {"pollId": 3, "question": "Welche Map?", "closesAt": at,
                                         "options": [{"id": 12, "label": "Dust"}, {"id": 13, "label": "Mirage"}]}},
                ], "more": []})
                return True
            if method == "GET" and sub == "tournaments":
                send(200, {"tournaments": [{
                    "id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "format": "single_elimination",
                    "status": "check_in", "startsAt": "2026-11-07T18:00:00+00:00", "entryCount": 12, "maxEntries": 16,
                    "teamSize": 1, "myEntry": {"id": 31, "status": "registered", "canWithdraw": True, "isOwner": True},
                    "can": {"checkIn": True, "withdraw": True},
                    "registration": {"required": False, "open": False, "url": None}}]})
                return True
            if method == "GET" and sub == "my-matches":
                send(200, {"matches": [self._match_dto()]})
                return True
            if method == "GET" and sub == "servers":
                send(200, {"servers": [self._server_dto()]})
                return True
            if method == "GET" and sub == "polls":
                p = self._poll_dto()
                send(200, {"event_slug": EVENT, "polls": [{k: p[k] for k in (
                    "id", "question", "kind", "status", "is_open", "closes_at", "total_votes", "my_vote_option_id")}]})
                return True
            if method == "GET" and sub == "catering":
                send(200, {"event_slug": EVENT, "registration_required": False,
                           "limits": {"items_per_user": 10, "my_open_items": 0},
                           "orders": [{"id": 4, "title": "Pizza", "status": "open", "is_open": True,
                                       "opens_at": None, "closes_at": None,
                                       "menu": [{"key": "pizza_margherita", "name": "Pizza Margherita", "price_cents": 850}],
                                       "my_items": [], "my_total_cents": 0}]})
                return True
            if method == "GET" and sub == "files":
                send(200, {"event_slug": EVENT, "files": [self._file_dto()],
                           "quota": {"used_bytes": 0, "cap_bytes": 10485760, "remaining_bytes": 10485760},
                           "upload": {"resumable": True, "max_file_bytes": 107374182400}})
                return True
            if method == "POST" and sub == "files/uploads/ticket":
                ticket = "ticket-" + secrets.token_hex(8)
                with self.lock:
                    self.tickets.add(ticket)
                send(200, {"endpoint": self.base + "/upload/", "ticket": ticket,
                           "chunkSize": self.args.tus_chunk, "expiresIn": 900,
                           "name": payload.get("name", "upload.bin"), "reservationId": 1})
                log(f"ticket issued for {payload.get('name')} ({payload.get('size')} bytes)")
                return True
            return False
        if method == "GET" and route == "servers":
            send(200, {"servers": []})
            return True
        if method == "GET" and route == "polls/3":
            send(200, self._poll_dto())
            return True
        if method == "POST" and route == "polls/3/vote":
            p = self._poll_dto()
            p["my_vote_option_id"] = payload.get("option_id")
            send(201, p)
            return True
        if method == "POST" and route == "tournaments/7/checkin":
            send(200, {"tournament": {"id": 7, "name": "CS2 Cup", "status": "check_in"}})
            return True
        if method == "POST" and route == "matches/88/report":
            if payload.get("score1") == payload.get("score2"):
                send(422, {"message": "Unentschieden ist nicht erlaubt.", "errors": {"score2": ["Unentschieden ist nicht erlaubt."]}})
            else:
                send(200, {"match": self._match_dto()})
            return True
        if method == "POST" and route == "matches/88/confirm":
            send(409, {"message": "Das Match hat sich geändert.", "error": "tournaments.errors.stale_match"})
            return True
        m = re.fullmatch(r"files/(\d+)/download", route)
        if method == "GET" and m:
            if m.group(1) != "21":
                send(404, {"message": "Not Found"})
            else:
                send(200, {"url": f"{self.base}/dl/maps.zip?expires=1&md5=sig&n=maps.zip", "name": "maps.zip",
                           "size_bytes": len(self.content), "expires_at": iso(datetime.now(timezone.utc) + timedelta(minutes=5))})
            return True
        if method == "GET" and route == "notifications/unread-count":
            send(200, {"unread": sum(1 for n in self.notifications if not n["read"])})
            return True
        if method == "GET" and route == "notifications":
            items = [n for n in self.notifications if not (query.get("unread") == "1" and n["read"])]
            send(200, {"notifications": items, "page": 1, "per_page": 20, "total": len(items), "has_more": False})
            return True
        m = re.fullmatch(r"notifications/([0-9a-f-]+)/read", route)
        if method == "POST" and m:
            for n in self.notifications:
                if n["id"] == m.group(1):
                    n["read"], n["read_at"] = True, iso(datetime.now(timezone.utc))
                    send(200, {"notification": n})
                    return True
            send(404, {"message": "Not Found"})
            return True
        if method == "GET" and route == "notification-preferences":
            send(200, {"preferences": {"match": True, "catering": True, "friends": True}})
            return True
        if method == "PUT" and route == "notification-preferences":
            send(200, {"preferences": {"match": True, "catering": True, "friends": True,
                                       **(payload.get("preferences") or {})}})
            return True
        m = re.fullmatch(r"rooms/(\d+)/messages", route)
        if m and int(m.group(1)) in self.messages:
            room = int(m.group(1))
            if method == "GET":
                send(200, {"messages": self.messages[room][-50:], "next_before": None})
                return True
            if method == "POST":
                body = (payload.get("body") or "").strip()
                if not body or len(body) > 500:
                    send(422, {"message": "Die Nachricht ist leer oder zu lang.", "errors": {"body": ["ungültig"]}})
                    return True
                with self.lock:
                    self.next_msg += 1
                    msg = {"id": self.next_msg, "channel_id": room, "body": body, "created_at": iso(datetime.now(timezone.utc)),
                           "author": {"id": uid, "name": name, "avatar_url": None}}
                    self.messages[room].append(msg)
                send(201, {"message": msg})
                self.pusher.publish(f"private-web-voice.channel.{room}", "message.posted", {"message": msg})
                return True
        if method == "POST" and route == "broadcasting/auth":
            ch, sid = payload.get("channel_name", ""), payload.get("socket_id", "")
            if not (re.fullmatch(r"private-web-voice\.channel\.[1-3]", ch) or ch == "private-web-voice.directory"):
                send(403, {"message": "Forbidden"})
                return True
            send(200, {"auth": self.pusher.sign(sid, ch)})
            log(f"broadcasting/auth signed {ch} for socket {sid}")
            return True
        return False

    # ---- raw file host paths: /dl/... and /upload/... (no bearer: the link is the credential) ----

    def handle_host(self, h, method, path, query):
        if path.startswith("/dl/") and method == "GET":
            return self._download(h)
        if path.startswith("/upload/"):
            return self._tus(h, method, path)
        return False

    def _download(self, h):
        total = len(self.content)
        rng = h.headers.get("Range")
        start = 0
        status = 200
        if rng:
            start = int(rng.split("=")[1].split("-")[0])
            status = 206
        log(f"download GET range={rng} auth={'yes' if h.headers.get('Authorization') else 'no'}")
        body = self.content[start:]
        h.send_response(status)
        h.send_header("Content-Type", "application/octet-stream")
        h.send_header("Content-Length", str(len(body)))
        h.send_header("Accept-Ranges", "bytes")
        if status == 206:
            h.send_header("Content-Range", f"bytes {start}-{total - 1}/{total}")
        h.end_headers()
        try:
            for i in range(0, len(body), DL_CHUNK):
                h.wfile.write(body[i:i + DL_CHUNK])
                h.wfile.flush()
                time.sleep(0.004)
        except OSError:
            pass
        return True

    def _tus(self, h, method, path):
        def reply(status, headers=None, body=b""):
            h.send_response(status)
            h.send_header("Tus-Resumable", "1.0.0")
            for k, v in (headers or {}).items():
                h.send_header(k, v)
            h.send_header("Content-Length", str(len(body)))
            h.end_headers()
            h.wfile.write(body)

        uid = path.rstrip("/").rsplit("/", 1)[-1]
        if method == "POST":
            md = {}
            for kv in (h.headers.get("Upload-Metadata") or "").split(","):
                if " " in kv.strip():
                    k, v = kv.strip().split(" ", 1)
                    md[k] = base64.b64decode(v).decode()
            ticket = md.get("ticket", "")
            with self.lock:
                ok = ticket in self.tickets
                self.tickets.discard(ticket)
            if not ok:
                reply(403, {"Content-Type": "application/json"}, b'{"code":"ticket_invalid"}')
                return True
            new_id = secrets.token_hex(6)
            self.uploads[new_id] = {"data": bytearray(), "length": int(h.headers["Upload-Length"]), "meta": md}
            log(f"tus create {new_id} length={self.uploads[new_id]['length']} filename={md.get('filename')}")
            reply(201, {"Location": f"/upload/{new_id}"})
            return True
        up = self.uploads.get(uid)
        if method == "HEAD":
            if not up:
                reply(404)
            else:
                log(f"tus head offset={len(up['data'])}")
                reply(200, {"Upload-Offset": str(len(up["data"])), "Upload-Length": str(up["length"])})
            return True
        if method == "DELETE":
            self.uploads.pop(uid, None)
            log(f"tus delete {uid}")
            reply(204)
            return True
        if method == "PATCH":
            n = int(h.headers.get("Content-Length") or 0)
            offset = int(h.headers.get("Upload-Offset") or -1)
            if not up:
                reply(404)
                return True
            with self.lock:
                idx = self.patches
                self.patches += 1
            if self.args.tus_drop_after > 0 and idx == 1 and not self.dropped:
                self.dropped = True
                part = h.rfile.read(min(n, self.args.tus_drop_after))
                up["data"] += part
                log(f"tus DROP: kept {len(part)} of {n} bytes of the chunk at offset {offset}, cutting the connection")
                h.close_connection = True
                try:
                    h.connection.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
                return True
            data = h.rfile.read(n)
            if offset != len(up["data"]):
                reply(409)
                return True
            up["data"] += data
            log(f"tus patch offset={offset} len={len(data)} -> {len(up['data'])}/{up['length']}")
            reply(204, {"Upload-Offset": str(len(up["data"]))})
            if len(up["data"]) == up["length"]:
                ok = bytes(up["data"]) == self.expected_upload
                log(f"tus complete {uid} bytes={len(up['data'])} matches_source={ok}")
            return True
        return False

    expected_upload = b""
