"""Stateful social demo for the UI smoke (used by standin_demo.py): the own profile with the
server's validation rules, linked accounts with a single-use link and a status flip after a delay,
friends (requests, suggestions, blocked) and the people list with the follow targets, all in the
shapes of docs/infra/native-voice-client-api.md ("People", "Account"). Everything is invented:
documentation addresses only, no real names, hosts or ids.

Hooks (loopback, no auth, see Demo.handle_host): /__connection/<provider>/<status> sets a linked
account's status, /__me/location/<on|off> flips the own sharing switch.
"""
import re
import threading
import time
from datetime import datetime, timedelta, timezone

EVENT = "lan-party-2026"
ME = 1
LINK_FLIP_AFTER = 4.0  # seconds between the link-url and the provider answering


def iso(dt):
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def now():
    return datetime.now(timezone.utc)


def module_of(route):
    """The module key a social route belongs to (a module that is off answers 404), or None."""
    if route.startswith(("friends", "users/")):
        return "friends"
    if route.startswith(("profile", "connections")):
        return "identity"
    if re.fullmatch(r"events/[^/]+/people(/\d+/follow)?", route):
        return "presence"
    return None


def user(uid, name):
    return {"id": uid, "name": name, "avatar_url": None}


class Social:
    def __init__(self, port):
        self.port = port
        self.lock = threading.Lock()
        self.reset()

    def reset(self):
        self.profile = {
            "id": ME, "name": "Tester", "email": "tester@example.test", "email_verified": True,
            "avatar_url": "https://cdn.example.test/tester.png", "bio": "Spielt gern Rocket League.",
            "steam_url": None, "stream_url": None, "profile_color": "#a85a00",
            "has_verified_steam_link": True, "verified_steam_nickname": "tester_steam",
            "share_location": True,
            "editable": ["name", "email", "bio", "steam_url", "stream_url", "profile_color", "share_location"],
        }
        self.connections = {
            "discord": {"provider": "discord", "label": "Discord", "status": "connected",
                        "external_name": "tester", "available": True, "can_link": False,
                        "can_unlink": False, "read_only": True},
            "steam": {"provider": "steam", "label": "Steam", "status": "connected",
                      "external_name": "tester_steam", "available": True, "can_link": False,
                      "can_unlink": True, "read_only": False},
            "twitch": {"provider": "twitch", "label": "Twitch", "status": "not_connected",
                       "external_name": None, "available": True, "can_link": True,
                       "can_unlink": False, "read_only": False},
        }
        self.link_flip = {}  # provider -> time.time() when it flips to connected
        self.link_seq = 0
        self.friends = [user(13, "Ben"), user(17, "Fynn")]
        self.incoming = [{"id": 501, "user": user(18, "Gero")}]
        self.outgoing = [{"id": 502, "user": user(19, "Hanna")}]
        self.suggestions = [
            {**user(14, "Cleo"), "shared": 2, "reasons": ["shared_event", "shared_tournament"],
             "reason_labels": ["Gemeinsames Event: LAN Party 2026", "Gemeinsames Turnier: CS2 Cup"]},
            {**user(15, "Dirk"), "shared": 1, "reasons": ["shared_team"],
             "reason_labels": ["Im selben Team: Die Nachtschicht"]},
            {**user(20, "Ina"), "shared": 1, "reasons": ["shared_steam_friend"],
             "reason_labels": ["Steam-Freund"]},
            {**user(22, "Karl"), "shared": 1, "reasons": ["shared_event"],
             "reason_labels": ["Gemeinsames Event: LAN Party 2026"]},
        ]
        self.blocked = [user(21, "Jonas")]
        self.next_request = 600
        self.people_error = None

    # ---- people ----

    def people_all(self):
        n = now()
        base = {"avatarUrl": None, "isMe": False, "isTeammate": False, "isFriend": False, "voice": [],
                "game": None, "lastSeenAt": None, "team": None}
        cs2_visible = {"source": "steam", "game": "Counter-Strike 2", "serverTitle": "CS2 Cup Runde 2",
                       "address": "203.0.113.5", "port": 27015, "joinUrl": "steam://connect/203.0.113.5:27015",
                       "linkId": 7, "since": iso(n - timedelta(minutes=6))}
        return [
            {**base, "id": 12, "name": "Ada", "team": "Team Rot", "isTeammate": True,
             "voice": [{"provider": "webvoice", "roomLabel": "Lobby", "roomRef": "1", "kind": "general",
                        "since": iso(n - timedelta(minutes=14))},
                       {"provider": "discord", "roomLabel": "Allgemein", "roomRef": "4711", "kind": "other",
                        "since": iso(n - timedelta(minutes=30))}],
             "game": cs2_visible, "lastSeenAt": iso(n - timedelta(minutes=1))},
            {**base, "id": 17, "name": "Fynn", "team": "Team Rot", "isTeammate": True, "isFriend": True,
             "lastSeenAt": iso(n - timedelta(minutes=25))},
            {**base, "id": 13, "name": "Ben", "isFriend": True,
             "voice": [{"provider": "discord", "roomLabel": "Allgemein", "roomRef": "4711", "kind": "other",
                        "since": iso(n - timedelta(minutes=9))}],
             "lastSeenAt": iso(n - timedelta(minutes=1))},
            {**base, "id": 14, "name": "Cleo", "team": "Die Nachtschicht",
             "voice": [{"provider": "mumble", "roomLabel": "Team Nachtschicht", "roomRef": "9", "kind": "team",
                        "since": iso(n - timedelta(minutes=3))}],
             "game": {"source": "query", "game": "Valheim", "serverTitle": "Valheim Dauerwelt",
                      "address": "203.0.113.8", "port": 2456, "joinUrl": None, "linkId": 32,
                      "since": iso(n - timedelta(minutes=3))},
             "lastSeenAt": iso(n - timedelta(minutes=1))},
            {**base, "id": 15, "name": "Dirk", "team": "Die Nachtschicht",
             "voice": [{"provider": "teamspeak", "roomLabel": "Match: Gelb gegen Grün", "roomRef": None,
                        "kind": "match", "since": iso(n - timedelta(minutes=20))}],
             "game": {"source": "steam", "game": "Counter-Strike 2", "serverTitle": None, "address": None,
                      "port": None, "joinUrl": None, "linkId": None, "since": iso(n - timedelta(minutes=20))},
             "lastSeenAt": iso(n - timedelta(minutes=1))},
            {**base, "id": 16, "name": "Eva", "lastSeenAt": iso(n - timedelta(minutes=47))},
        ]

    def people_data(self, query):
        everybody = self.people_all()
        rows = [p for p in everybody if p["id"] not in {21}]
        if query.get("team") == "1":
            rows = [p for p in rows if p["isTeammate"]]
        if query.get("friends") == "1":
            rows = [p for p in rows if p["isFriend"]]
        q = (query.get("q") or "").strip().lower()
        if q:
            rows = [p for p in rows if q in p["name"].lower()]
        return rows

    def me_entry(self):
        sharing = self.profile["share_location"]
        n = now()
        return {"id": ME, "name": "Tester", "avatarUrl": None, "team": "Team Rot", "isMe": True,
                "isTeammate": False, "isFriend": False,
                "voice": [{"provider": "webvoice", "roomLabel": "Match: Rot gegen Blau", "roomRef": "3",
                           "kind": "match", "since": iso(n - timedelta(minutes=2))}] if sharing else [],
                "game": None, "lastSeenAt": iso(n) if sharing else None}

    def follow_targets(self, uid):
        p = next((x for x in self.people_all() if x["id"] == uid), None)
        if p is None or uid == ME:
            return None
        voice = []
        for v in p["voice"]:
            joinable = v["roomRef"] is not None
            entry = {"provider": v["provider"], "roomLabel": v["roomLabel"], "kind": v["kind"],
                     "joinable": joinable, "join": None}
            if joinable and v["provider"] == "webvoice":
                entry["join"] = {"roomId": int(v["roomRef"]),
                                 "tokenPath": f"/api/voice-client/v1/rooms/{v['roomRef']}/token"}
            elif joinable and v["provider"] == "discord":
                entry["join"] = {"url": f"https://discord.com/channels/111111111111111111/{v['roomRef']}"}
            elif joinable and v["provider"] == "mumble":
                # The server writes channel names raw (with a space) and the name percent-encoded.
                entry["join"] = {"url": f"mumble://Tester@voice.example:64738/{v['roomLabel']}"}
            elif joinable and v["provider"] == "teamspeak":
                entry["join"] = {"url": "ts3server://voice.example?port=9987&nickname=Tester&channel=Lobby"}
            voice.append(entry)
        game = None
        if p["game"]:
            game = dict(p["game"])
            if game.get("address"):
                game["copyText"] = f"{game['address']}:{game['port']}"
        preferred = "webvoice" if any(v["provider"] == "webvoice" for v in p["voice"]) else None
        return {"user": {"id": uid, "name": p["name"]}, "preferredProvider": preferred, "voice": voice,
                "game": game}

    # ---- profile validation (the browser's ProfileUpdateRequest) ----

    def validate(self, payload):
        errors = {}

        def bad(field, text):
            errors.setdefault(field, []).append(text)

        for key in ("name", "email"):
            if key in payload and (not isinstance(payload[key], str) or not payload[key].strip()):
                bad(key, f"Das Feld {'Name' if key == 'name' else 'E-Mail'} ist ein Pflichtfeld.")
        if isinstance(payload.get("name"), str) and len(payload["name"]) > 255:
            bad("name", "Der Name darf höchstens 255 Zeichen lang sein.")
        email = payload.get("email")
        if isinstance(email, str) and email.strip():
            if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
                bad("email", "Bitte gib eine gültige E-Mail-Adresse an.")
            elif email.lower() == "vergeben@example.test":
                bad("email", "Diese E-Mail-Adresse wird schon verwendet.")
        bio = payload.get("bio")
        if isinstance(bio, str) and len(bio) > 1000:
            bad("bio", "Die Bio darf höchstens 1000 Zeichen lang sein.")
        steam = payload.get("steam_url")
        if isinstance(steam, str) and not steam.startswith("https://steamcommunity.com/"):
            bad("steam_url", "Die Steam-URL muss mit https://steamcommunity.com/ beginnen.")
        stream = payload.get("stream_url")
        if isinstance(stream, str) and not stream.startswith("https://"):
            bad("stream_url", "Die Stream-URL muss mit https:// beginnen.")
        color = payload.get("profile_color")
        if isinstance(color, str) and not re.fullmatch(r"#[0-9a-fA-F]{6}", color):
            bad("profile_color", "Die Profilfarbe muss im Format #rrggbb angegeben werden.")
        if "share_location" in payload and not isinstance(payload["share_location"], bool):
            bad("share_location", "Der Wert für Standort teilen muss ja oder nein sein.")
        return errors

    # ---- hooks ----

    def host(self, h, path):
        m = re.fullmatch(r"/__connection/(\w+)/(\w+)", path)
        if m:
            provider, status = m.groups()
            c = self.connections.get(provider)
            if not c or status not in ("connected", "not_connected", "needs_reauth"):
                h.send(404, {"message": "unknown"})
                return True
            self.link_flip.pop(provider, None)
            self.set_status(c, status)
            h.send(200, c)
            return True
        m = re.fullmatch(r"/__me/location/(on|off)", path)
        if m:
            self.profile["share_location"] = m.group(1) == "on"
            h.send(200, {"share_location": self.profile["share_location"]})
            return True
        return False

    @staticmethod
    def set_status(c, status):
        c["status"] = status
        c["can_link"] = c["available"] and status != "connected" and not c["read_only"]
        c["can_unlink"] = status != "not_connected" and not c["read_only"]
        c["external_name"] = None if status == "not_connected" else (c["external_name"] or f"tester_{c['provider']}")

    def tick(self):
        t = time.time()
        for provider, at in list(self.link_flip.items()):
            if t >= at:
                del self.link_flip[provider]
                self.set_status(self.connections[provider], "connected")

    # ---- the router ----

    def handle(self, h, method, route, query, payload, empty):
        """True when the route was answered."""
        send = h.send
        self.tick()
        if route == "profile":
            if method == "GET":
                send(200, {"profile": self.profile})
            elif method in ("PATCH", "PUT"):
                body = h.body()
                errors = self.validate(body)
                if errors:
                    first = next(iter(errors.values()))[0]
                    send(422, {"message": first, "errors": errors})
                else:
                    for key in ("name", "email", "bio", "steam_url", "stream_url", "profile_color",
                                "share_location"):
                        if key in body:
                            self.profile[key] = body[key]
                    send(200, {"profile": self.profile})
            else:
                return False
            return True
        if route == "connections" and method == "GET":
            send(200, {"connections": list(self.connections.values())})
            return True
        m = re.fullmatch(r"connections/(\w+)(/link-url)?", route)
        if m:
            c = self.connections.get(m.group(1))
            if not c or c["read_only"]:
                send(404, {"message": "Diese Verknüpfung gibt es nicht."})
            elif m.group(2) and method == "POST":
                if not c["available"]:
                    send(404, {"message": "Dieses Konto ist hier nicht eingerichtet."})
                else:
                    self.link_seq += 1
                    self.link_flip[c["provider"]] = time.time() + LINK_FLIP_AFTER
                    send(200, {"provider": c["provider"],
                               "url": f"http://127.0.0.1:{self.port}/settings/connections/link/demo-link-{self.link_seq}",
                               "expires_at": iso(now() + timedelta(minutes=10))})
            elif method == "DELETE":
                self.set_status(c, "not_connected")
                send(200, {"connection": c})
            else:
                return False
            return True
        # ---- friends ----
        if route == "friends" and method == "GET":
            send(200, {"friends": [] if empty else self.friends})
            return True
        if route == "friends/requests" and method == "GET":
            send(200, {"incoming": [] if empty else self.incoming, "outgoing": [] if empty else self.outgoing})
            return True
        if route == "friends/suggestions" and method == "GET":
            send(200, {"suggestions": [] if empty else self.suggestions})
            return True
        if route == "friends/blocked" and method == "GET":
            send(200, {"blocked": [] if empty else self.blocked})
            return True
        if route == "friends/requests" and method == "POST":
            uid = payload.get("user_id")
            if uid == 22:  # a stale suggestion: the server knows better
                send(422, {"code": "request_pending", "message": "Du hast Karl schon eine Anfrage gesendet."})
                return True
            who = next((s for s in self.suggestions if s["id"] == uid), None)
            if not who:
                send(422, {"message": "Diese Person gibt es nicht.", "errors": {"user_id": ["ungültig"]}})
                return True
            self.suggestions.remove(who)
            self.next_request += 1
            self.outgoing.append({"id": self.next_request, "user": user(who["id"], who["name"])})
            send(201, {"status": "pending"})
            return True
        m = re.fullmatch(r"friends/requests/(\d+)/(accept|decline)", route)
        if m and method == "POST":
            rid, action = int(m.group(1)), m.group(2)
            req = next((r for r in self.incoming if r["id"] == rid), None)
            if not req:
                send(404, {"message": "Nicht gefunden."})
                return True
            self.incoming.remove(req)
            if action == "accept":
                self.friends.append(req["user"])
            send(200, {"status": "accepted" if action == "accept" else "declined"})
            return True
        m = re.fullmatch(r"friends/requests/(\d+)", route)
        if m and method == "DELETE":
            req = next((r for r in self.outgoing if r["id"] == int(m.group(1))), None)
            if not req:
                send(404, {"message": "Nicht gefunden."})
                return True
            self.outgoing.remove(req)
            self.empty204(h)
            return True
        m = re.fullmatch(r"friends/(\d+)", route)
        if m and method == "DELETE":
            self.friends = [f for f in self.friends if f["id"] != int(m.group(1))]
            self.empty204(h)
            return True
        m = re.fullmatch(r"users/(\d+)/block", route)
        if m and method in ("POST", "DELETE"):
            uid = int(m.group(1))
            if method == "POST":
                if uid == ME:
                    send(422, {"code": "cannot_block_self", "message": "Du kannst dich nicht selbst blockieren."})
                    return True
                who = next((f for f in self.friends if f["id"] == uid), None) \
                    or next((r["user"] for r in self.incoming + self.outgoing if r["user"]["id"] == uid), None) \
                    or next((user(s["id"], s["name"]) for s in self.suggestions if s["id"] == uid), None) \
                    or user(uid, f"Person {uid}")
                self.friends = [f for f in self.friends if f["id"] != uid]
                self.incoming = [r for r in self.incoming if r["user"]["id"] != uid]
                self.outgoing = [r for r in self.outgoing if r["user"]["id"] != uid]
                self.suggestions = [s for s in self.suggestions if s["id"] != uid]
                if not any(b["id"] == uid for b in self.blocked):
                    self.blocked.append(who)
                send(200, {"blocked": True})
            else:
                self.blocked = [b for b in self.blocked if b["id"] != uid]
                self.empty204(h)
            return True
        # ---- people ----
        m = re.fullmatch(r"events/([^/]+)/people(?:/(\d+)/follow)?", route)
        if m and method == "GET":
            if m.group(2) is None:
                rows = [] if empty else self.people_data(query)
                send(200, {"me": self.me_entry(), "data": rows,
                           "meta": {"current_page": 1, "per_page": 50, "total": len(rows)}})
            else:
                t = self.follow_targets(int(m.group(2)))
                if t is None:
                    send(404, {"message": "Diese Person ist gerade nicht zu finden."})
                else:
                    send(200, t)
            return True
        return False

    @staticmethod
    def empty204(h):
        h.send_response(204)
        h.send_header("Content-Length", "0")
        h.end_headers()
