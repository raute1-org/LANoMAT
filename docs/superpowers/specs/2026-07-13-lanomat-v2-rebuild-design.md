# LANoMAT v2 — Bauplan für die Neuaufsetzung

**Datum:** 2026-07-13
**Status:** Entwurf zur Review
**Ziel-Repo:** neues Repository (Arbeitstitel: `lanomat`)

---

## 1. Ziel

Ein einziges, modulares Tool, über das die **gesamte LAN-Party-Organisation** läuft: Anmeldung & Tickets, Sitzplan, Turniere mit Brackets, Zeitplan, Catering, Mitspielersuche, Abstimmungen, Infoscreens, Discord-Integration und Gameserver. Selbst gehostet, betrieben von einem kleinen Orga-Team, genutzt von einer Discord-Community.

Die Neuaufsetzung ersetzt LANoMAT v1 (Nuxt-Nitro + NestJS + Plain-JS-Bot). Sie ist keine 1:1-Portierung, sondern eine Konsolidierung plus gezielte Feature-Erweiterung.

## 2. Lehren aus v1 (Ist-Analyse, Juli 2026)

Das vollständige Inventar wurde aus dem bestehenden Code erhoben. Die strukturellen Probleme, die v2 beheben muss:

| Problem in v1 | Konsequenz | v2-Antwort |
|---|---|---|
| 3 Runtimes (Nitro-Backend, NestJS, Bot-Prozess) | 3× Deployment, 3× Auth, doppelte Typen | **Ein modularer Monolith** |
| 2 Persistenz-Stile (raw `pg` + MikroORM) | Kein einheitliches Datenmodell, Bot schreibt an der App vorbei direkt in die DB | **Ein ORM (Eloquent), eine DB, alle Schreibzugriffe durch die App** |
| Kein Event-/Editions-Begriff | Keine Historie, keine Statistiken, alles „eine implizite LAN" | **`Event` als Kern-Entität** |
| Bot mit eigener DB-Logik + In-Memory-Deduplizierung | Duplikate nach Restart, Logik doppelt | **Kein Bot-Prozess: Discord REST + Interactions-Endpoint direkt aus der App** |
| Kein Echtzeit-Layer (nur Polling) | Projector/Brackets laggen | **Laravel Reverb (WebSockets)** |
| Handgebautes Admin-UI (~50 % des Frontend-Aufwands) | Enormer Pflegeaufwand | **Filament v5 Admin-Panel** |
| Bracket-Logik doppelt (eigene in `scheduler.ts` **und** `brackets-manager` mit Schema-Mismatch) | Tech-Debt, Bugs | **Eine eigene, getestete Bracket-Engine** |
| Gameserver-Management als Eigenbau (SSH-Tunnel, CS2-Cache-Sync, RCON — die komplexesten Teile von v1) | Dauerhafter Wartungs-Klotz | **Pelican Panel integrieren statt neu bauen** |
| Voting halb gebaut, TeamSpeak halb verdrahtet, Backups/Konsole „geplant" | Tote Baustellen | Bewusste Phasen + Backlog statt angefangener Features |

## 3. Architektur-Entscheidung

### Geprüfte Optionen

**Option A — Laravel-13-Monolith (Empfehlung).** Laravel 13 (PHP 8.4) als modularer Monolith. Filament v5 für das komplette Admin-/Orga-Panel, Inertia v2 + Vue 3 für die Teilnehmer-UI, Reverb für Echtzeit, Scheduler/Queues für Hintergrundjobs, Socialite für Discord-OAuth, Discord REST + Interactions-Endpoint statt Bot-Prozess, Pelican Panel für Gameserver.

**Option B — Unified-TypeScript (Nuxt 4 Full-Stack).** Eine Sprache, bestehendes Vue-Know-how, discord.js und dockerode nativ. Aber: kein Filament-Äquivalent (Admin-UI wieder komplett handgebaut), Auth/Queues/Scheduler/Policies/Notifications alles selbst zusammengesteckt — strukturell dieselbe Glue-Code-Falle wie v1.

