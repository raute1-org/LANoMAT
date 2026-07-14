# LANoMAT v2 — Implementierungs-Roadmap M0–M6

> **For agentic workers:** Dies ist die Master-Roadmap. Pro Phase existiert (bzw. entsteht beim Phasenstart) ein Detailplan in `docs/superpowers/plans/` mit bite-sized TDD-Steps. Für die Ausführung eines Detailplans: REQUIRED SUB-SKILL `superpowers:subagent-driven-development` oder `superpowers:executing-plans`.

**Goal:** Neuaufsetzung von LANoMAT als modularer Laravel-13-Monolith gemäß [Design-Dokument](../specs/2026-07-13-lanomat-v2-rebuild-design.md), in 7 Phasen mit je einem benutzbaren Ergebnis.

**Architecture:** Ein Laravel-13-Monolith (`app/Modules/*`), Filament v5 als Orga-Panel, Inertia v2 + Vue 3 als Teilnehmer-UI, Reverb für Echtzeit. Discord über REST + Interactions-Endpoint (kein Bot-Prozess), Voice über Mumble (Ice-REST-Sidecar), Gameserver über Pelican Panel.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5, Inertia v2, Vue 3, Tailwind v4, shadcn-vue, Reverb, Pest, PostgreSQL 16, Redis, Docker Compose (FrankenPHP), Mumble, Pelican.

## Global Constraints (gelten für jeden Task jeder Phase)

- Neues Repo `lanomat`; Code, Kommentare, Commits, Doku **Englisch**; UI-Texte Deutsch über `lang/de/*.php` bzw. Vue-i18n-freie einfache Props (keine hartkodierten Strings in Komponenten).
- Conventional Commits (`feat(scope): …`).
- PHP: Pint (Laravel-Preset), Larastan Level 8+, keine `mixed`-Rückgaben in eigenem Code. Vue: `<script setup lang="ts">`, ESLint + Prettier, keine `<style>`-Blöcke, nur Tailwind + shadcn-vue.
- Jede Autorisierung über Policies; nie Client-gelieferte User-IDs verwenden.
- Jedes Modul: `app/Modules/<Name>/` mit `Models/`, `Actions/`, `Policies/`, ggf. `Filament/`, `Jobs/`, `Events/`; Tests in `tests/Feature/<Name>/` und `tests/Unit/<Name>/`.
- Externe Systeme (Discord, Mumble, Pelican) nur über Interfaces (`DiscordClient`, `MumbleClient`, `PelicanClient`) in `app/Modules/<X>/Contracts/` — Tests laufen gegen Fakes, nie gegen echte APIs.
- TDD: Test zuerst, wo es eine testbare Verhaltenseinheit gibt; Scaffolding-Tasks enden mit einem Verifikationsschritt.
- Icons/Logos/Uploads im Laravel-Storage (`storage/app/public`), nie Base64 in der DB.
- **i18n-Gate (Erkenntnis M1):** Jede Phase, die `lang/de`-Keys hinzufügt, MUSS mindestens eine Feature-Test-Assertion auf ein übersetztes Label enthalten (`->where('labels.x', 'Übersetzter Text')`), und die Phasen-Abnahme enthält einen Locale-Smoke-Check. Hintergrund: M1 lieferte komplette deutsche Copy, die zur Laufzeit als rohe Keys renderte (`APP_LOCALE` stand auf `en`) — kein Task-Test prüfte Label-Inhalte.

---

## Phasenübersicht & Abhängigkeiten

```
M0 Fundament ─▶ M1 Events & Identity ─▶ M2 Anmeldung/Sitzplan/Notifications ─▶ M3 Turniere/Discord/Mumble
                                                                                  │
                                            M4 Schedule/Catering/Voting/LFG ◀────┤ (M4 braucht nur M2)
                                            M5 Infoscreen ◀──────────────────────┤ (Szenen nutzen M3-Brackets)
                                            M6 Gameserver & Stats ◀──────────────┘ (Match-Server braucht M3)
```

MVP für die erste LAN: **M0–M3**. M4, M5, M6 sind danach unabhängig voneinander nachschiebbar.

---

## M0 — Fundament

