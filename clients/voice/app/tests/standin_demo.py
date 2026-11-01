"""Stateful demo data for the UI smoke (standin_server.py --features --ui-demo): a LAN party with
matches, servers, polls, a food round, files, notifications and chat, so that every screen of the
client can be driven and screenshotted in its normal, empty and error state.

A scenario switch (GET /__scenario/<name>, no auth, loopback only) changes what the API answers
without restarting anything: `normal`, `empty` (nothing to show), `error` (503 on every feature
route) and `offline` (the connection is cut without an answer). `GET /__chat?room=1&as=Peer&body=..`
lets a second user write (published over the fake Pusher). Everything is invented, no real data.
"""
import json
import re
import threading
import time
from datetime import datetime, timedelta, timezone

import standin_social

EVENT = "lan-party-2026"
LIMIT = 10


def iso(dt):
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def now():
    return datetime.now(timezone.utc)


class Demo:
    def __init__(self, features):
        self.f = features
        self.lock = threading.Lock()
        self.scenario = getattr(features.args, "scenario", "normal")
        self.chat_posts = {}  # user id -> [timestamps]
        self.next_item = 100
        self.next_file = 100
        self.social = standin_social.Social(features.args.port)
        self.reset()

    def reset(self):
        self.social.reset()
        n = now()
        self.polls = {
            3: {"id": 3, "question": "Welche Map spielen wir im Finale?", "kind": "standard", "status": "open",
                "closes_at": iso(n + timedelta(minutes=5)),
                "options": [{"id": 12, "label": "Dust", "count": 2}, {"id": 13, "label": "Mirage", "count": 1}],
                "my": None},
            4: {"id": 4, "question": "Welche Pizza bestellen wir fürs Finale?", "kind": "standard", "status": "closed",
                "closes_at": iso(n - timedelta(hours=1)),
                "options": [{"id": 21, "label": "Salami", "count": 7}, {"id": 22, "label": "Margherita", "count": 4},
                            {"id": 23, "label": "Hawaii", "count": 1}],
                "my": 21},
        }
        self.orders = {
            4: {"id": 4, "title": "Pizza am Abend", "status": "open", "opens_at": iso(n - timedelta(hours=1)),
                "closes_at": iso(n + timedelta(minutes=25)),
                "menu": [{"key": "margherita", "name": "Pizza Margherita", "price_cents": 850},
                         {"key": "salami", "name": "Pizza Salami", "price_cents": 950},
                         {"key": "tonno", "name": "Pizza Tonno", "price_cents": 990},
                         {"key": "cola", "name": "Cola 0,5 l", "price_cents": 250}]},
            5: {"id": 5, "title": "Frühstück", "status": "closed", "opens_at": iso(n - timedelta(hours=12)),
                "closes_at": iso(n - timedelta(hours=9)),
                "menu": [{"key": "broetchen", "name": "Brötchen mit Aufschnitt", "price_cents": 390}]},
        }
        self.items = [
            {"id": 90, "order_id": 5, "option_key": "broetchen", "option_name": "Brötchen mit Aufschnitt", "note": None,
             "price_cents": 390, "paid": True},
        ]
        self.notifications = [
            {"id": "00000000-0000-4000-8000-0000000000a1", "category": "match", "title": "Dein Match startet",
             "body": "Team Blau wartet auf dich. Der Sprachraum ist offen.", "read": False, "read_at": None,
             "created_at": iso(n - timedelta(minutes=2))},
            {"id": "00000000-0000-4000-8000-0000000000a2", "category": "catering", "title": "Essen ist da",
             "body": "Deine Bestellung kannst du am Tresen abholen.", "read": False, "read_at": None,
             "created_at": iso(n - timedelta(minutes=20))},
            {"id": "00000000-0000-4000-8000-0000000000a3", "category": "direct", "title": "Ada hat dich erwähnt",
             "body": "@Tester kommst du in die Lobby?", "read": True, "read_at": iso(n - timedelta(hours=1)),
             "created_at": iso(n - timedelta(hours=2))},
        ]
        self.prefs = {"registration": True, "checkin": True, "match": True, "teams": True, "friends": True,
                      "schedule": True, "catering": True, "orga_ping": True, "discord": False}
        self.files = [
            {"id": 21, "name": "maps.zip", "size_bytes": self.f.content.__len__(), "uploader_name": "Ada",
             "visibility": "approved", "created_at": iso(n - timedelta(hours=3)), "mine": False,
             "can_download": True, "can_delete": False},
            {"id": 22, "name": "treiber-pack.7z", "size_bytes": 38_400_000, "uploader_name": "Ben",
             "visibility": "approved", "created_at": iso(n - timedelta(hours=1)), "mine": False,
             "can_download": True, "can_delete": False},
            {"id": 23, "name": "mein-mod.zip", "size_bytes": 2_100_000, "uploader_name": "Tester",
             "visibility": "pending", "created_at": iso(n - timedelta(minutes=10)), "mine": True,
             "can_download": True, "can_delete": True},
        ]
        self.match_state = "ready"  # ready | reported | done
        self.confirm_state = "waiting"  # waiting | confirmed | disputed
        self.checked_in = False

    # ---- fixtures ----

    def server(self, sid):
        base = {"description": None, "checkedAt": iso(now()), "connectIsLink": True,
                "installHint": {"steamUrl": "steam://store/730", "shareUrl": None, "versionNote": None}}
        servers = {
            21: {**base, "id": 21, "title": "CS2 Cup Runde 2", "game": "Counter-Strike 2",
                 "matchLabel": "CS2 Cup, Runde 2", "status": "ready", "players": 4, "maxPlayers": 10,
                 "map": "de_mirage", "address": "203.0.113.5", "port": 27015,
                 "connectString": "steam://connect/203.0.113.5:27015",
                 "joinUrl": "steam://connect/203.0.113.5:27015", "copyText": "203.0.113.5:27015"},
            22: {**base, "id": 22, "title": "Minecraft zum Event", "game": "Minecraft",
                 "matchLabel": None, "status": "provisioning", "players": None, "maxPlayers": None, "map": None,
                 "address": "203.0.113.6", "port": 25565, "connectString": "203.0.113.6:25565", "connectIsLink": False,
                 "installHint": {"steamUrl": None, "shareUrl": "https://lancache.example.org/minecraft",
                                 "versionNote": "Version 1.21"},
                 "joinUrl": None, "copyText": None},
            31: {**base, "id": 31, "title": "UT2004 Dauerserver", "game": "Unreal Tournament 2004",
                 "matchLabel": None, "status": "offline", "players": None, "maxPlayers": None, "map": None,
                 "checkedAt": iso(now() - timedelta(hours=5)), "address": "203.0.113.7", "port": 7777,
                 "connectString": "203.0.113.7:7777", "connectIsLink": False,
                 "installHint": {"steamUrl": None, "shareUrl": "https://lancache.example.org/ut2004",
                                 "versionNote": None},
                 "joinUrl": None, "copyText": None},
            32: {**base, "id": 32, "title": "Valheim Dauerwelt", "game": "Valheim", "matchLabel": None,
                 "status": "ready", "players": 3, "maxPlayers": 10, "map": "Wikingerwelt", "address": "203.0.113.8",
                 "port": 2456, "connectString": "203.0.113.8:2456  Passwort: lanparty", "connectIsLink": False,
                 "installHint": {"steamUrl": "steam://store/892970", "shareUrl": None, "versionNote": None},
                 "joinUrl": None, "copyText": "203.0.113.8:2456  Passwort: lanparty"},
        }
        return servers[sid]

    def matches(self):
        out = []
        if self.match_state == "ready":
            m = self.f._match_dto()
            m["server"] = self.server(21)
            out.append(m)
        else:
            m = self.f._match_dto()
            m["server"] = self.server(21)
            m["status"] = "reported" if self.match_state == "reported" else "completed"
            m["phase"] = "active" if self.match_state == "reported" else "finished"
            m["lockVersion"] = 1
            m["score"] = "13:7"
            m["can"] = {"report": False, "confirm": False, "dispute": False,
                        "withdrawReport": self.match_state == "reported", "checkIn": False}
            if self.match_state == "reported":
                m["pendingReport"] = {"reportedByMe": True, "ownScore": 13, "opponentScore": 7}
            out.append(m)
        confirm = {
            "id": 90, "phase": "active", "status": "reported",
            "tournament": {"id": 8, "name": "Rocket League 2v2", "game": "Rocket League", "status": "live"},
            "round": 1, "bracket": "winners", "scheduledAt": None, "warmupStartedAt": None,
            "updatedAt": iso(now()), "me": {"entryId": 41, "name": "Tester + Ada"},
            "opponent": {"entryId": 45, "name": "Die Nachtschicht"}, "score": None, "won": None, "lockVersion": 3,
            "pendingReport": {"reportedByMe": False, "ownScore": 2, "opponentScore": 5}, "voiceRoomId": None,
            "server": None,
            "can": {"report": False, "confirm": self.confirm_state == "waiting",
                    "dispute": self.confirm_state == "waiting", "withdrawReport": False, "checkIn": False},
        }
        if self.confirm_state == "confirmed":
            confirm.update(status="completed", phase="finished", score="2:5", pendingReport=None)
        if self.confirm_state == "disputed":
            confirm.update(status="disputed")
        out.append(confirm)
        out.append({
            "id": 91, "phase": "upcoming", "status": "pending",
            "tournament": {"id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "status": "live"},
            "round": 3, "bracket": "winners", "scheduledAt": iso(now() + timedelta(hours=2)),
            "warmupStartedAt": None, "updatedAt": iso(now()), "me": {"entryId": 31, "name": "Team Rot"},
            "opponent": None, "score": None, "won": None, "lockVersion": 0, "pendingReport": None,
            "voiceRoomId": None, "server": None,
            "can": {"report": False, "confirm": False, "dispute": False, "withdrawReport": False, "checkIn": False},
        })
        out.append({
            "id": 85, "phase": "finished", "status": "completed",
            "tournament": {"id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "status": "live"},
            "round": 1, "bracket": "winners", "scheduledAt": None, "warmupStartedAt": None,
            "updatedAt": iso(now() - timedelta(hours=1)), "me": {"entryId": 31, "name": "Team Rot"},
            "opponent": {"entryId": 33, "name": "Team Grün"}, "score": "16:9", "won": True, "lockVersion": 5,
            "pendingReport": None, "voiceRoomId": None, "server": None,
            "can": {"report": False, "confirm": False, "dispute": False, "withdrawReport": False, "checkIn": False},
        })
        return out

    def tournaments(self):
        n = now()
        return [
            {"id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "format": "single_elimination",
             "status": "check_in" if not self.checked_in else "live", "startsAt": iso(n + timedelta(minutes=30)),
             "checkinOpensAt": iso(n - timedelta(minutes=10)), "checkinClosesAt": iso(n + timedelta(minutes=8)),
             "entryCount": 12, "maxEntries": 16, "teamSize": 1,
             "myEntry": {"id": 31, "status": "checked_in" if self.checked_in else "registered",
                         "canWithdraw": not self.checked_in, "isOwner": True},
             "can": {"checkIn": not self.checked_in, "withdraw": not self.checked_in},
             "registration": {"required": False, "open": False, "url": None}},
            {"id": 8, "name": "Rocket League 2v2", "game": "Rocket League", "format": "double_elimination",
             "status": "live", "startsAt": iso(n - timedelta(hours=1)), "entryCount": 8, "maxEntries": 8, "teamSize": 2,
             "myEntry": {"id": 41, "status": "checked_in", "canWithdraw": False, "isOwner": True},
             "can": {"checkIn": False, "withdraw": False},
             "registration": {"required": False, "open": False, "url": None}},
            {"id": 9, "name": "Mario Kart Nacht", "game": "Mario Kart 8", "format": "round_robin",
             "status": "registration", "startsAt": iso(n + timedelta(hours=20)), "entryCount": 5, "maxEntries": 12,
             "teamSize": 1, "myEntry": None, "can": {"checkIn": False, "withdraw": False},
             "registration": {"required": True, "open": True, "url": "https://lan.example.org/events/x/register"}},
        ]

    def poll_dto(self, p, full=True):
        total = sum(o["count"] for o in p["options"])
        open_ = p["status"] == "open"
        d = {"id": p["id"], "event_slug": EVENT, "question": p["question"], "kind": p["kind"], "status": p["status"],
             "is_open": open_, "closes_at": p["closes_at"], "total_votes": total, "my_vote_option_id": p["my"]}
        if full:
            d["options"] = [{"id": o["id"], "label": o["label"], "count": o["count"],
                             "percent": round(o["count"] * 1000 / total) / 10 if total else 0.0}
                            for o in p["options"]]
        return d

    def my_items(self):
        out = []
        for it in self.items:
            o = self.orders[it["order_id"]]
            out.append({**it, "order_title": o["title"], "order_status": o["status"],
                        "order_is_open": o["status"] == "open", "can_cancel": o["status"] == "open"})
        return out

    def feed(self):
        n = now()
        cards = []
        if self.scenario == "empty":
            return {"cards": [{"kind": "schedule", "id": "schedule:5", "state": "next", "title": "Turnierstart: CS2 Cup",
                               "body": "Treffpunkt an der großen Bühne.", "at": iso(n + timedelta(minutes=50)),
                               "data": {"item": {"title": "Turnierstart: CS2 Cup"}}}], "more": []}
        t = self.tournaments()[0]
        if t["can"]["checkIn"]:
            cards.append({"kind": "match", "id": "match:checkin:7", "state": "checkin_open",
                          "title": "Check-in für den CS2 Cup läuft", "body": "Check dich ein, sonst verfällt dein Platz.",
                          "at": t["checkinClosesAt"], "data": {"tournament": t}})
        for m in self.matches():
            if m["can"]["confirm"]:
                cards.append({"kind": "match", "id": "match:90", "state": "confirm_result",
                              "title": "Bitte Ergebnis bestätigen", "body": "Die Nachtschicht meldet 5:2",
                              "at": None, "data": {"match": m}})
            if m["id"] == 88 and m["phase"] == "active" and m["status"] == "ready":
                cards.append({"kind": "match", "id": "match:88", "state": "ready", "title": "Dein Match ist bereit",
                              "body": "gegen Team Blau", "at": None, "data": {"match": m}})
        p = self.polls[3]
        if p["my"] is None:
            cards.append({"kind": "vote", "id": "vote:3", "state": "open", "title": "Abstimmung", "body": None,
                          "at": p["closes_at"],
                          "data": {"pollId": 3, "question": p["question"], "closesAt": p["closes_at"],
                                   "options": [{"id": o["id"], "label": o["label"]} for o in p["options"]]}})
        o = self.orders[4]
        if not any(i["order_id"] == 4 for i in self.items):
            cards.append({"kind": "order", "id": "order:4", "state": "closing_soon", "title": "Essen: Runde schließt bald",
                          "body": None, "at": o["closes_at"],
                          "data": {"orderId": 4, "title": o["title"], "closesAt": o["closes_at"],
                                   "menu": [{"key": m["key"], "name": m["name"], "priceCents": m["price_cents"]}
                                            for m in o["menu"]]}})
        for nt in [x for x in self.notifications if not x["read"]][:3]:
            cards.append({"kind": "notification", "id": "notification:" + nt["id"], "state": "unread",
                          "title": nt["title"], "body": nt["body"], "at": None,
                          "data": {"notificationId": nt["id"], "category": nt["category"]}})
        return {"cards": cards[:5], "more": cards[5:]}

    # ---- the router ----

    def fail(self, h, status=503, msg="Der Server kann das gerade nicht beantworten."):
        h.send(status, {"message": msg})
        return True

    def handle_host(self, h, method, path, query):
        m = re.fullmatch(r"/__scenario/(\w+)", path)
        if m:
            self.scenario = m.group(1)
            if self.scenario == "normal":
                self.reset()
            h.send(200, {"scenario": self.scenario})
            return True
        if self.social.host(h, path):
            return True
        if path == "/__reset":
            self.reset()
            h.send(200, {"ok": True})
            return True
        if path == "/__chat":
            room = int(query.get("room", "1"))
            msg = self.f.add_message(room, 2, query.get("as", "Peer"), query.get("body", "gl hf"))
            h.send(200, {"id": msg["id"]})
            return True
        if path == "/__uploaded":
            h.send(200, {"files": [f["name"] for f in self.files]})
            return True
        return False

    def on_upload_complete(self, name, size):
        with self.lock:
            self.next_file += 1
            self.files.append({"id": self.next_file, "name": name, "size_bytes": size, "uploader_name": "Tester",
                               "visibility": "pending", "created_at": iso(now()), "mine": True,
                               "can_download": True, "can_delete": True})

    def handle(self, h, method, route, query, payload, user):
        """Returns True when answered (demo mode only)."""
        uid, name = user
        send = h.send
        if self.scenario == "offline" and route not in ("me",):
            h.close_connection = True
            try:
                h.connection.close()
            except OSError:
                pass
            return True
        if route == "config":
            off = set(self.f.args.modules_off.split(",")) if self.f.args.modules_off else set()
            mods = {"game_servers": True, "catering": True, "voting": True, "files": True, "tournaments": True,
                    "schedule": True, "lfg": False, "friends": True, "presence": True, "identity": True}
            for k in off:
                if k:
                    mods[k] = False
            send(200, {"api_version": 1, "livekit_url": None, "voice_ready": True, "audio": {}, "modules": mods,
                       "realtime": {"driver": "reverb", "key": "standin-app", "host": "127.0.0.1",
                                    "port": self.f.args.pusher_port, "scheme": "http",
                                    "auth_endpoint": "/api/voice-client/v1/broadcasting/auth"}})
            return True
        if route in ("events", "broadcasting/auth", "rooms") or re.fullmatch(r"rooms/\d+/token", route):
            return False
        if route == "notifications/unread-count" and self.scenario != "error":
            send(200, {"unread": sum(1 for x in self.notifications if not x["read"])})
            return True
        if self.scenario == "error" and not route.startswith("rooms/"):
            return self.fail(h)
        empty = self.scenario == "empty"
        off = set(self.f.args.modules_off.split(",")) if self.f.args.modules_off else set()
        off = set(self.f.args.modules_off.split(",")) if self.f.args.modules_off else set()
        if standin_social.module_of(route) in off:
            send(404, {"message": "Dieser Bereich ist hier nicht eingeschaltet."})
            return True
        if self.social.handle(h, method, route, query, payload, empty):
            return True
        m = re.fullmatch(r"events/([^/]+)/(.+)", route)
        if m:
            sub = m.group(2)
            if method == "GET" and sub == "now":
                send(200, self.feed())
            elif method == "GET" and sub == "tournaments":
                send(200, {"tournaments": [] if empty else self.tournaments()})
            elif method == "GET" and sub == "my-matches":
                send(200, {"matches": [] if empty else self.matches()})
            elif method == "GET" and sub == "servers":
                send(200, {"servers": [] if empty else [self.server(21), self.server(22)]})
            elif method == "GET" and sub == "polls":
                polls = [] if empty else [self.poll_dto(p, False) for p in self.polls.values()]
                send(200, {"event_slug": EVENT, "polls": polls})
            elif method == "GET" and sub == "catering":
                orders = []
                if not empty:
                    for o in self.orders.values():
                        mine = [i for i in self.items if i["order_id"] == o["id"]]
                        orders.append({**o, "is_open": o["status"] == "open",
                                       "my_items": [{k: i[k] for k in ("id", "option_key", "option_name", "note",
                                                                      "price_cents", "paid")} for i in mine],
                                       "my_total_cents": sum(i["price_cents"] for i in mine)})
                send(200, {"event_slug": EVENT, "registration_required": False,
                           "limits": {"items_per_user": LIMIT,
                                      "my_open_items": sum(1 for i in self.items if self.orders[i["order_id"]]["status"] == "open")},
                           "orders": orders})
            elif method == "GET" and sub == "catering/mine":
                items = [] if empty else self.my_items()
                send(200, {"items": items, "total_cents": sum(i["price_cents"] for i in items),
                           "paid_cents": sum(i["price_cents"] for i in items if i["paid"])})
            elif method == "GET" and sub == "files":
                send(200, {"event_slug": EVENT, "files": [] if empty else self.files, "quota": self.quota(),
                           "upload": {"resumable": True, "max_file_bytes": 107374182400}})
            elif method == "GET" and sub == "files/quota":
                send(200, {"quota": self.quota(), "upload": {"resumable": True, "max_file_bytes": 107374182400}})
            else:
                return False
            return True
        if method == "GET" and route == "servers":
            send(200, {"servers": [] if empty else [self.server(32), self.server(31)]})
            return True
        m = re.fullmatch(r"polls/(\d+)", route)
        if m and method == "GET" and int(m.group(1)) in self.polls:
            send(200, self.poll_dto(self.polls[int(m.group(1))]))
            return True
        m = re.fullmatch(r"polls/(\d+)/vote", route)
        if m and method == "POST" and int(m.group(1)) in self.polls:
            p = self.polls[int(m.group(1))]
            if p["status"] != "open":
                send(422, {"code": "not_open", "message": "Diese Abstimmung ist beendet."})
            elif p["my"] is not None:
                send(422, {"code": "already_voted", "message": "Du hast schon abgestimmt."})
            else:
                opt = next((o for o in p["options"] if o["id"] == payload.get("option_id")), None)
                if not opt:
                    send(404, {"message": "Not Found"})
                else:
                    opt["count"] += 1
                    p["my"] = opt["id"]
                    send(201, self.poll_dto(p))
            return True
        m = re.fullmatch(r"food-orders/(\d+)/items", route)
        if m and method == "POST":
            o = self.orders.get(int(m.group(1)))
            opt = next((x for x in (o or {"menu": []})["menu"] if x["key"] == payload.get("option_key")), None)
            if not o or o["status"] != "open":
                send(422, {"code": "not_open", "message": "Diese Bestellrunde ist geschlossen."})
            elif not opt:
                send(422, {"code": "unknown_option", "message": "Diesen Artikel gibt es nicht."})
            elif sum(1 for i in self.items if self.orders[i["order_id"]]["status"] == "open") >= LIMIT:
                send(422, {"code": "item_limit", "message": f"Du kannst höchstens {LIMIT} Artikel offen haben."})
            else:
                with self.lock:
                    self.next_item += 1
                    it = {"id": self.next_item, "order_id": o["id"], "option_key": opt["key"],
                          "option_name": opt["name"], "note": payload.get("note"), "price_cents": opt["price_cents"],
                          "paid": False}
                    self.items.append(it)
                send(201, {"item": {k: it[k] for k in ("id", "option_key", "option_name", "note", "price_cents", "paid")}})
            return True
        m = re.fullmatch(r"food-order-items/(\d+)", route)
        if m and method == "DELETE":
            it = next((i for i in self.items if i["id"] == int(m.group(1))), None)
            if not it:
                send(404, {"message": "Not Found"})
            elif self.orders[it["order_id"]]["status"] != "open":
                send(422, {"code": "not_open", "message": "Diese Bestellrunde ist geschlossen."})
            else:
                self.items.remove(it)
                h.send_response(204)
                h.send_header("Content-Length", "0")
                h.end_headers()
            return True
        m = re.fullmatch(r"files/(\d+)", route)
        if m and method == "DELETE":
            f = next((x for x in self.files if x["id"] == int(m.group(1))), None)
            if not f or not f["can_delete"]:
                send(403, {"message": "Das darfst du nicht."})
            else:
                self.files.remove(f)
                h.send_response(204)
                h.send_header("Content-Length", "0")
                h.end_headers()
            return True
        if method == "POST" and route == "tournaments/7/checkin":
            self.checked_in = True
            send(200, {"tournament": self.tournaments()[0]})
            return True
        m = re.fullmatch(r"matches/(\d+)/(report|confirm|dispute|report/withdraw)", route)
        if m and method == "POST":
            mid, action = int(m.group(1)), m.group(2)
            if mid == 88 and action == "report":
                if payload.get("score1") == payload.get("score2"):
                    send(422, {"message": "Unentschieden ist nicht erlaubt.", "errors": {"score2": ["Unentschieden ist nicht erlaubt."]}})
                else:
                    self.match_state = "reported"
                    send(200, {"match": self.matches()[0]})
            elif mid == 88 and action == "report/withdraw":
                self.match_state = "ready"
                send(200, {"match": self.matches()[0]})
            elif mid == 90 and action == "confirm":
                self.confirm_state = "confirmed"
                send(200, {"match": self.matches()[1]})
            elif mid == 90 and action == "dispute":
                self.confirm_state = "disputed"
                send(200, {"match": self.matches()[1]})
            else:
                send(409, {"message": "Das Match hat sich geändert.", "error": "tournaments.errors.stale_match"})
            return True
        if method == "GET" and route == "notifications":
            items = [x for x in self.notifications if not (query.get("unread") == "1" and x["read"])]
            send(200, {"notifications": [] if empty else items, "page": 1, "per_page": 20,
                       "total": len(items), "has_more": False})
            return True
        if method == "POST" and route == "notifications/read-all":
            marked = 0
            for x in self.notifications:
                if not x["read"]:
                    x["read"], x["read_at"] = True, iso(now())
                    marked += 1
            send(200, {"marked": marked})
            return True
        if method == "GET" and route == "notification-preferences":
            send(200, {"preferences": self.prefs})
            return True
        if method == "PUT" and route == "notification-preferences":
            self.prefs.update(payload.get("preferences") or {})
            send(200, {"preferences": self.prefs})
            return True
        m = re.fullmatch(r"rooms/(\d+)/messages", route)
        if m and method == "POST":
            stamps = [t for t in self.chat_posts.get(uid, []) if time.time() - t < 10]
            if len(stamps) >= 10:
                retry = max(1, int(10 - (time.time() - stamps[0])) + 1)
                h.send(429, {"message": "Zu viele Nachrichten."}, {"Retry-After": str(retry)})
                return True
            stamps.append(time.time())
            self.chat_posts[uid] = stamps
            return False
        m = re.fullmatch(r"messages/(\d+)", route)
        if m and method == "DELETE":
            mid = int(m.group(1))
            for room, msgs in self.f.messages.items():
                for msg in msgs:
                    if msg["id"] == mid:
                        msgs.remove(msg)
                        self.f.pusher.publish(f"private-web-voice.channel.{room}", "message.deleted",
                                              {"channel_id": room, "message_id": mid})
                        send(200, {"ok": True})
                        return True
            send(404, {"message": "Not Found"})
            return True
        return False

    def quota(self):
        used = 120 * 1024 * 1024 + sum(f["size_bytes"] for f in self.files if f["mine"])
        cap = 512 * 1024 * 1024
        return {"used_bytes": used, "cap_bytes": cap, "remaining_bytes": cap - used}