**Option C — NestJS als Single-Backend, Nuxt als reine SPA.** Geringste Lernkurve, aber löst das Kernproblem (Eigenbau von Admin + Betriebs-Infrastruktur) nicht. NestJS' Stärken (Microservices, große Teams) treffen diesen Anwendungsfall nicht.

### Warum Option A gewinnt

Der dominante Aufwandstreiber dieses Projekts ist nicht Algorithmik, sondern **Admin-CRUD + Betriebs-Glue** (Auth, Rollen, Jobs, Benachrichtigungen, Validierung). Genau das ist Laravels Kernkompetenz:

1. **Filament v5** ersetzt das gesamte handgebaute Admin-Panel (Turniere, Spiele, User, Sitzplan, Catering, Hosts) durch deklarative Resources — der größte einzelne Zeitgewinn der Neuaufsetzung.
2. **Scheduler + Queues** bilden exakt das v1-Jobprofil ab (Check-in-Fenster, Turnier-Autostart, Match-Timeouts, Erinnerungen) — first-party, mit Retry, Locking und Monitoring, statt `setInterval` mit In-Memory-State.
3. **Notifications** (Datenbank/In-App + eigener Discord-Channel) vereinheitlichen alles, was v1 über drei Systeme verstreut hat.
4. **Der Bot-Prozess entfällt.** Das Bot-Inventar belegt: Alle Slash-Commands, Channel-CRUD, DMs, Embeds und Announcements sind über Discord REST + HTTP-Interactions abbildbar. Auch das Verschieben von Usern aus Voice-Channels geht per REST (`PATCH /guilds/{id}/members/{user}` mit `channel_id`). Es gibt **keine** Gateway-Pflicht-Funktion in v1.
5. **Pelican Panel** (Laravel-basiert, De-facto-Nachfolger von Pterodactyl) übernimmt das Gameserver-Management — die drei größten Komplexitäts-Hotspots von v1 (SSH-Tunnel-Docker, CS2-Cache-Sync, RCON-Handling) werden zu einer API-Integration.

Bewusst in Kauf genommene Trade-offs:

- **PHP statt TypeScript im Backend.** Das Vue-Know-how bleibt über Inertia voll nutzbar; Eloquent/Filament sind schnell gelernt und im Jahr 2026 exzellent dokumentiert (inkl. KI-Unterstützung).
- **Zwei UI-Welten** (Filament/Livewire fürs Admin, Inertia/Vue für Teilnehmer). Das ist Absicht: Admin-Oberflächen müssen funktional sein, nicht schön; Teilnehmer-Oberflächen (Brackets, Infoscreen, Sitzplan) müssen schön sein — dafür Vue.
- **Pelican als separates Deployment** (eigener Container + Wings-Daemon je Host). Dafür entfällt der komplette Eigenbau.

## 4. Tech-Stack

| Schicht | Technologie | Anmerkung |
|---|---|---|
| Backend | **Laravel 13**, PHP 8.4 | Support bis 2028 |
| Admin-Panel | **Filament v5** | Livewire 4; alle Orga-CRUDs |
| Teilnehmer-UI | **Inertia v2 + Vue 3 + Tailwind CSS v4 + shadcn-vue** | Vue-Know-how aus v1 wiederverwendbar |
| Echtzeit | **Laravel Reverb + Echo** | Brackets, Infoscreen, Match-Status |
| Jobs | Laravel Scheduler + Queues (Redis-Treiber) | Horizon optional |
| DB | **PostgreSQL 16** | wie v1, eine Datenbank |
| Cache/Queue | Redis | auch für Deduplizierung/Locks |
| Auth | Socialite (Discord OAuth) + Session | Rollen: admin, orga, participant |
| Discord | Eigener `DiscordService` (REST) + Interactions-Endpoint (Route + Ed25519-Signaturprüfung) | kein Gateway, kein Bot-Prozess; nur Text/Announcements |
| Voice | **Mumble** (mumble-server im Compose-Stack) + REST-Sidecar über die Ice-API | Low-Latency-Voice am Event; Channel-Orchestrierung aus Laravel |
| Gameserver | **Pelican Panel + Wings** via Application-API | eigenes Modul nur als Client/Verknüpfung |
| Tests | **Pest** | Bracket-Engine mit hoher Abdeckung |
| Runtime/Deploy | Docker Compose: **FrankenPHP** (App + Octane), Postgres, Redis, Reverb, Pelican, Wings | ein `compose.yml`, keine Varianten-Matrix wie v1 |
| Code-Qualität | Pint (Format), PHPStan/Larastan (Level ≥ 8), ESLint + Prettier (Vue) | CI via GitHub Actions |