**Detailplan:** [2026-07-14-m0-fundament.md](2026-07-14-m0-fundament.md) (vollständig ausgearbeitet, sofort ausführbar)

**Ergebnis:** Neues Repo; Login mit Discord funktioniert; leeres Filament-Panel unter `/admin` nur für `orga`/`admin`; CI grün.

| # | Task | Kern-Dateien (neues Repo) |
|---|------|---------------------------|
| 0.1 | Repo + Laravel 13 via Vue-Starter-Kit (Inertia v2, Vue 3, Tailwind 4, shadcn-vue, Pest) | `composer.json`, `resources/js/*` |
| 0.2 | Dev-Infrastruktur: `compose.yml` (postgres:16, redis:7), `.env.example` | `compose.yml`, `.env.example` |
| 0.3 | Qualitäts-Tooling: Pint-Config, Larastan L8, GitHub-Actions-CI (pint, larastan, pest, eslint, build) | `pint.json`, `phpstan.neon`, `.github/workflows/ci.yml` |
| 0.4 | User-Modell umbauen: `discord_id` (unique), `role` (enum admin/orga/participant), `avatar_url`; Passwort nullable | `database/migrations/*_users…`, `app/Models/User.php` |
| 0.5 | Discord-OAuth via Socialite: Redirect/Callback, User-Upsert, Session-Login (TDD mit Socialite-Mock) | `app/Modules/Identity/…`, `routes/web.php` |
| 0.6 | Rollen & Policies: `Role`-Enum, `Gate::before` für admin, Middleware `EnsureRole` | `app/Enums/Role.php`, `app/Providers/AppServiceProvider.php` |
| 0.7 | Filament v5 installieren; Panel `/admin`; `canAccessPanel()` = role ∈ {admin, orga}; Filament-Login deaktiviert (Session kommt vom Discord-Login) | `app/Providers/Filament/AdminPanelProvider.php` |
| 0.8 | `lanomat:install`-Command: migrate, Admin-User aus Discord-ID anlegen | `app/Console/Commands/InstallCommand.php` |
| 0.9 | Modul-Konvention verankern: `app/Modules/`-Struktur, Beispielmodul-Test, `CLAUDE.md` + `README.md` fürs neue Repo | `CLAUDE.md`, `README.md` |

**Abnahme:** `gh workflow run ci` grün; lokal: Discord-Login legt User an (`role=participant`); `/admin` → 403 für participant, 200 für orga/admin; `php artisan lanomat:install --admin-discord-id=…` erzeugt Admin.

**Erkenntnisse aus M0 (Whole-Branch-Review, 2026-07-14):**

- **Plan-Bug korrigiert:** `role` gehört NICHT in `$fillable` (M0-Plan Task 4 hatte das fälschlich spezifiziert) — `role` ist das einzige Privilegien-Bit; Zuweisung nur explizit (Factory-States, InstallCommand). Regel für alle Folgephasen: privilegientragende Felder nie mass-assignable.
- **Test-Falle:** `phpunit.xml`-`<env>`-Einträge übersteuern `.env.testing` (das Starter-Kit setzte so sqlite `:memory:` — Tests liefen unbemerkt NICHT auf Postgres). Bei neuen Test-Env-Vars immer prüfen, welche Quelle gewinnt.
- **Fortify-Restfläche (Entscheidung für M1):** `POST /login` (Passwort), 2FA- und Passkey-Routen bleiben schlafend (Fortify-bedingt, mit `password = null` nicht nutzbar), aber die Settings-Security-Seite ist für Discord-User eine Sackgasse (`RequirePassword` unerfüllbar) → in M1: Security-Navigation/-Seite für passwortlose User ausblenden; Entscheidung über endgültiges Entfernen der Fläche spätestens M2.
- **Für M1 Task 1.6 (Profil):** `UpsertUserFromDiscord` überschreibt bei jedem Login `name`/`email` → Feld-Ownership definieren (Discord-owned vs. user-owned) bevor Profil-Editing kommt; E-Mail-Unique-Kollision zweier Discord-Accounts abfangen (aktuell 500 im Callback).
- Klein, bei Gelegenheit: `EnsureRole` wirft bare `ValueError` bei Tippfehler im Middleware-Parameter (beschreibende Exception wrappen); Migration-`down()` stellt NOT NULL auf email/password nicht wieder her; UI-Copy-Konvention (`lang/de/`) ab M1 formalisieren (Login.vue hat den Discord-Button-Text inline).