## 5. Systemarchitektur

```
                        ┌────────────────────────────────────────────┐
 Teilnehmer ── Browser ─┤  Laravel 13 Monolith (FrankenPHP)          │
 Orga ──────── Browser ─┤   ├─ Inertia/Vue  (Teilnehmer-UI)          │
 Beamer ────── Browser ─┤   ├─ Filament     (Admin-Panel /admin)     │
                        │   ├─ Module (app/Modules/*)                │
 Discord ── Interactions┤   ├─ Interactions-Endpoint (/discord/...)  │
            (HTTP POST) │   ├─ Scheduler + Queue-Worker              │
                        │   └─ Reverb (WebSockets)                   │
                        └───────┬──────────────┬─────────────────────┘
                                │              │ REST (Application API)
                          PostgreSQL 16   ┌────┴─────────┐
                          Redis           │ Pelican Panel │──▶ Wings-Daemon(s)
                                          └──────────────┘    (Docker je Host)
                                          ┌──────────────────┐
                                          │ mumble-server     │◀── Mumble-Clients (LAN)
                                          │  + REST-Sidecar   │◀── Laravel (Channel-CRUD)
                                          └──────────────────┘
```

Ein Deployment, eine Datenbank, ein Auth-System. Discord und Pelican sind externe Systeme hinter je einem Service-Interface (`DiscordClient`, `PelicanClient`) — mockbar in Tests, austauschbar.

## 6. Modulschnitt

Modularer Monolith: `app/Modules/<Name>/` mit je eigenen Models, Actions, Policies, Filament-Resources, Events und Tests. Kommunikation zwischen Modulen über Laravel-Events und explizite Service-Interfaces — kein Modul greift in die Tabellen eines anderen.

### Kern-Entität: Event

Eine LAN-Party-Ausgabe (`Winter-LAN 2027`) ist ein `Event` mit Lifecycle `draft → announced → registration → live → finished → archived`. **Alles Organisatorische hängt an genau einem Event** (Turniere, Sitzplan, Zeitplan, Catering, Votes, LFG, Infoscreen). **Identität ist event-übergreifend** (User, Teams, Games) → Historie, Statistiken und Leaderboards über mehrere LANs entstehen automatisch.

### Module (Phase in Klammern, siehe Abschnitt 15)

1. **Identity** (M0–M1) — Discord-OAuth-Login, User-Profil (Nickname, Avatar, Bio, Steam-Link, Profilfarbe), Rollen `admin`/`orga`/`participant` via Policies. Kein Passwort-Login für Teilnehmer.
2. **Events** (M1) — Event-CRUD (Filament), Lifecycle, globaler „aktuelles Event"-Kontext für Teilnehmer-UI, Archiv-Ansicht vergangener Events.
3. **Registration** (M2) — Anmeldung zum Event, Ticket-Stufen (z. B. Früher Vogel/Standard, frei konfigurierbar), Bezahlstatus **manuell** durch Orga markierbar (v1-Realität: PayPal-QR auf der Display-Wall; echte Payment-Provider sind Backlog), personalisierter QR-Code, Vor-Ort-Check-in per QR-Scan (Orga-Handy genügt), Teilnehmerliste.
4. **Seating** (M2) — Sitzplan-Editor im Admin (Tische/Reihen als Raster mit x/y-Position), Teilnehmer wählen Platz bei/nach Anmeldung, Team-Nachbarschaft sichtbar, öffentliche „Wer sitzt wo"-Ansicht, optionale Felder je Platz (Switch-Port, IP) als Netzwerkdoku.
5. **Teams** (M3) — Globale Stamm-Teams (Name, Tag, Logo, Captain, Mitglieder, Join-Requests). Turnierteilnahme friert das Roster als Snapshot ein → Umbenennungen/Wechsel zerstören keine Historie (v1-Schwäche).
6. **Tournaments** (M3, Herzstück) —
   - Formate: Single Elimination, Double Elimination, Round Robin (Swiss: Backlog).
   - Anmeldung solo oder als Team, Auto-Team-Bildung (Shuffle) wie v1, Seeding (manuell + zufällig).
   - Check-in-Fenster (öffnet/schließt X Min vor Start, konfigurierbar), Auto-Start via Scheduler.
   - **Bracket-Engine als eigene, Pest-getestete Domain-Schicht** (`BracketGenerator`, `BracketProgressor`): erzeugt Matches inkl. Loser-Bracket-Progression, Byes, Grand Final. Kein externes Bracket-Paket (Lehre aus dem brackets-manager-Schema-Mismatch in v1).
   - Ergebnis-Erfassung **neu**: Teilnehmer melden Ergebnis, Gegner bestätigt; bei Konflikt → Dispute-Status für Orga. Forfeit/No-Show als expliziter Ausgang. (v1: nur Admin konnte Ergebnisse eintragen — Flaschenhals.)
   - Optimistic Locking auf Match-Updates (aus v1 übernehmen).
   - Bracket-Visualisierung als Vue-Komponente (Adaption der v1-Komponenten `BracketMatch`/`BracketRound`, Connector-Linien diesmal fertigstellen), live via Reverb.
7. **Schedule** (M4) — Zeitplan je Event: Turniere erscheinen automatisch, manuelle Slots (Essen, Siegerehrung, Filmabend), „Jetzt & gleich"-Widget, ICS-Export.
8. **Catering** (M4) — Sammelbestellungen: Orga öffnet Bestellfenster (z. B. Pizza mit Artikelliste), Teilnehmer wählen, Orga schließt Fenster und erhält Sammelliste + Kostenaufteilung pro Person, Bezahlt-Häkchen. Bewusst simpel; Kiosk/Guthaben: Backlog.
9. **Voting** (M4) — Game-Votes und generische Umfragen je Event (öffnen/schließen, 1 Stimme pro User, Live-Ergebnis). In v1 nur als DB-Schema vorhanden — hier fertig bauen.
10. **LFG** (M4) — Mitspielersuche je Event: Titel, Spiel, gesuchte Spieler, Skill-Level, Ablaufzeit; Announcements via Discord.
11. **Discord** (M2–M3, Querschnitt) —
    - `DiscordClient` (REST): Channels, Nachrichten, DMs, Rollen, Member-Voice-Move.
    - **Interactions-Endpoint** als Laravel-Route mit Ed25519-Signaturprüfung; Slash-Commands: `/tournament (list|info|checkin|bracket)`, `/lfg (create|list)`, `/schedule`, `/help` — dünne Wrapper, die dieselben Actions aufrufen wie das Web-UI.
    - Match-Text-Channels wie v1 (Team-Berechtigungen, Willkommens-Embed mit Mumble-Join-Link, Cleanup nach Match-Ende) — ausgelöst durch Laravel-Events (`MatchReady`, `MatchCompleted`), ausgeführt als Queue-Jobs. **Keine Discord-Voice-Channels** — Voice läuft über Mumble (Modul 12), das Voice-Move-Handling aus v1 entfällt ersatzlos.
    - Announcements (Turnier-Reminder 24 h/1 h, Ergebnisse, LFG) als Notifications; Deduplizierung in der DB statt In-Memory (v1-Bug: Duplikate nach Bot-Restart).