---

## M1 — Events & Identity

**Ergebnis:** Orga legt ein Event an und führt es durch den Lifecycle; Teilnehmer sehen Event-Seite und pflegen ihr Profil.

| # | Task | Interfaces (Produces) |
|---|------|----------------------|
| 1.1 | Migration + Model `Event` (`name, slug unique, status, location, starts_at, ends_at, max_participants, settings jsonb`) + Factory | `App\Modules\Events\Models\Event` |
| 1.2 | `EventStatus`-Enum (`draft, announced, registration, live, finished, archived`) + `TransitionEventStatus`-Action mit erlaubter Übergangs-Map; ungültige Übergänge werfen `DomainException` (TDD: alle Kanten testen) | `TransitionEventStatus::handle(Event $event, EventStatus $to): Event` |
| 1.3 | Filament `EventResource` (CRUD) + Status-Action-Buttons (rufen 1.2) | `/admin/events` |
| 1.4 | `CurrentEvent`-Resolver: aktuellstes Event mit Status ∈ {announced, registration, live}; als Inertia-Shared-Prop via Middleware | `CurrentEvent::get(): ?Event`, Prop `currentEvent` |
| 1.5 | Öffentliche Event-Seite (Inertia `Pages/Event/Show.vue`): Name, Zeitraum, Ort, Status-abhängige CTAs; Archiv-Liste vergangener Events | Route `/`, `/events/{slug}`, `/events` |
| 1.6 | Profil: Migration (`bio, steam_url, profile_color`), `UpdateProfile`-Action + Inertia-Seite `Pages/Profile/Edit.vue`; Zufalls-`profile_color` bei User-Erstellung (App-Code, kein DB-Trigger wie v1) | `PATCH /profile` |
| 1.7 | Öffentliches Profil `Pages/Profile/Show.vue` (`/users/{id}`) | — |

**Abnahme:** Feature-Tests: Lifecycle-Kanten, `CurrentEvent`-Auswahl, Profil-Update-Validierung. Manuell: Event „Testlan 2026" durchklickbar von draft → archived.

---

## M2 — Anmeldung, Sitzplan, Notifications, Discord-Basis

**Ergebnis:** Teilnehmer melden sich zum Event an, wählen einen Sitzplatz, werden vor Ort per QR eingecheckt; Discord-Announcements laufen.

**Erkenntnisse aus M1 (für den M2-Detailplan verbindlich):**