12. **Voice (Mumble)** (M3, Querschnitt) — [Mumble](https://www.mumble.info/) als Voice-Server am Event (niedrigste Latenz, self-hosted, LAN-lokal). `mumble-server`-Container im Compose-Stack; Administration über die Ice-API via **REST-Sidecar** (murmur-rest oder ein minimaler eigener Sidecar — PHP-Ice-Bindings sind unmaintained, daher bewusst dieses Muster). `MumbleClient`-Interface in Laravel (mockbar).
    - **Channel-Orchestrierung:** je Turnier ein Channel-Baum (`🏆 <Turnier>` → ein Channel pro Team), je Match temporäre Team-Channels; Erstellung/Umbenennung/Cleanup über dieselben Domain-Events wie die Discord-Text-Channels (`TournamentStarted`, `MatchReady`, `MatchCompleted`).
    - **Join-Links:** `mumble://host:port/<channel-pfad>` auf der Match-Seite, im Match-Embed (Discord) und auf dem Infoscreen; Kanal-Referenzen in `matches.voice_channels`/`teams`-Spalten.
    - Zugriff simpel halten: Server-Passwort pro Event (LAN-intern), keine Zertifikats-Registrierung im MVP.
13. **Infoscreen** (M5, ehem. Projector + Display-Wall) — Vollbild-Ansichten für Beamer: rotierende Szenen (Bracket, nächste Matches, Zeitplan, Ankündigung, Sitzplan, PayPal-/Beitrags-QR, Sponsorenlogos). Szenen + Dauer im Admin konfigurierbar, Sofort-Einblendung („Essen ist da!") via Reverb-Push, Winner-Animation (Konfetti) wie v1.
14. **GameServers** (M6) — `PelicanClient` gegen die Pelican Application-API: Server anlegen/starten/stoppen aus dem Admin, **Ein-Klick „Server für dieses Match"** aus dem Turnierkontext (Spiel → Egg-Mapping), Join-Info (IP:Port, Passwort, `connect`-String) automatisch in den Match-Discord-Channel und die Match-Seite. Teilnehmer-Serverliste („welche Server laufen") auf Infoscreen + Web. RCON/Datei-Manager/Konsole/Backups liefert Pelican selbst — wird **nicht** dupliziert; Orga-Deeplink ins Pelican-Panel genügt.
15. **Notifications** (M2, Querschnitt) — Laravel Notifications mit Kanälen `database` (In-App-Glocke), `discord` (eigener Channel-Treiber: Channel-Post oder DM), Mail optional. Benutzer-Präferenzen pro Kategorie.
16. **Stats** (M6) — Über Events hinweg: Turniersiege, Podiumsplätze, Teilnahmen, einfache Badges; Leaderboard-Seite. Fällt aus dem Event-Datenmodell heraus, bewusst klein gehalten.

### Explizit gestrichen / Backlog

- **TeamSpeak-Integration**: In v1 halb verdrahtet (5 Hintergrund-Tasks im Bot, Frontend nur Platzhalter). v2 ersetzt sie durch das **Mumble-Modul** (niedrigere Latenz, vollständig self-hosted) — TeamSpeak entfällt ersatzlos.
- **Discord-Voice-Match-Channels**: entfallen zugunsten Mumble; damit auch das komplette Voice-State-/Voice-Move-Handling aus v1.
- Payment-Provider (Stripe/PayPal-API), Kiosk mit Guthaben, Swiss-Format, Foto-Galerie, Sponsor-Verwaltung über Logos hinaus, automatisches LFG-Matching.

## 7. Datenmodell (Kern)

Eloquent-Migrationen, `snake_case`, Foreign Keys mit `cascadeOnDelete` wo sinnvoll. Auszug der zentralen Tabellen:

```
users                (id, discord_id UNIQUE, name, avatar_url, bio, steam_url, profile_color, role, timestamps)
events               (id, name, slug, status, location, starts_at, ends_at, max_participants, settings JSONB)
event_registrations  (id, event_id, user_id, ticket_type, status, paid_at, checked_in_at, qr_token UNIQUE)
seats                (id, event_id, label, pos_x, pos_y, meta JSONB)            -- meta: switch_port, ip …
seat_assignments     (id, seat_id UNIQUE, registration_id UNIQUE)
games                (id, name, slug, icon_path, min/max_team_size, pelican_egg_id NULLABLE, default_server_config JSONB)
teams                (id, name, tag, logo_path, owner_id)
team_members         (id, team_id, user_id, role, UNIQUE(team_id,user_id))
team_join_requests   (id, team_id, user_id, status, message)
tournaments          (id, event_id, game_id, name, format, status, team_size, max_entries, rules,
                      starts_at, checkin_opens_at, checkin_closes_at, settings JSONB, winner_entry_id)
tournament_entries   (id, tournament_id, team_id NULLABLE, user_id NULLABLE, display_name, seed,
                      checked_in_at, roster_snapshot JSONB, status)              -- genau eines von team_id/user_id
matches              (id, tournament_id, round, bracket, position, entry1_id, entry2_id,
                      score1, score2, winner_entry_id, status, scheduled_at, lock_version,
                      next_match_id, next_slot, loser_match_id, loser_slot,
                      discord_channels JSONB, voice_channels JSONB, server_link_id NULLABLE)  -- voice_channels: Mumble-Kanal-IDs/Pfade
match_reports        (id, match_id, reported_by, score1, score2, status[pending|confirmed|disputed], timestamps)
schedule_items       (id, event_id, title, type, starts_at, ends_at, ref_type/ref_id NULLABLE)
food_orders          (id, event_id, title, vendor, menu JSONB, opens_at, closes_at, status)
food_order_items     (id, food_order_id, user_id, selection JSONB, price_cents, paid_at)
polls                (id, event_id, question, type, opens_at, closes_at) + poll_options + poll_votes(UNIQUE(poll_id,user_id))
lfg_posts            (id, event_id, user_id, game_id, title, description, players_needed, skill_level, expires_at, status)
infoscreen_scenes    (id, event_id, type, config JSONB, duration_sec, sort, enabled)
server_links         (id, match_id NULLABLE, tournament_id NULLABLE, pelican_server_id, join_info JSONB, status)
notifications        (Laravel-Standard) · discord_outbox (id, kind, dedup_key UNIQUE, sent_at)  -- Announcement-Dedup
```

Entscheidungen darin:

- **`tournament_entries` vereinheitlicht Solo & Team** (v1 hatte drei parallele Wege: `tournament_enrollments`, `tournament_teams`, `participant*_id`-Spalten in `matches`). Matches referenzieren nur noch Entries.
- **`roster_snapshot`** friert das Team-Lineup zum Turnierstart ein.
- **`match_reports`** trägt den neuen Bestätigungs-/Dispute-Flow; `matches` bleibt der bestätigte Zustand.
- **`discord_outbox` mit `dedup_key`** ersetzt die In-Memory-Deduplizierung des v1-Bots.
- Icons/Logos als Dateien im Storage (`icon_path`), nicht Base64 in der DB (v1-Altlast).

## 8. Discord-Integration im Detail

- **OAuth:** Socialite Discord-Provider; beim ersten Login wird der User angelegt (`discord_id` als Identität). Kein Vertrauen in Client-IDs — Server-Session ist maßgeblich.
- **Interactions-Endpoint:** `POST /api/discord/interactions`, Middleware prüft Ed25519-Signatur (`X-Signature-Ed25519`). Commands werden bei Deploy per Artisan-Command (`discord:register-commands`) registriert. Antworten > 3 s nutzen Deferred Response + Follow-up aus einem Queue-Job.
- **Channel-Orchestrierung:** Domain-Events (`MatchReady`, `MatchCompleted`, `TournamentStarted`, …) → Listener → Queue-Jobs, die über `DiscordClient` Channels erstellen/löschen, Embeds senden, User aus Voice zurückverschieben. Retry + Backoff über die Queue; Kanal-IDs in `matches.discord_channels`.
- **Kein Gateway-Prozess.** Sollte je ein Echt-Event nötig werden (z. B. auf Nachrichten reagieren), wäre ein Mini-Gateway-Worker als separater Container nachrüstbar — bewusst nicht Teil des Plans.

## 9. Gameserver-Strategie

- **Pelican Panel + Wings** als eigenständige Container im selben Compose-Stack; Wings zusätzlich auf jedem weiteren Host (ersetzt v1-SSH-Tunnel-Eigenbau vollständig, inkl. Multi-Host).
- LANoMAT spricht die **Application-API** (Server-CRUD, Power-Actions, Status) über `PelicanClient`; API-Key im Config.
- **Egg-Mapping:** `games.pelican_egg_id` + `default_server_config` (Startparameter, Slots, Tickrate). Für Minecraft/CS2 existieren gepflegte Eggs; für CS 1.6/UT2004 werden Community-Eggs verwendet oder eigene aus den v1-Docker-Images (`goldsrc-engine:cs16`, `ut2004-server`) erstellt — die v1-Images bleiben als Referenz wertvoll.
- **Match-Integration:** „Server erstellen" am Match/Turnier → Job provisioniert via Pelican, pollt bis `running`, schreibt `server_links.join_info` und postet die Join-Info in den Match-Channel.
- **Risiko-Fallback:** Sollte Pelican sich für ein Spiel als unpraktikabel erweisen, wird nur für dieses Spiel ein manueller Modus genutzt (Orga trägt IP/Port händisch am Match ein). Ein Eigenbau-Orchestrator ist ausdrücklich **kein** Plan-B mehr.

## 10. Echtzeit-Konzept

Reverb-Channels je Kontext: `event.{id}` (Infoscreen-Steuerung, Announcements), `tournament.{id}` (Bracket-/Match-Updates), `presence` optional. Vue-Seiten abonnieren via Echo; Infoscreen reagiert auf Szenen-Pushes. Fallback bleibt normales Neuladen — kein Feature darf Echtzeit voraussetzen.

## 11. Repo-Struktur (neues Repo)

```
lanomat/
├── app/
│   ├── Modules/
│   │   ├── Identity/ Events/ Registration/ Seating/ Teams/
│   │   ├── Tournaments/        # inkl. Domain/Bracket/ (Generator, Progressor + Pest-Tests)
│   │   ├── Schedule/ Catering/ Voting/ Lfg/
│   │   ├── Discord/            # Client, Interactions, Jobs, Notification-Channel
│   │   ├── GameServers/        # PelicanClient, Jobs, server_links
│   │   ├── Infoscreen/ Stats/ Notifications/
│   ├── Filament/               # Panel-Provider; Resources liegen bei ihren Modulen
│   └── ...
├── resources/js/               # Inertia: Pages/, Components/ (shadcn-vue), Layouts/, echo.ts
├── database/migrations|factories|seeders/
├── routes/web.php|api.php (Interactions)|console.php
├── tests/                      # Pest: Unit (Bracket!), Feature (HTTP), Modul-weise
├── docker/ + compose.yml       # frankenphp, postgres, redis, reverb, horizon, pelican, wings
├── .github/workflows/ci.yml    # pint, larastan, pest, eslint, vue-tsc, build
└── CLAUDE.md / README.md
```

Konventionen: Code, Kommentare, Commits, Doku **Englisch**; Conventional Commits; Pint + PHPStan Level ≥ 8; Actions-Pattern (eine Klasse pro Anwendungsfall) statt fetter Controller/Services; Policies für jede Autorisierung; Feature-Tests pro Endpoint.

## 12. Teststrategie

- **Bracket-Engine:** höchste Priorität. Property-artige Pest-Tests: alle Teilnehmerzahlen 2–64, mit/ohne Byes, Double-Elim-Progression inkl. Grand-Final-Reset, Forfeits. Die Engine ist reine Domain-Logik ohne IO — vollständig deterministisch testbar.
- **Feature-Tests** je Modul-Endpoint (Anmeldung, Check-in, Ergebnis-Flow inkl. Dispute, Sitzplatzkonflikt = zwei User, ein Platz).
- **Discord/Pelican gemockt** über die Client-Interfaces; je ein Contract-Test gegen die echte API manuell ausführbar.
- CI-Gate: pint, larastan, pest, eslint, vue-tsc.

## 13. Deployment

Ein `compose.yml` (dev + prod via Env-Overrides, statt fünf Varianten wie v1): `app` (FrankenPHP/Octane), `queue` (Queue-Worker; Horizon optional), `reverb`, `postgres`, `redis`, `mumble` (mumble-server), `mumble-admin` (Ice-REST-Sidecar), `pelican`, `wings`. `.env.example` vollständig dokumentiert. Erst-Setup per `php artisan lanomat:install` (Migrationen, Admin aus Discord-ID, Command-Registrierung) — ersetzt `make-admin.sh`.

## 14. Datenübernahme aus v1

Keine automatische Migration (Neuaufsetzung = frischer Start). Optional (Backlog): Import-Command für `users` (Discord-IDs) und abgeschlossene Turniere als „Legacy-Event" für die Historie. Entscheidung erst nach M3.

## 15. Implementierungsphasen

Jede Phase endet mit etwas Benutzbarem; Reihenfolge = Wertbeitrag fürs nächste Event.

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **M0 — Fundament** | Repo, Docker-Stack, Laravel 13 + Filament + Inertia/Vue-Skeleton, CI, Discord-OAuth, Rollen | Login mit Discord, leeres Admin-Panel läuft |
| **M1 — Events & Identity** | Event-CRUD + Lifecycle, User-Profile, Event-Kontext in der UI | Orga kann eine LAN anlegen und ankündigen |
| **M2 — Anmeldung, Sitzplan, Notifications** | Registration + Tickets + QR-Check-in, Seating-Editor + Platzwahl, Notification-Grundgerüst, Discord-Announcements | Teilnehmer melden sich an und wählen Plätze |
| **M3 — Turniere, Discord & Mumble** | Teams, Bracket-Engine (getestet!), Turnier-Lifecycle, Check-in, Ergebnis-/Dispute-Flow, Bracket-UI live (Reverb), Match-Text-Channels + Slash-Commands, Mumble-Channel-Orchestrierung + Join-Links | Erstes voll digital geführtes Turnier |
| **M4 — Orga-Alltag** | Schedule + ICS, Catering-Sammelbestellung, Voting, LFG | Komplette Event-Organisation im Tool |
| **M5 — Infoscreen** | Szenen-System, Rotation, Sofort-Push, Winner-Animation | Beamer-Betrieb am Event |
| **M6 — Gameserver & Stats** | Pelican-Integration, Match-Server-Provisionierung, Serverliste, Leaderboards/Badges | Ein-Klick-Server aus dem Bracket |

MVP für die erste echte LAN = **M0–M3**; M4–M6 sind einzeln nachschiebbar.

## 16. Risiken & Gegenmaßnahmen

| Risiko | Einschätzung | Gegenmaßnahme |
|---|---|---|
| PHP/Laravel-Lernkurve | mittel | Filament/Eloquent-Konventionen strikt folgen; M0/M1 bewusst klein |
| Pelican-Eggs für CS 1.6/UT2004 | mittel | Früh in M6 testen; eigene Eggs aus v1-Docker-Images; manueller Modus als Fallback |
| Interactions-Endpoint braucht öffentliche HTTPS-URL (auch dev) | klein | dev via Tunnel (z. B. cloudflared); Commands funktionieren unabhängig vom Web-UI |
| Double-Elim-Engine unterschätzt | mittel | Eigene Domain-Schicht mit erschöpfenden Pest-Tests vor jeder UI-Arbeit (TDD) |
| Mumble-Ice-Sidecar (murmur-rest ist Flask/Ice, wenig gepflegt) | mittel | Sidecar hinter `MumbleClient`-Interface kapseln; Fallback: statischer Channel-Baum je Event (einmalig provisioniert) statt dynamischer Match-Channels |
| Zwei UI-Welten driften optisch | klein | Filament nur intern für Orga; öffentliche UI ausschließlich Inertia/Vue |

## 17. Offene Entscheidungen (vor M0 zu klären, blockieren den Plan nicht)

1. Modul-Struktur: schlichte `app/Modules/`-Ordner (empfohlen, weniger Magie) vs. `nwidart/laravel-modules`.
2. Deutsch oder Englisch als UI-Sprache (empfohlen: Deutsch, `lang/`-Dateien von Anfang an).
3. ~~TeamSpeak: endgültig streichen oder Backlog-Modul~~ → **Entschieden (2026-07-14): Voice läuft über Mumble**; TeamSpeak und Discord-Voice entfallen.
4. v1-Datenimport: ja/nein (Entscheidung nach M3).

---

*Grundlage: vollständiges Feature-Inventar von LANoMAT v1 (Frontend/DB, NestJS-Gameserver-Backend, Discord-Bot/TeamSpeak), erhoben am 2026-07-13 aus dem Repo-Stand `main@f029980`.*