- **Erster M2-Task: öffentliche Event-Sichtbarkeit als Domain-Helper** (`Event::isPubliclyVisible(): bool` bzw. Scope `publiclyVisible()`), NICHT in die `EventPolicy` (deren `view()` heißt „darf ins Admin-Panel", orga-only — Überladen würde Filament brechen). Der Inline-Draft-404-Check in `EventPageController::show()` wird dabei auf den Helper umgestellt; Task 2.3 (Anmelde-CTA) ist der zweite Konsument.
- Filament: `slug`/öffentliche URL als read-only Feld/Spalte an der EventResource ergänzen (Orga kann den Link aktuell nirgends kopieren).
- CTA-Button auf der Event-Seite ist bis Task 2.3 inert — bei der Anmelde-Verdrahtung disabled/aria-Semantik mitliefern.
- Backlog (LAN-Scale akzeptiert, bei Gelegenheit): TOCTOU-Fenster bei E-Mail-Kollision und discord_id-Doppel-Login in `UpsertUserFromDiscord` (partial unique index / advisory lock); `labels`-Props sauber typisieren statt `Record<string, string>` mit Casts.

| # | Task | Interfaces (Produces) |
|---|------|----------------------|
| 2.1 | Migration + Model `EventRegistration` (`event_id, user_id unique zusammen, ticket_type, status[pending/confirmed/cancelled], paid_at, checked_in_at, qr_token unique`) | `Registration`-Model |
| 2.2 | Actions `RegisterForEvent` (prüft Status=registration, max_participants, Ticket-Typ aus `event.settings['tickets']`), `CancelRegistration` (TDD: voll, doppelt, falscher Status) | `RegisterForEvent::handle(Event, User, string $ticketType): EventRegistration` |
| 2.3 | Inertia-Anmeldeseite + „Meine Anmeldung" (Ticket, QR-Code-Anzeige via `bacon/bacon-qr-code`) | `/events/{slug}/register` |
| 2.4 | Filament: Registrations-RelationManager am Event (Suche, Paid-Toggle, CSV-Export) | — |
| 2.5 | QR-Check-in: Orga-Seite (Kamera-Scan via `vue-qrcode-reader` oder manuelle Token-Eingabe) → `POST /orga/checkin {qr_token}` → setzt `checked_in_at` (Policy: orga/admin; TDD: unbekannt/doppelt/falsches Event) | `CheckInRegistration::handle(string $qrToken): EventRegistration` |
| 2.6 | Seating: Migrationen `seats` (`event_id, label, pos_x, pos_y, meta jsonb`) + `seat_assignments` (`seat_id unique, registration_id unique`); `ClaimSeat`/`ReleaseSeat`-Actions (DB-Unique fängt Race, Test: 2 User × 1 Platz) | `ClaimSeat::handle(Seat, EventRegistration): SeatAssignment` |
| 2.7 | Filament Seat-Editor: Bulk-Anlage (Reihen × Spalten → Raster), Einzel-Edit (Label, Position, meta: switch_port, ip) | `/admin/events/{id}` Tab „Seats" |
| 2.8 | Teilnehmer-Sitzplan `Pages/Seating/Index.vue`: SVG-Raster aus `pos_x/pos_y`, eigener Platz wählbar/wechselbar, belegte Plätze mit Nickname (+ Team-Badge ab M3) | `/events/{slug}/seating` |
| 2.9 | Notifications-Grundgerüst: `database`-Channel + Glocken-Dropdown im Layout; Kategorien-Präferenzen (`users.notification_prefs jsonb`) | `App\Modules\Notifications\…` |
| 2.10 | Discord-Basis: `DiscordClient`-Interface + `HttpDiscordClient` (Bot-Token, `sendMessage`, `createChannel`, `deleteChannel`, `sendDm`, `upsertPermissionOverwrites`) + `FakeDiscordClient` für Tests; config `services.discord` | `App\Modules\Discord\Contracts\DiscordClient` |
| 2.11 | Discord-Notification-Channel (Notification → Channel-Post/DM) + `discord_outbox`-Tabelle mit `dedup_key unique`; Event-Announcements (Registration offen, 24 h/1 h-Reminder) als Scheduler-Command `lanomat:send-reminders` (TDD mit Time-Travel + Fake-Client) | Notification-Channel `discord` |

**Abnahme:** kompletter Anmelde-→Platzwahl-→Check-in-Durchlauf in Feature-Tests; Reminder feuert genau einmal (Outbox-Dedup-Test); manuell: Testnachricht landet im Discord-Channel.

---

## M3 — Teams, Turniere, Discord-Interactions, Mumble

**Ergebnis:** Ein Turnier läuft komplett digital: Anmeldung → Check-in → Auto-Start → Bracket live → Ergebnisse mit Bestätigung → Sieger. Match-Koordination via Discord-Text-Channel + Mumble-Voice.

### Teams

| # | Task |
|---|------|
| 3.1 | Migrationen `teams` (`name, tag, logo_path, owner_id`), `team_members` (`team_id, user_id, role, UNIQUE(team_id,user_id)`), `team_join_requests` (`status, message`); Models + Policies (nur Owner managt) |
| 3.2 | Actions: `CreateTeam`, `InviteToTeam`/`RequestToJoin`, `RespondToJoinRequest`, `LeaveTeam` (Owner kann nicht leaven ohne Übergabe) — TDD |
| 3.3 | Inertia-Seiten `Pages/Teams/{Index,Show,Edit}.vue` (Logo-Upload → Storage) + Filament `TeamResource` (Orga-Eingriff) |

### Bracket-Engine (reine Domain-Schicht, kein IO — höchste Testpriorität)

| # | Task | Interfaces (Produces) |
|---|------|----------------------|
| 3.4 | Wertobjekte in `app/Modules/Tournaments/Domain/`: `BracketMatch` (`round, bracket[winners/losers/finals], position, slot1, slot2, next{Match,Slot}, loserNext{Match,Slot}`), `Slot` (entryId \| bye \| pendingFrom) | readonly PHP-Klassen |
| 3.5 | `BracketGenerator::singleElimination(array $entryIds): BracketPlan` — Seeds, Byes, Rundenverkettung. Pest: n = 2…64, Bye-Verteilung, jede Kette endet im Finale | `BracketPlan` (Liste `BracketMatch`) |
| 3.6 | `BracketGenerator::doubleElimination(...)` — Winners/Losers-Verzahnung, Grand Final + Reset-Match. Pest: L-Bracket-Einstiegsrunden korrekt für n = 4, 8, 16, 6 (mit Byes) | — |
| 3.7 | `BracketGenerator::roundRobin(...)` — Circle-Method, jeder gegen jeden genau 1× | — |
| 3.8 | `BracketProgressor::apply(BracketPlan, matchId, score1, score2): BracketPlan` — Sieger weiter, Verlierer ins L-Bracket, Forfeit/No-Show als Ausgang, GF-Reset-Logik. Pest: komplette Turniere durchspielen (Property-Style: zufällige Ergebnisse, Invarianten prüfen: genau 1 Sieger, keine offenen Matches) | — |

### Turnier-Lifecycle

| # | Task |
|---|------|
| 3.9 | Migrationen `tournaments`, `tournament_entries` (Check: genau eines von `team_id`/`user_id`; `roster_snapshot jsonb`), `matches` (`lock_version`, `discord_channels jsonb`, `voice_channels jsonb`), `match_reports` |
| 3.10 | Enrollment: `EnrollSolo`, `EnrollTeam` (schreibt `roster_snapshot`), `WithdrawEntry`; Check-in-Fenster (`OpenCheckin`/`CloseCheckin` via Scheduler, `CheckInEntry`) — TDD inkl. Fenstergrenzen |
| 3.11 | `StartTournament`-Action: Auto-Team-Shuffle bei Solo-Team-Turnieren (wie v1), Seeding, ruft `BracketGenerator`, persistiert Matches, Status → live; als Job + Scheduler-Autostart. Transaktional, Test: Doppelstart unmöglich |
| 3.12 | Ergebnis-Flow: `SubmitMatchReport` (Teilnehmer), `ConfirmMatchReport` (Gegner → ruft `BracketProgressor`, `lock_version`-Guard), `DisputeMatchReport`; Filament: Dispute-Queue + Orga-Override. TDD: confirm/conflict/stale-lock |
| 3.13 | Reverb einrichten (`php artisan install:broadcasting` → Reverb wählen, Echo-Client-Setup, Compose-Service `reverb`); Domain-Events: `TournamentStarted`, `MatchReady`, `MatchCompleted`, `TournamentCompleted` (Broadcasting auf `tournament.{id}`) |
| 3.14 | Turnier-UI: `Pages/Tournaments/{Index,Show}.vue` — Anmelden/Check-in/Ergebnis melden; Bracket-Komponenten `BracketView/BracketRound/BracketMatchCard/BracketConnector` (SVG-Linien diesmal fertig); Echo-Subscription für Live-Updates |
| 3.15 | Filament `TournamentResource`: CRUD, Entries-RelationManager, Start-Button, Dispute-Handling |

### Discord-Interactions & Match-Channels

| # | Task |
|---|------|
| 3.16 | Interactions-Endpoint `POST /api/discord/interactions`: Ed25519-Middleware (`sodium_crypto_sign_verify_detached`), PING/PONG, Command-Router; `discord:register-commands`-Artisan-Command. TDD: Signatur gültig/ungültig, PING |
| 3.17 | Slash-Commands `/tournament list|info|checkin|bracket`, `/help` — dünne Wrapper um M3-Actions, Deferred Response + Follow-up-Job bei > 3 s |
| 3.18 | Match-Text-Channels: Listener auf `MatchReady` → `CreateMatchChannelJob` (Channel, Overwrites für beide Rosters, Willkommens-Embed mit Mumble-Link + Match-URL); `MatchCompleted` → Ergebnis-Announcement + `CleanupMatchChannelJob` (delayed). Tests gegen `FakeDiscordClient` |

### Mumble

| # | Task |
|---|------|
| 3.19 | Compose: `mumble` (`mumbleveil/mumble-server` o. offizielles Image, Ice aktiviert + Ice-Secret) + `mumble-admin` (murmur-rest-Container; falls unbrauchbar: eigener ~100-Zeilen-FastAPI-Sidecar in `docker/mumble-admin/` mit Endpoints `GET/POST/PATCH/DELETE /channels`) |
| 3.20 | `MumbleClient`-Interface (`createChannel(name, parentId, temporary): MumbleChannel`, `renameChannel`, `deleteChannel`, `listChannels`) + `HttpMumbleClient` + `FakeMumbleClient`; config `services.mumble` (host, port, rest_url, ice_secret, server_password) |
| 3.21 | Voice-Orchestrierung: `TournamentStarted` → Channel-Baum (`🏆 <Turnier>` + Team-Channels); `MatchReady` → temporäre Match-Team-Channels, IDs in `matches.voice_channels`; `TournamentCompleted` → Cleanup. Join-Link-Helper `mumble://{host}:{port}/{pfad}` auf Match-Seite + im Discord-Embed. Tests gegen Fake |

**Abnahme:** End-to-End-Feature-Test „8 Solo-Spieler, Double-Elim, zufällige Ergebnisse → genau ein Sieger, alle Channels erstellt & aufgeräumt (Fakes)"; manuell auf Test-Discord + lokalem Mumble: ein 4-Spieler-Testturnier komplett durchspielen.

---

## M4 — Schedule, Catering, Voting, LFG

**Ergebnis:** Der komplette Orga-Alltag eines Events läuft im Tool.

| # | Task |
|---|------|
| 4.1 | Schedule: Migration `schedule_items` (`type, ref_type/ref_id nullable`); Turniere erscheinen automatisch (Listener auf Tournament-CRUD); Filament-Verwaltung; `Pages/Schedule/Index.vue` mit „Jetzt & gleich"-Widget; Slash-Command `/schedule` in den Command-Router einhängen |
| 4.2 | ICS-Export `GET /events/{slug}/schedule.ics` (`spatie/icalendar-generator`), Test: validiertes ICS |
| 4.3 | Catering: Migrationen `food_orders` (`menu jsonb, opens_at, closes_at, status`), `food_order_items` (`selection jsonb, price_cents, paid_at`); Actions `PlaceFoodOrderItem` (nur im Fenster), `CloseFoodOrder` → Sammelliste + Kostenaufteilung; Filament (Fenster anlegen, Paid-Toggle, Summenansicht); `Pages/Catering/Show.vue` |
| 4.4 | Voting: `polls/poll_options/poll_votes` (UNIQUE(poll_id,user_id)); Actions `CastVote` (nur offen, einmal); Filament + `Pages/Polls/Show.vue` mit Live-Ergebnis (Reverb `event.{id}`) |
| 4.5 | LFG: Migration `lfg_posts` (Ablauf via `expires_at`); CRUD-Actions + Expiry-Scheduler; `Pages/Lfg/Index.vue`; Discord-Announcement (Outbox-Dedup); Slash-Command `/lfg create|list` in den Command-Router einhängen |

**Abnahme:** Feature-Tests je Modul (Fenster-/Frist-Grenzen, Doppel-Stimme, Ablauf); manuell: Pizza-Sammelbestellung mit 3 Test-Usern inkl. Kostenaufteilung.

---

## M5 — Infoscreen

**Ergebnis:** Beamer-taugliche Vollbild-Rotation, live steuerbar; Produktions-Deployment steht.

| # | Task |
|---|------|
| 5.1 | Migration `infoscreen_scenes` (`type, config jsonb, duration_sec, sort, enabled`); Filament-Verwaltung (Szenen sortieren, an/aus) |
| 5.2 | Screen-Shell `Pages/Screen/Show.vue` (Route `/screen/{event}`, ohne Auth lesbar, ohne Navigation, dark): Rotations-Engine (client-seitiger Timer aus Szenen-Config), Reverb-Subscription `event.{id}` für `SceneOverride`-Push („Essen ist da!") und Config-Reload |
| 5.3 | Szenen-Komponenten: `SceneBracket` (nutzt M3-`BracketView` in Beamer-Größe), `SceneUpcomingMatches`, `SceneSchedule`, `SceneAnnouncement`, `SceneSeatmap`, `ScenePaymentQr` (Beitrags-QR wie v1-Display-Wall), `SceneSponsors` (Logo-Grid aus Uploads) |
| 5.4 | Winner-Moment: `MatchCompleted` bei Finals → Konfetti-Overlay + „WINNER"-Einblendung (Adaption v1) |
| 5.5 | Orga-Fernbedienung: Filament-Action „Sofort einblenden" (Szene + Dauer) → Broadcast |
| 5.6 | Produktions-Deployment: FrankenPHP-`app`-Image (`docker/Dockerfile`), Compose-Profile `prod` (app, queue, reverb, scheduler), `.env.example` final, Deploy-Doku in README; `lanomat:install` im Container verifiziert |

**Abnahme:** Screen läuft 30 min stabil im Kiosk-Browser durch alle Szenen; Sofort-Einblendung erscheint < 2 s; `docker compose --profile prod up` liefert lauffähiges System.

---

## M6 — Gameserver (Pelican) & Stats

**Ergebnis:** Ein-Klick-Server aus dem Match-Kontext; Leaderboards über Events.

| # | Task |
|---|------|
| 6.1 | Compose: `pelican` + `wings` Services; Pelican einrichten (Node, Eggs für Minecraft/CS2 importieren); Doku `docs/pelican-setup.md`. **Spike zuerst:** CS 1.6/UT2004-Eggs aus v1-Docker-Images (`goldsrc-engine:cs16`, `ut2004-server`) bauen und verifizieren — Ausgang entscheidet, ob diese Spiele Ein-Klick oder manuellen Modus bekommen |
| 6.2 | `PelicanClient`-Interface (`createServer(eggId, config): PelicanServer`, `getServer(id)`, `powerAction(id, action)`, `deleteServer(id)`) + `HttpPelicanClient` (Application-API, Token) + Fake; `games.pelican_egg_id` + `default_server_config jsonb` Migration |
| 6.3 | Migration `server_links` (`match_id/tournament_id nullable, pelican_server_id, join_info jsonb, status`); `ProvisionMatchServerJob`: erstellen → Status-Polling (Queue-Retry) → `join_info` schreiben → Embed-Update im Match-Channel + Match-Seite; `TournamentCompleted` → Server-Cleanup-Job. Manueller Modus: Orga trägt `join_info` händisch am Match ein (Fallback-UI) |
| 6.4 | UI: Filament-Server-Übersicht (Power-Actions, Deeplink ins Pelican-Panel); Teilnehmer-Serverliste `Pages/Servers/Index.vue` + Infoscreen-Szene `SceneServers` |
| 6.5 | Stats: Query-Schicht über `tournaments/matches/entries` (Siege, Podien, Teilnahmen je User/Team, event-übergreifend); `Pages/Stats/Leaderboard.vue`; Badges minimal (`first_win`, `hattrick`, `veteran` ab 3 Events) als berechnete Werte, keine eigene Tabelle |

**Abnahme:** Feature-Test Provisioning-Flow gegen Fake (inkl. Poll-Retry + Fehlerpfad → manueller Modus); manuell: Minecraft-Server aus Match-Kontext erstellt, Join-Info erscheint in Discord-Embed und auf der Match-Seite; Leaderboard zeigt Daten aus 2 Test-Events.

---

## Arbeitsweise

1. **Detailpläne just-in-time:** Vor jedem Phasenstart wird aus dieser Roadmap der Detailplan der Phase erzeugt (Format wie [M0-Plan](2026-07-14-m0-fundament.md): bite-sized Steps, kompletter Code, TDD). Roadmap-Task-Nummern bleiben als Referenz erhalten.
2. **Jede Phase endet mit:** grüner CI, Abnahme-Checkliste erfüllt, Tag `m<N>` im Repo.
3. **Roadmap ist lebendes Dokument:** Erkenntnisse einer Phase (z. B. Ausgang des Pelican-Spikes 6.1) werden hier nachgetragen, bevor der nächste Detailplan entsteht.
