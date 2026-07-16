# LANoMAT v2 — Implementierungs-Roadmap M0–M12

> **For agentic workers:** Dies ist die Master-Roadmap. Pro Phase existiert (bzw. entsteht beim Phasenstart) ein Detailplan in `docs/superpowers/plans/` mit bite-sized TDD-Steps. Für die Ausführung eines Detailplans: REQUIRED SUB-SKILL `superpowers:subagent-driven-development` oder `superpowers:executing-plans`.

**Goal:** Neuaufsetzung von LANoMAT als modularer Laravel-13-Monolith gemäß [Design-Dokument](../specs/2026-07-13-lanomat-v2-rebuild-design.md), in 7 Phasen mit je einem benutzbaren Ergebnis.

**Architecture:** Ein Laravel-13-Monolith (`app/Modules/*`), Filament v5 als Orga-Panel, Inertia v2 + Vue 3 als Teilnehmer-UI, Reverb für Echtzeit. Discord über REST + Interactions-Endpoint (kein Bot-Prozess), Voice über Mumble (Ice-REST-Sidecar), Gameserver über Pelican Panel.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5, Inertia v2, Vue 3, Tailwind v4, shadcn-vue, Reverb, Pest, PostgreSQL 16, Redis, Docker Compose (FrankenPHP), Mumble, Pelican.

## Produktleitlinien (übergeordnet, ziehen sich durch alle Phasen)

- **10-Minuten-Prinzip:** Vom Start bis zum Zocken max. 10 Minuten. Jede Feature-Entscheidung wird daran gemessen — Presets statt Config-Gefummel, Ein-Klick statt Formular-Marathon, sinnvolle Defaults vor Vollständigkeit. Wo ein Feature Aufwand für den Nutzer erzeugt, muss es einen Ein-Klick-Pfad geben.
- **Contracts konsequent:** Jedes externe System steckt hinter einem austauschbaren Contract (`DiscordClient`, `VoiceClient`, `PelicanClient`, künftig `MusicClient`/OAuth-Provider-Adapter). Backends (Voice: Mumble/TeamSpeak; Gameserver: Pelican/eigene Engine) müssen pro Installation wählbar sein, ohne dass Aufrufer-Code sich ändert. Das ist die technische Absicherung, dass „austauschbar wie eine Unterhose" auch nach Monaten noch gilt.
- **Discord verstärkt, ersetzt nie** (Feature-Input Runde 2, 2026-07-15): Jede Info und jede Aktion, die über Discord läuft, ist AUCH auf der Seite les- und bedienbar. Die **Glocke/In-App-Notification ist die Wahrheit**, die Discord-DM der Spiegel je nach User-Präferenz (in M2 bereits so angelegt). Discord bleibt der bequemere Weg, wo er schlanker ist (Handy-DMs, Slash-Commands), aber wer kein Discord offen hat, verpasst nichts. **Konkreter offener Punkt:** die Event-Announcements gehen aktuell NUR in den Discord-Channel (M2.11, direkter `DiscordClient::sendMessage`) — sie gehören zusätzlich in Glocke + Startseite (beim nächsten Anfassen des Announcement-Pfads nachziehen). Bewusst NICHT gemeint: ein eigener Web-Chat (da ist Discord schlicht besser).

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

M7 Infra & Betrieb (Backlog, aus Issues nach LAN 2025) — unabhängig, jederzeit nachschiebbar

M8  Voice-Multiprovider ─┐
M9  Identity+ ───────────┤ Post-MVP (Feature-Input R1, 2026-07-15), ohne festes Datum, nach M4–M7
M10 Präsenz & Casting ───┘   (M9 braucht vorab die Gruppen-Fusions-Entscheidung; M10 sinnvoll nach M6; Präsenz gewünscht ZUERST post-MVP)

M11 LAN-Radio/Jukebox ───┐ Post-MVP (Feature-Input R2, 2026-07-15), neue Module, null Eile
M12 Post-/Pre-LAN-Content ┘   (Galerie/Recap/News + Countdown-Seite; braucht Infoscreen M5 + Voting M4)
```

MVP für die erste LAN: **M0–M3**. M4, M5, M6 sind danach unabhängig voneinander nachschiebbar. M7 bündelt die Infra-/Betriebs-Wünsche aus den GitHub-Issues (erstellt nach der LAN 2025-11) und ist ohne Abhängigkeit zu den Feature-Phasen umsetzbar. **M8–M10** stammen aus Feature-Input R1, **M11–M12** aus Feature-Input R2 (beide 2026-07-15) — als eigene Post-MVP-Milestones angelegt (Details unten im Backlog-Abschnitt). Viele R2-Wünsche sind zusätzlich **direkt in die offenen Phasen M5/M6/M7/M8 eingearbeitet** (dort als Tasks/Notes markiert „Feature-Input R2"), weil sie Aufsätze auf genau das sind, was diese Phasen ohnehin bauen.

**Zieltermin (Stand 2026-07-14):** alle Phasen bis **2026-07-24** (Ende nächster Woche). M0–M2 abgeschlossen; M3 bis 17.07., M4 bis 20.07., M5 bis 22.07., M6/M7 bis 24.07. Termine als Milestone-Fälligkeitsdaten + Projects-Zeitachse (Board #2) gepflegt.

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

**Erkenntnisse aus M2 (Whole-Branch-Review, 2026-07-14):**

- **Seat-Editor-Abweichung von der Roadmap-Tabelle:** Task 2.7 ist entgegen der Tabellenspalte oben ("`/admin/events/{id}` Tab „Seats"") als eigenständige Filament-`SeatResource` unter `/admin/seats` umgesetzt (Bulk-Grid-Anlage per Formular, Einzel-Edit inkl. `meta.switch_port`/`meta.ip`, Occupancy-Warnung im Lösch-Modal). Grund: Seats sind pro Event global adressierbar (Netzwerk-Meta, Occupancy-Status) und ein eigenständiger Resource-Index ist für Orga-Alltag (Suche/Filter über viele Plätze) praktikabler als ein Event-Tab. Die Tabellenzeile oben ist als historisch zu lesen; verbindlich ist diese Erkenntnis.
- **Discord-Zustellwege bewusst getrennt:** `DiscordChannel` (Notification-Channel, `app/Modules/Discord/Channels/DiscordChannel.php`) ist der Weg für **user-adressierte** Nachrichten (DM, prefs-aware über `NotificationPreferences`) — registriert als Klassen-String-Channel via `Notification::via()` (`DiscordDirectMessage::via()` gibt `[DiscordChannel::class]` zurück), nicht über `ChannelManager::extend()`. **Broadcasts** (Registration-open-Announcement, 24h/1h-Reminder) laufen bewusst **direkt** über `DiscordClient::sendMessage()` gegen den konfigurierten Announce-Channel — sie gehen NICHT durchs Notification-System, weil es keinen einzelnen "Notifiable" gibt. Für M3 (Match-Ready-DMs, LFG-Pings) ist `DiscordChannel` der etablierte Carrier; für neue Channel-weite Announcements den direkten `DiscordClient`-Weg wiederverwenden, nicht künstlich in Notifications pressen.
- **Outbox-Insert-before-send-Tradeoff:** `DiscordOutboxGuard::once()` (`app/Modules/Discord/Support/DiscordOutboxGuard.php`) inserted die `discord_outbox`-Zeile mit `dedup_key` **vor** dem eigentlichen Versand, markiert `sent_at` erst danach. Tradeoff bewusst gewählt: ein Crash zwischen Insert und Versand lässt eine Nachricht **verloren** gehen (kein Retry), verhindert aber garantiert **doppelten** Versand bei Retry/Racing — für LAN-Announcements ("verloren" ist unauffällig, "doppelt" nervt) die richtige Seite. Die `QueryException`-Behandlung ist auf SQLSTATE `23505` (unique violation) verengt; jeder andere Fehler wird weitergeworfen statt fälschlich als "bereits gesendet" verschluckt zu werden.
- **Lock-Order-Konvention etabliert:** `RegisterForEvent` (`app/Modules/Registration/Actions/RegisterForEvent.php`) sperrt zuerst die **Parent-Event-Zeile** (`Event::lockForUpdate()`) und liest Kapazität/Registrierungen erst danach — ein `FOR UPDATE` auf den (potenziell leeren) Child-Rows würde bei einem brandneuen Event nichts sperren und einen Phantom-Read erlauben (zwei gleichzeitige Erstregistrierungen bei `max_participants=1` könnten beide durchkommen). Regel für alle Folgemodule mit ähnlichem "Kapazität über Child-Tabelle prüfen"-Muster (Turnier-Entries, Essensbestellungen in M4): **immer die Parent-Aggregatzeile zuerst sperren**, dann Child-Reads sind danach sicher ohne eigenes Row-Lock.
- **Registrierungs-Reaktivierungs-Semantik:** Eine stornierte Registrierung (`status = cancelled`) wird bei erneuter Anmeldung **in derselben Zeile reaktiviert** statt eine neue Zeile einzufügen (`(event_id, user_id)` ist unique unabhängig vom Status). Dabei wird `qr_token` **neu generiert** (der `creating`-Hook, der den Token normalerweise setzt, feuert nur beim Insert) — der alte Token könnte während der Stornierung sichtbar/geteilt gewesen sein und darf nicht gültig bleiben. Kapazität wird bei Reaktivierung erneut geprüft, unter demselben Parent-Row-Lock wie eine Neuanmeldung.
- **Seat-Release bei Storno ist entgegen der ursprünglichen Annahme #5 im Brief doch verdrahtet:** `CancelRegistration` dispatcht `RegistrationCancelled` (nur bei echtem Statuswechsel, nicht beim idempotenten No-op), `Seating\Listeners\ReleaseSeatOnCancellation` hört darauf und ruft `ReleaseSeat::handle()`. Registriert in `AppServiceProvider::boot()`. Damit ist die modulübergreifende Kopplung Registration→Seating sauber über ein Domain-Event gelöst, kein Fremdzugriff auf die andere Modul-Tabelle.
- **QR-Lib:** `bacon/bacon-qr-code` (^3.1) wie im Design vorgesehen; SVG-Rendering über `BaconQrCode\Renderer\Image\SvgImageBackEnd`, keine Bild-Bibliothek/Base64 nötig.
- **Scheduler-Registrierungsort:** `routes/console.php` via `Schedule::command('lanomat:send-reminders')->everyFiveMinutes()` — konsistent mit dem Laravel-11+-Standardmuster, kein `bootstrap/app.php`-`withSchedule()` nötig, da es der einzige Scheduler-Eintrag im Repo ist.
- **i18n-Gate eingehalten:** `.env.testing` setzt `APP_LOCALE=de` (Lehre aus M1 bereits umgesetzt); jede Teilnehmerseite (Registrierung, Sitzplan, Check-in, Glocke) und Filament-Fläche (SeatResource-Grid-Label) hat mindestens eine Feature-Assertion auf übersetztes Label.

---

## M3 — Teams, Turniere, Discord-Interactions, Mumble

**Ergebnis:** Ein Turnier läuft komplett digital: Anmeldung → Check-in → Auto-Start → Bracket live → Ergebnisse mit Bestätigung → Sieger. Match-Koordination via Discord-Text-Channel + Mumble-Voice.

**Vorgaben aus dem M2-Branch-Review (für den M3-Detailplan verbindlich):**

- **Discord unter Last:** Bevor M3 Channels in Serie erstellt / DMs aus Web-Requests sendet: `AnnounceRegistrationOpen`-Listener und `DiscordDirectMessage`-Versand queuen (`ShouldQueue`), `HttpDiscordClient` bekommt `Http::retry` + 429-Rate-Limit-Handling. Outbox: Retry-Sweep für `sent_at IS NULL`-Zeilen (> 5 min) im Scheduler erwägen; ein fehlgeschlagener Send darf die Restschleife nicht abbrechen.
- **Shared-Prop-Kosten:** `unreadNotifications` ist unbounded und lädt auf jeder Seite (auch layout-losen ohne Glocke) — `->take(15)` + `Inertia::optional` beim ersten M3-Task, der die Middleware anfasst.
- Klein: `GenerateSeatGrid` Formular braucht `maxValue` (rows/cols); `toggle_paid`/`export_csv` bekommen explizites `->authorize()` sobald der RelationManager angefasst wird; TS-`RegistrationStatus`-Union bei Enum-Änderungen mit Codegen ersetzen; Seat-Fehlermeldung könnte Constraint-Namen unterscheiden (registration_id- vs seat_id-Verletzung).

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

### Erkenntnisse M3 (laufend, während der Umsetzung)

- **Double-Elimination nur für Teilnehmerzahl n ∈ {2, 4, 6, 8, 16}.** Der `BracketGenerator::doubleElimination` transkribiert die LB-Verzahnungstabellen nur für Bracketgrößen {4, 8, 16} (aus `Drarig29/brackets-manager.js`, per Brute-Force für n=8 rematch-frei verifiziert) und wirft für andere Größen laut. Zusätzlich konvergiert der `BracketProgressor` bei DE-Brackets mit mehr Byes als n=6 nicht (ein WB-Match kann zwei Bye-Feeder haben → dauerhaft totes LB-Match). `StartTournament` guardet die DE-Teilnehmerzahl daher auf {2,4,6,8,16} und wirft sonst eine `TournamentException`. **Offene Erweiterung:** 32/64/128-Intake-Tabellen + Bye-tolerante Progression für beliebige DE-Feldgrößen (eigener Task mit eigener Testabdeckung; SE ist bereits n=2..64).
- **Lifecycle: `StartTournament` besitzt allein den `→ Live`-Übergang.** `CloseCheckin` als Status-Transition entfernt — das Check-in-Ende ist zeitgesteuert (`checkin_closes_at`, in `CheckInEntry` geprüft), der 5-Status-Enum bleibt (Draft, Enrollment, CheckIn, Live, Finished). Der Scheduler-Tick macht `OpenCheckin` und dispatcht am `starts_at` den `StartTournamentJob`, der `CheckIn`/`Enrollment → Live` schaltet und das Bracket generiert (Doppelstart via Status-Guard + Row-Lock unmöglich).
- **Domain-Engine-Konvention:** `BracketMatch::isDecided()` (früher `isComplete()`); Slots, die wegen eines Upstream-Byes nie befüllt werden, lässt der Progressor auto-advancen (analog zur SE-Bye-Auflösung).
- **`GameMatch`-Modellname:** `Match` ist PHP-reserviert (Match-Expression seit PHP 8.0), daher heißt das Eloquent-Model `GameMatch` (Tabelle bleibt `matches`). Betrifft nur den Klassennamen/Imports — Domain-Schicht und `MatchProgression` arbeiten ohnehin nur mit primitiven IDs.
- **Mumble-Sidecar: eigener FastAPI-Ice-REST-Dienst, kein `murmur-rest`.** `murmur-rest` (github.com/alfg/murmur-rest) wurde geprüft und verworfen — letzter echter Commit 2024-07, Flask + veraltetes Ice-Binding, nicht für eine aktuelle Mumble/Ice-Kombination gepflegt. Stattdessen: ein minimaler, zweckgebundener FastAPI-Sidecar (`docker/mumble-admin/app.py`), der nur das implementiert, was `MumbleClient` braucht (Channel list/create/rename/delete), spricht das Murmur-Ice-Interface (`Murmur.ice`, stabile 1.4.x-Slice) über die `python3-zeroc-ice`-Ubuntu-Paket-Bindung an (kein manylinux-Wheel verfügbar; muss zur Ice-ABI 3.7 des offiziellen `mumblevoip/mumble-server`-Images passen). Auth: Shared-Secret-Bearer-Token (`MUMBLE_ADMIN_TOKEN`, Default = `MUMBLE_ICE_SECRET`). Der Ice-Port (6502) wird nicht auf den Host published, nur `mumble-admin` erreicht ihn übers Compose-Netzwerk. Entscheidung ist contract-isoliert (`MumbleClient`) — kein Downstream-Impact auf Tests oder andere Tasks.
- **Reverb-Compose-Service:** `reverb` läuft mit `php artisan reverb:start --host=0.0.0.0 --port=8080` im Container, aber auf einem **non-default Host-Port 8081** (`ports: ['8081:8080']`) — Port 8080 ist lokal häufig von anderen Dev-Setups belegt. Analog zu Postgres (5434) und Redis (6380) folgt Reverb damit der Projekt-Konvention "dev-Ports absichtlich nicht default".
- **Bracket-Persistenz-Bye-Entscheidung:** `BracketPersister::persist()` lässt Byes (und daraus resultierende Bye-Ketten) bereits **vor** dem Schreiben der `GameMatch`-Zeilen über den `BracketProgressor` auflösen (`resolveByes()`, iterativ bis zum Fixpunkt). Ein Bye-Match wird daher direkt als `Completed` mit gesetztem `winner_entry_id` persistiert, und der reale Entrant steht schon im Folge-Match-Slot — es gibt nach der Persistenz nie ein offenes/spielbares Bye-Match. Das ist dieselbe Auto-Advance-Logik, die der Progressor auch mitten im laufenden Turnier verwendet, also können Start-Zeit- und Live-Bye-Auflösung nie auseinanderlaufen.
- **`MatchProgression` ist die einzige Domain↔DB-Brücke für gespielte Ergebnisse** (das Gegenstück zu `BracketPersister` für die initiale Generierung): rekonstruiert einen `BracketPlan` aus den `GameMatch`-Zeilen eines Turniers (Zeilen-IDs = Domain-Match-IDs, keine Übersetzung nötig), wendet `BracketProgressor::apply()` an, diffed und schreibt nur geänderte Zeilen zurück, dispatcht `MatchCompleted`/`MatchReady`/ggf. `TournamentCompleted`. Die Domain-Engine selbst bleibt vollständig IO-frei; nur diese Klasse kennt beide Welten.
- **Wichtige Konsequenz für Live-Wiring:** `MatchReady` wird **nur** von `MatchProgression::apply()` dispatcht — also nur für Matches, die durch Fortschritt (ein Vorgänger-Match wurde entschieden) spielbar werden. Die anfänglichen Winners-Bracket-Runde-1-Matches werden von `BracketPersister` direkt mit Status `Ready` angelegt und lösen **kein** `MatchReady` aus; für sie werden also nie ein Discord-Match-Channel oder Mumble-Match-Voice-Channels provisioniert (nur der Turnier-Channel-Baum auf `TournamentStarted`). Das M3-E2E-Abnahmetest (`DoubleElimE2ETest`) berücksichtigt das explizit — die Channel-Assertions gelten nur für Matches, die tatsächlich über `MatchReady` erreicht wurden (ab WB-Runde 2 aufwärts sowie Losers-Bracket/Finals). **Offene Notiz für später:** falls Runde-1-Match-Channels gewünscht sind, bräuchte es einen zusätzlichen Listener auf `TournamentStarted`, der für jedes initial-`Ready`-Match synthetisch `MatchReady` nachfeuert.
- **Discord/Voice unter Last:** `HttpDiscordClient` retryt nur transiente Fehler (Verbindungsfehler, HTTP 429/5xx) via `Http::retry()`, mit Backoff aus Discords `Retry-After`-Header bei 429 — 4xx-Fehler (außer 429) werden sofort durchgereicht, da ein Retry sie nicht heilt. Alle Sends laufen über `ShouldQueue`-Jobs/Listener (nie inline in der Bracket-Progression-Transaktion). `DiscordOutbox` + `SweepOutboxCommand` fangen liegen gebliebene Sends (`sent_at IS NULL` länger als 5 Minuten) im Scheduler-Tick ab, wobei ein einzelner Fehler die Sweep-Schleife nicht abbricht.

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

### Erkenntnisse M4 (Umsetzung + Whole-Branch-Review, 2026-07-15)

**Getaggt `m4`.** 19 Tasks über 4 neue Module (`Schedule`, `Catering`, `Voting`, `Lfg`) + Discord-Slash-Commands `/schedule` und `/lfg`; ~750 Tests grün, alle sechs Gates grün. Umgesetzt via `subagent-driven-development` (Implementer → Task-Review → Fix-Waves → Whole-Branch-Review auf opus → konsolidierte Fix-Wave → Tag).

- **Typisiertes jsonb statt roher `KeyValue`** (die verbindliche Antwort auf M3-Insight #9): `Catering.menu` wird über ein `MenuCast` (`CastsAttributes`) + `MenuOption`-DTO round-getrippt (`price_cents` bleibt echtes `int`), im Filament-Formular per **typisiertem `Repeater`** (`->numeric()->integer()->minValue(0)`) editiert. Weil das strukturierte Feld **non-fillable** ist, persistiert Filament es über `handleRecordCreation`/`handleRecordUpdate`-Overrides (fillable/Cast unangetastet). Wiederverwendbares Muster für jedes künftige typisierte jsonb.
- **Parent-Row-Lock gilt auch für Status-Übergänge, nicht nur Kapazitätsprüfungen.** `OpenFoodOrder`/`CloseFoodOrder`/`OpenPoll`/`ClosePoll` machen `DB::transaction` + `lockForUpdate()` auf die Aggregat-Zeile *vor* dem Guard — ein bloßes read-check-write racet sonst (zwei parallele Closes). Erst im Task-Review nachgezogen; für alle künftigen Transition-Actions verbindlich.
- **Non-fillable Ownership-/State-Felder via `forceFill`/explizite Zuweisung setzen; Factories umgehen `$fillable`.** `PollVote.user_id`, `LfgPost.user_id`/`expires_at`, `ScheduleItem.ref_type`/`ref_id` sind non-fillable (Anti-Forgery/Ownership); gesetzt nur in der jeweiligen Action. Tests, die solche Felder brauchen, nutzen die **Factory** (force-fillt) statt `create()` (respektiert fillable) — sonst schlägt z. B. ein Unique-Constraint-Test fälschlich als NOT-NULL-Fehler an.
- **Input-Validierung gehört in die Domain-Action, nicht nur in den `FormRequest`.** `/lfg create` (Discord) ruft `CreateLfgPost` direkt und umging die `max`-Regel des `CreateLfgPostRequest` → `varchar`-Overflow. Lösung: Titel/Länge in `CreateLfgPost::handle()` prüfen (`LfgException::invalidTitle()`), damit Web- **und** Nicht-HTTP-Aufrufer (Slash-Commands) über eine Naht gedeckt sind. Regel für alle Actions mit mehreren Einstiegspunkten.
- **Neue Modul-Filament-Resources müssen in `AdminPanelProvider` per `->discoverResources(in:, for:)` registriert werden** (Discovery ist pro Verzeichnis; sonst registriert sich die Resource still nicht). Modul-Console-Commands analog in `bootstrap/app.php` `withCommands([...])` pro `Console`-Dir.
- **Zweiter öffentlicher Reverb-Kanal `event.{id}`** (Voting) spiegelt `tournament.{id}`: `PollUpdated` (`ShouldBroadcast, ShouldDispatchAfterCommit`), in `routes/channels.php` als public dokumentiert (keine Auth-Closure, keine Voter-Identität im Payload). Frontend über eine neue `useEventChannel`-Composable analog `useTournamentChannel`; `PollResults` wird für HTTP-Prop **und** Broadcast-Payload wiederverwendet (kein Drift).
- **Cross-Modul-Kopplung Tournaments→Schedule** über ein `TournamentSaved`-Event (guarded `saved`-Hook, nur bei `name`/`starts_at`/`status`-Änderung) + Listener, der ausschließlich `schedule_items` schreibt — Modulgrenze gewahrt, Loop strukturell unmöglich.
- **`composer check` pest-Step brauchte `-d memory_limit=1G`** (die Suite überschritt bei ~750 Tests das 128M-CLI-Default; phpstan-Step setzte längst `1G`). CI unberührt (setup-php-Default höher) — nur der lokale Gate war betroffen.
- **Offene Follow-ups (dokumentiert, nicht blockierend):** Filament-Edit-Seiten zeigen nach einem locked-instance-Transition-Action einen veralteten In-Memory-`status` bis Reload (`refreshFormData`; betrifft auch das **vorbestehende** `EditTournament`/`StartTournament` — gemeinsamer kleiner Refactor); `CreateLfgPostRequest` cappt `game` auf `max:64`, Spalte/Action erlauben 255 (Web enger als DB — angleichen); diverse kosmetische Per-Task-Minors im SDD-Ledger.

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
| 5.6 | Produktions-Deployment: FrankenPHP-`app`-Image (`docker/Dockerfile`), Compose-Profile `prod` (app, queue, reverb, scheduler), `.env.example` final, Deploy-Doku in README; `lanomat:install` im Container verifiziert. **Fold-in:** hier Reverb-`allowed_origins` von `'*'` auf die Prod-Hosts festziehen (M3-Insight); den `refreshFormData`-Stale-Status-Follow-up aus M4 mit angehen |
| 5.7 | **Benachrichtigungs-Trigger + Zeitplan-Favoriten** (Feature-Input R2 ⭐, verdrahtet M2.9-Glocke × M4.1-Schedule × 5.5-Infoscreen): Favoriten-Stern je Programmpunkt → persönlicher Zeitplan + Erinnerung vor Start + Alarm bei Planänderung an Betroffene (Teilnehmer + Favoriten-Setzer). Drei Ein-Klick-Trigger für Orga/Helfer: „Essen ist da" (Push an alle Besteller + Infoscreen-Einblendung via 5.5), „Match/Server bereit" **auch in die Glocke** (läuft bisher nur über Discord — siehe Leitlinie „Discord verstärkt, ersetzt nie"), „Check-in öffnet". Glocke = Wahrheit, Discord-DM = Spiegel je Präferenz |
| 5.8 | **Show-Momente + Betriebs-Kacheln am Beamer** (Feature-Input R2): Tombola-Szene (jeder eingecheckte Teilnehmer bekommt automatisch ein Los, Preise pflegt Orga, Ziehung als Beamer-Szene — dieselbe Show-Mechanik wie die Glücksrad-Ziehung des Spiele-Votings, siehe R2-Backlog); Status-Ansage-Kachel (Internet/Server-Last/Voice; bei Störung automatische Infoscreen-Einblendung „Internet down, Orga weiß Bescheid" — erspart die 20 gleichzeitigen Nachfragen); **Orga-Ping** (Teilnehmer-Knopf „Orga rufen" → Notification an Orga/Helfer mit Sitzplatz + optional 3 Wörtern; kein Ticketsystem, nur der Ping) |

**Abnahme:** Screen läuft 30 min stabil im Kiosk-Browser durch alle Szenen; Sofort-Einblendung erscheint < 2 s; `docker compose --profile prod up` liefert lauffähiges System; ein Trigger („Essen ist da") landet in Glocke UND am Beamer.

### Erkenntnisse M5 (Umsetzung + Whole-Branch-Review, 2026-07-16)

**Getaggt `m5`.** 14 Tasks: neue **Helfer-Rolle** (Task 1, zuerst) + neues Modul `Infoscreen` + Erweiterungen an `Schedule`/`Registration`/`Catering`/`Tournaments`; ~887 Tests grün, alle sechs Gates grün. Umgesetzt via `subagent-driven-development` (sonnet für Implementer/Reviewer/Fix, opus für den Whole-Branch-Review → konsolidierte Fix-Wave → Re-Review → Tag).

- **Helfer-Rolle:** `isHelper()` = helfer-oder-höher (`[Admin, Orga, Helper]`); `isOrga()` unverändert; `canAccessPanel()` bleibt `isOrga()` (Helfer bekommt **kein** `/admin`); `Gate::before` bleibt admin-only. Helfer-Flächen (Trigger/Ziehung/Status/„Sofort einblenden") laufen über `role:helper`-**Routen** + Policy-`can`, **nicht** über das Filament-Panel. Das Check-in-Gate war keine Policy-Methode → `routes/web.php` (`role:orga`→`role:helper`) + `CheckInRequest::authorize()` umgestellt.
- **Infoscreen-Broadcast:** eine öffentliche `event.{id}`-Fläche; `SceneOverride` (`'scene.override'`) + `ScenesUpdated` (`'scenes.updated'`), Payload frei von Privatdaten. **`ScenePayload::for` ist die EINZIGE Szene→Wire-Projektion** (Controller + alle Override-Producer — Show-now/Winner/Essen/Tombola/Status), kein Drift. Winner-Moment: `MatchCompleted` läuft nur auf `tournament.{id}` → eigener Listener re-broadcastet `SceneOverride` auf `event.{id}`.
- **Rotations-Remount:** synthetische Overrides haben kein Top-Level-`id` → `Show.vue` keyt die aktive Szene auf einen `renderKey` (Rotations-`id` **oder** ein `override-<seq>`-Token je Push), damit mount-getriggerte Animationen (`ConfettiOverlay`) bei **wiederholten gleichartigen** Overrides (2./3. Tombola-Ziehung usw.) erneut abspielen. Erst im Whole-Branch-Review gefunden.
- **Reuse ohne Drift:** Bracket-/Schedule-/Seat-DTO-Projektionen in Support-Klassen extrahiert (`BracketMatchProjection`/`ScheduleProjection`/`SeatProjection` — byte-identisch, von den Original-Seiten **und** den Szenen genutzt); `EntryRoster` als wiederverwendbarer Roster→Users-Resolver (Match **und** Turnier).
- **Cross-Event-Scoping (verbindliche Lehre aus Task 8):** jeder helfer-bediente Endpoint mit gebundenem Kind-Record macht `abort_unless($child->event_id === $event->id, 404)`. Zuerst als Task-8-Fix nachgezogen, danach in Tombola/Status/Ping **proaktiv** angewandt.
- **„Glocke ist die Wahrheit, Discord spiegelt":** alle M5-Notifications `data = ['category','title','body']`, `via() => ['database', DiscordChannel::class]`; der DB-Eintrag landet **immer**, die Discord-DM nur bei aktiver Kategorie-Präferenz + verknüpfter `discord_id`. Neue Kategorien `schedule`/`catering`/`checkin`/`match`/`orga_ping`. Registration-open **und** Match-ready jetzt AUCH in der Glocke (vorher nur Discord). Zwei `via()`-Stile koexistieren (beide korrekt, `DiscordChannel::send` re-gated).
- **Änderungsalarm an Betroffene** (Roadmap 5.7): favoriters **∪** Turnier-Teilnehmer über einen neuen Consumer-Contract `ScheduleParticipantResolver` (**Schedule definiert die Schnittstelle, Tournaments implementiert** sie, im Container gebunden), dedupliziert per `unique('id')` → genau eine Benachrichtigung je User. Musterbeispiel für saubere Cross-Modul-Kopplung ohne Fremdtabellen-Zugriff.
- **Typisiertes jsonb `SceneConfig`** (flacher DTO + Cast; `is_array`-Guard im `set()` wie `MenuCast`); Filament `->reorderable('sort')` erstmals im Repo genutzt; `ToggleColumn` per `->disabled(fn () => ! can('update'))` policy-gated (inline editable columns respektieren sonst keine Policy). **Tombola:** DB-`unique(event_id, registration_id)` als Backstop zur lock-basierten No-Repeat-Garantie (Analogie `poll_votes`).
- **Prod-Deployment (5.6):** zweistufiges **FrankenPHP**-Image (`dunglas/frankenphp:1.12.4-php8.4`, **nativer Modus, KEIN Octane** — per aktueller Doku verifiziert ausreichend, als Abweichung notiert); Compose-`prod`-Profil (`app`/`queue`/`scheduler`/`reverb-prod`) über den Compose-Default-Profil-Marker (`''`/`dev`) vom Dev-Stack getrennt (Dev-Stack byte-identisch, keine 8081-Kollision); `mumble-admin` loopback-gebunden (nicht öffentlich); Reverb-`allowed_origins` env-getrieben (M3-Insight gefoldet); `refreshFormData`-Stale-Status-Fix (M4-Follow-up) auf `EditFoodOrder`/`EditPoll`/`EditTournament`. TLS/Reverse-Proxy bewusst nach **M7 (Traefik)** verschoben.
- **Offene Follow-ups (dokumentiert, nicht blockierend):** HTTP-Level-Tests für weitere Trigger-Routen breiter ziehen; 4 identische compose-`build:`-Blöcke → `x-app-build`-Anchor/geteiltes Image; `status_signals` append-only (späterer „Outage-Log" mit Prune); diverse kosmetische Per-Task-Minors im SDD-Ledger.

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
| 6.6 | **Server-Presets & Settings-Modell** (10-Minuten-Prinzip, Feature-Input 2026-07-15 ⭐; ersetzt/erweitert Backlog [#4](https://github.com/raute1-org/LANoMAT/issues/4)): je Spiel Ein-Klick-Presets (z. B. „Vanilla 1–20", „Hardcore", „Modpack X") in `games.default_server_config` (JSONB) + optionaler Preset-Katalog. Settings-Formular (Slots, Map, Difficulty … als Web-Form, Messlatte Nitrado/ShockByte) **ODER** Config-Upload (`server.properties` etc.) — der User wählt den Modus, am Ende wird **genau eine** Config auf dem Server ausgeführt (eine Wahrheit). Minecraft-Config-Panel aus #4 ist der spielspezifische Ausbau dieses generischen Modells. |
| 6.7 | **Guardrails gegen Ressourcen-Overrun** (Feature-Input 2026-07-15): RAM-Schätzung je Preset/Config **vor** dem Start anzeigen; harte Caps je Instanz (RAM/CPU/Slots); max. gleichzeitig offene Server pro User. Verhindert, dass eine Fehlkonfiguration die Host-Kiste einfriert. Durchgesetzt in `ProvisionMatchServerJob`/der Server-Anlage-Action, nicht nur in der UI. |
| 6.8 | **Warmup & Go** (Feature-Input R2, Muster epic.LAN/FACEIT): Match startet, wenn alle ready sind ODER Orga/Helfer das „Go" gibt. Software-Zustand `WARMUP → LIVE` auf der Match-Seite (spielagnostisch, gilt für alle Turniertypen) + Gong-Einblendung am Infoscreen; bei CS2 zusätzlich **serverseitig** durchsetzbar (MatchZy beendet den Warmup). Nutzt das M3-Match-Lifecycle-Modell, kein neues Bracket-Konzept |
| 6.9 | **CS2-Live-Stats** (Feature-Input R2, Vorbild `sivert-io/matchzy-auto-tournament`): MatchZy/G5API liefern Live-Match-Events (K/D/A, Rundenstände) an eine eigene API → Live-Scoreboard auf der Match-Seite + am Beamer (Infoscreen-Szene). Ehrlich als **Rezept je Spiel**, nur wo Telemetrie existiert — kein Universal-Anspruch (deckt sich mit der M6.5-Stats-Kür „APM wo auslesbar") |

**Abnahme:** Feature-Test Provisioning-Flow gegen Fake (inkl. Poll-Retry + Fehlerpfad → manueller Modus); Preset-Start erzeugt genau eine wirksame Config (Form-Modus wie Upload-Modus getestet); Guardrail lehnt Start über Cap/Server-Limit ab (Test); manuell: Minecraft-Server aus Match-Kontext erstellt, Join-Info erscheint in Discord-Embed und auf der Match-Seite; Leaderboard zeigt Daten aus 2 Test-Events.

**Stats-Kür (Feature-Input 2026-07-15, optionale Stretch-Ziele über 6.5 hinaus):** aktivste Stunden (Heatmap aus Check-in-/Match-Zeiten), APM-Counter wo aus dem Spiel auslesbar (spielspezifisch, nur wo Telemetrie existiert), VOD-Archiv mit Highlights (Storage-getrieben, kein Base64), KI-generierte Auto-News/Patchnotes auf der Startseite. Alles nice-to-have, klar nachrangig gegenüber dem Kern-Leaderboard.

---

## M7 — Infra & Betrieb (Backlog aus GitHub-Issues, erstellt nach LAN 2025-11)

**Ergebnis:** Betriebsfähiges Deployment mit eigenem Ingress, eigener Image-Bereitstellung, LAN-Filesharing und flexibleren Gameserver-Starts. Rein infrastruktur-/betriebslastig, ohne Abhängigkeit zu den Feature-Phasen M1–M6 — jeder Task einzeln nachschiebbar. Detailplan (Format wie die übrigen Phasen) wird just-in-time bei Phasenstart abgeleitet.

| # | Task | Issue |
|---|------|-------|
| 7.1 | **Traefik Reverse Proxy:** Traefik als Ingress vor `app`/`reverb`/`admin` (+ ggf. Pelican/Mumble), TLS (ACME/interne CA), Router-/Middleware-Config; Integration ins prod-Compose-Profil (M5.6). Reverb-WebSocket-Upgrade und Filament-`/admin` mit abbilden | [#7](https://github.com/raute1-org/LANoMAT/issues/7) |
| 7.2 | **Eigene Docker-Registry:** private Registry für LANoMAT-Service-Images (FrankenPHP-`app` aus M5.6) und Gameserver-Images/Pelican-Eggs (M6.1); Push/Pull in CI + Deploy-Doku; Auth/Zugriffsschutz | [#3](https://github.com/raute1-org/LANoMAT/issues/3) |
| 7.3 | **Filesharing-Service:** LAN-Dateiablage (Installer, Treiber, Medien) — Upload/Download über Laravel Storage (kein Base64 in DB, Konvention!), Teilnehmer-UI (`Pages/Files/*`) + Orga-Verwaltung im Filament-Panel, Quota/Sichtbarkeit pro Event. **Spike zuerst:** reicht Laravel-Storage + einfache UI, oder dedizierter Service (z. B. WebDAV/S3-kompatibel im Compose)? **Feinschliff (Feature-Input R2):** User dürfen selbst Files anbieten (Mods/Tools/Configs), sichtbar erst **nach Freigabe durch Orga/Helfer** (Moderations-Gate, damit kein Quatsch in der Ablage landet — dasselbe Freigabe-Muster wie Galerie/M12 und die Voice-Installer/M8). | [#1](https://github.com/raute1-org/LANoMAT/issues/1) |
| 7.4 | **Custom Docker Command & Compose-Startup:** freie Gameserver/Services jenseits der Pelican-Eggs starten — Orga hinterlegt Docker-Command bzw. Compose-Fragment, Start/Stop/Status über bestehende Betriebs-UI. Baut auf M6 auf (Pelican als Standardweg, dieser Task als Ausweichweg für nicht-abgedeckte Spiele) | [#6](https://github.com/raute1-org/LANoMAT/issues/6) |
| 7.5 | **LanCache** (Feature-Input R2 ⭐, größter Praxis-Hebel der ganzen Liste): [`lancache.net`](https://lancache.net) als Container im prod-Stack — Steam/Epic/Battle.net-Downloads laufen einmal durchs Internet, danach für alle mit LAN-Speed. Orga cached **vor** der LAN die Gewinner des Spiele-Votings vor (kein 60-GB-Patch am LAN-Tag übers Internet). Dazu je Spiel im Katalog (`games`) eine „So kommst du ran"-Zeile: `steam://install`-Deeplink, Download aus der LAN-Ablage (M7.3), Versions-/Modpack-Hinweis. Rein Infra + ein kleines Katalog-Feld — keine App-Kern-Abhängigkeit | — |
| 7.6 | **README-Screenshots via Headless-Pipeline** ([#10](https://github.com/raute1-org/LANoMAT/issues/10)): **wiederholbare** Bild-Pipeline statt manuellem Einmal-Durchlauf — deterministischer Seed (bestehende Factories) baut ein Demo-Event (laufendes Turnier, gefüllter Sitzplan, offene Abstimmung), ein Playwright-Headless-Skript schießt ~6–8 Kern-Screens (Event-Seite, Anmeldung/QR, Sitzplan, Live-Bracket, Schedule/Catering/Voting/LFG, Filament-Panel, Infoscreen-Hero) bei fixem Viewport (hell + dunkel) nach `docs/screenshots/` (Dateien, kein Base64), eingebettet ins README; optional CI-Regeneration gegen Veralterung. **Timing:** MVP (M0–M3) ist bereits getaggt und zeigenswert — sinnvoll **direkt nach M5** ausführen (Infoscreen liefert das „Hero"-Bild), Pipeline pro Milestone re-runbar (M6 Gameserver/Stats). Rein Tooling/Docs, keine App-Kern-Abhängigkeit. | [#10](https://github.com/raute1-org/LANoMAT/issues/10) |

**Abnahme:** `docker compose --profile prod up` liefert ein über Traefik erreichbares System mit TLS; ein Image wird aus der eigenen Registry gezogen; eine Datei lässt sich als Teilnehmer hoch- und (nach Freigabe) wieder herunterladen; ein nicht-Pelican-Gameserver startet über den Custom-Docker-Weg; ein zweiter Download desselben Spiels kommt aus dem LanCache (LAN-Speed statt Internet); die README-Screenshot-Pipeline (7.6) erzeugt reproduzierbar die Kern-Screens.

---

## Backlog — Erweiterungen an geplanten Modulen (aus Issues nach LAN 2025-11)

Diese Wünsche sind keine eigene Phase, sondern erweitern bereits geplante Bausteine. Beim Detailplan der jeweiligen Phase mitziehen:

- **Voice-Provider-Abstraktion — beide Backends gleichzeitig, Channel-Baum auf beiden gespiegelt** ([#2](https://github.com/raute1-org/LANoMAT/issues/2), verstärkt durch Feature-Input 2026-07-15 ⭐): M3 plant Mumble (`MumbleClient`, 3.19–3.21). Gewünscht: `MumbleClient` zu einem allgemeinen **`VoiceClient`**-Contract verallgemeinern — Mumble UND **TeamSpeak** laufen **gleichzeitig** (Discord-Voice optional als dritte). Mumble = geringe Latenz, TeamSpeak = Gewohnheit vieler Nutzer — beide legitim.
  - **Spiegel-Provisionierung:** Der Channel-Baum (Turnier + Team-/Match-Channels) wird **auf allen aktiven Backends parallel** angelegt und gemeinsam wieder abgeräumt — nicht nur auf dem vom Team gewählten. So kann ein Team **spontan von Mumble zu TeamSpeak wechseln**, ohne dass erst etwas provisioniert werden muss (der Ziel-Channel existiert schon). Das vereinfacht die Team-Wahl: `voice_provider` am Team/Entry bestimmt nur noch den **hervorgehobenen/Default-Join-Link**, nicht mehr, wo überhaupt Channels entstehen.
  - **Umsetzung:** eine **Provider-Registry** hält alle konfigurierten Backends aktiv; die Orchestrierung fächert Channel-Anlage/Rename/Delete über **alle** Provider auf (fehlertolerant je Provider — fällt ein Backend aus, blockiert es die anderen nicht). Join-Link-Helper provider-generisch (`mumble://` bzw. `ts3server://`). `config('services.mumble')` wird zu `config('services.voice.<provider>')`. Erweiterung von M3.20/3.21.
  - **Lifecycle:** Match-/Turnier-Channels entstehen und verschwinden mit dem Match/Turnier (auf beiden Servern synchron). Die „0-Spieler → weg"-Auto-Teardown-Idee greift damit v. a. für ad-hoc/LFG-Channels, nicht für die gespiegelten Turnier-Bäume (dort wäre pro Match ohnehin immer ein Server leer).
  - **Web-UI-Channelliste** mit One-Click-Join: beide Links je Channel sichtbar, der Team-Provider hervorgehoben.
  - **Nachschärfung (Feature-Input R2):** die Web-Channelliste zeigt zusätzlich die **Live-Insassen** (wer sitzt gerade in welchem Channel), nicht nur die Channels; Voice-Channels außerdem **je laufendem Gameserver** (nicht nur Turnier/Match), mit Teardown bei 0 Spielern.
- **Voice-Client-Download-Sektion** (Feature-Input 2026-07-15, Teil von M8): kleine Teilnehmer-Seite „Voice einrichten" mit **Client-Downloads für Mumble und TeamSpeak** + den Verbindungs-Daten der LAN-Server (Host/Port, One-Click-Connect-Links) — 10-Minuten-Prinzip: in Minuten verbunden. **Entscheidung: die aktuellen Installer werden direkt in LANoMAT gehostet** (kein Internet nötig, volle LAN-Geschwindigkeit) — Dateien über **Laravel Storage** (Konvention: kein Base64 in der DB), Ablage/Ersetzen über den Filesharing-Dienst **M7.3** ([#1](https://github.com/raute1-org/LANoMAT/issues/1)); die Orga lädt die jeweils aktuelle Client-Version hoch und kann sie ersetzen (Versions-/„aktuell"-Kennzeichnung an der Datei). Externe Links auf die offiziellen Downloads nur als optionale Ergänzung. *Hinweis: Mumble ist Open Source und frei weiterverteilbar; für die Weitergabe des TeamSpeak-Clients die EULA-Lage kurz prüfen — für den privaten LAN-Kreis i. d. R. unkritisch, aber bewusst entschieden.*
- **Minecraft-Konfigurations-Panel** ([#4](https://github.com/raute1-org/LANoMAT/issues/4), Referenz: setupmc.com/java-server): jetzt als spielspezifischer Ausbau des generischen **Preset-/Settings-Modells M6.6** geführt (server.properties, Mods/Plugins, Whitelist, Version über den `PelicanClient` hinaus). Siehe M6.6/6.7.
- **Discord-Auth per Guild-Membership** ([#8](https://github.com/raute1-org/LANoMAT/issues/8)): Discord-OAuth-Login existiert (M0). Gewünscht: Login/Registrierung auf Mitglieder einer bestimmten Discord-Guild beschränken (Guild-Membership im OAuth-Callback prüfen, ggf. rollenbasiert). Erweiterung der M0-Auth.
- **„Build LANoMAT from scratch"** ([#5](https://github.com/raute1-org/LANoMAT/issues/5)): entspricht dieser Roadmap (M0–M7) — der komplette Rebuild ist die Umsetzung dieses Epics; kein separater Task.

## Post-MVP-Phasen M8–M10 & Backlog — Feature-Input 2026-07-15 (⭐ = Absender-Priorität)

Zweite Welle Feature-Wünsche, bewertet und eingeordnet. Die drei substanziellen Blöcke sind als **eigene Post-MVP-Milestones M8–M10** angelegt (GitHub-Milestones #9/#10/#11 + Board #2, Status Todo, ohne Fälligkeitsdatum — kommen nach M4–M7). Kleinere Erweiterungen bereits abgeschlossener Module ziehen im jeweiligen Detailplan mit. Bewertung je Item: **Wert / Aufwand / Einordnung**.

- **M8 — Voice-Multiprovider (Mumble + TeamSpeak gleichzeitig)** ⭐ — siehe oben im Issue-Backlog (`#2`, verstärkt): **beide Backends gleichzeitig aktiv, Wahl pro Team**. Getrackt als Milestone M8. (Kein Duplikat hier — das verbindliche Detail steht im #2-Bullet.)
- **M9 — Identity+: Plattform-Verknüpfungen & kontextsensitiver Anzeigename** ⭐ — optionale User-Verknüpfungen zu Steam, GOG, Battle.net, Epic, Twitch (das bestehende `steam_url` von echter URL zu echter OAuth-Verknüpfung aufwerten). Nutzen: Anzeigename kontextsensitiv (Steam-Spiel → Steam-Nick, sonst LANoMAT-Nick), Turnier-Besitz-Checks als Hinweis, Freunde-Vorschläge. Token-Pflege: Refresh automatisch, Warnung bei nötiger Re-Auth.
  *Wert hoch / Aufwand groß (mehrere OAuth-Provider + Token-Lifecycle; Achtung: GOG bietet keinen offiziellen öffentlichen OAuth-Flow — als „manuelle Verknüpfung"/nachrangig behandeln). Umsetzung: Provider inkrementell hinter einem `LinkedAccountProvider`-Adapter (Contract-Prinzip). Kontextsensitiver Anzeigename ist billig, sobald Links existieren. **Vorbedingung: die Gruppen-Fusions-Entscheidung (unten) muss vorher stehen.***
  - **Enthält als Design-Leitplanke — Tournaments: Anmeldung locker halten:** Anmeldung übers Konto; Spielbesitz-Check nur als **Hinweis, kein hartes Gate**. LAN-Games ohne Onlinezwang und Ausnahmen müssen durchgehen; Ziel: Listen voll bekommen. *Der Besitz-Check aus M9 darf nie blockieren, nur warnen — verbindliche Regel.*
- **M10 — Präsenz & Casting** — zwei benachbarte neue Features in einer Phase:
  - **Präsenz-Live-Ansicht „wer ist da / spielt was / freie Slots / wer streamt"** mit Filtern (nur freie Slots, nur Freunde, nur Streams), auch beamertauglich. *Wert hoch (LAN-Gefühl) / Aufwand mittel — Datengrundlage entsteht sukzessive: Check-in (M2), Sitzplan (M2), Match-/Turnier-Status (M3), Server-Slots (M6), Streams (unten), Freunde (M9). Sinnvoll erst nach M6, wenn die meisten Quellen live sind. Reverb-getrieben.* **R2-Priorisierung:** der Absender nennt Präsenz das „Kern-Erlebnis der Seite, nicht Kür" und wünscht sie **zuerst**, sobald Post-MVP priorisiert wird → innerhalb M10 die Präsenz-Ansicht vor Streaming/Casting ziehen; die Basis-Ansicht ist auch ohne M9-Freunde/Streams schon wertvoll (freie Slots + wer spielt was aus M2/M3/M6).
  - **Streaming/Casting: einbetten statt hosten + Auto-Overlays** — Streams primär über Discord/Twitch hosten (schont Upload), in LANoMAT nur einbetten/verlinken. **OBS-Overlays (Bracket, Scoreboard) automatisch aus dem Turnier-Modul generieren.** Spectator/Caster je Spiel als kleines Rezept (GOTV/SourceTV, Observer-Slots, Replay) — kein Universal-Bot, aber LANoMAT orchestriert Start/Stop. *Wert mittel-hoch / Aufwand mittel. Overlays sind eine Browser-Source-Route, die M5-Szenen-Technik + M3-`BracketView` wiederverwendet. Stream-Einbettung ist billig. Spectator-Rezepte hängen an den M6-Server-Presets.*
- **Architektur: Gruppen-/Community-Fusion (User-/Team-/Historien-Merge)** (Board-Item, ohne Milestone) — zwei Communities zusammenführen können (Import/Merge von Usern, Teams, Historie). Das Event-als-Aggregate-Root-Modell passt, aber **User-Merge früh mitdenken**.
  *Wert langfristig / Aufwand groß, aber die Design-Entscheidung ist billig und JETZT fällig: stabile User-IDs, keine harten Annahmen, die einen späteren Merge verbauen (z. B. `discord_id` als einziger Identitätsanker, Merge-fähige FKs/Historie). Muss vor M9 (Identity+) feststehen — dort werden dauerhafte Verknüpfungen/Tokens an User gehängt.*

---

## Feature-Input Runde 2 (2026-07-15) — Bewertung & Einordnung

Dritte Welle Wünsche (JB), sortiert entlang der Milestone-Reihenfolge. Leitlinie „Discord verstärkt, ersetzt nie" ist oben in die Produktleitlinien aufgenommen. Absender-Top-3 ⭐: **Zeitplan-Favoriten+Trigger (→ M5.7)**, **LanCache (→ M7.5)**, **Jukebox (→ M11)**. Vieles ist bereits **in die offenen Phasen eingearbeitet** (siehe Verweise); hier stehen (a) die Aufsätze auf bereits **abgeschlossene** Module M2–M4 und (b) die zwei neuen Post-MVP-Phasen. Bewertung je Item: **Wert / Aufwand / Einordnung**.

### Bereits in offene Phasen eingearbeitet (nur Verweis)

- **#2 Zeitplan-Favoriten + Trigger** ⭐ → **M5.7**. **#10 Tombola/Status-Kachel + #11 Orga-Ping** → **M5.8**. **#6 Warmup & Go** → **M6.8**. **#7 CS2-Live-Stats** → **M6.9**. **#8 LanCache** ⭐ → **M7.5**. **#9 Filesharing-Feinschliff (User-Uploads mit Freigabe)** → **M7.3**. **#13 Voice-Nachschärfung (Live-Insassen, Channel je Gameserver)** → **M8**. **#14 Präsenz zuerst** → **M10**-Priorisierungsnote.

### Stufe 1 — Aufsätze auf abgeschlossene Module (M2–M4, getaggt); als Erweiterungs-Tasks nachschiebbar

- **#1 Spiele-Voting für die nächste LAN** (Aufsatz auf M4-Voting) — Orga stellt feste Kandidaten, Community schlägt eigene Spiele vor (Orga moderiert/sortiert aus), **jeder hat 3 Stimmen** statt einer (ehrlichere Spielewahl); bei Gleichstand **Los** — aber als **Show-Moment am Beamer** (Glücksrad-Szene, teilt die Mechanik mit der Tombola M5.8), nicht still in der DB. *Wert hoch / Aufwand mittel. Einordnung: erweitert das `Voting`-Modul (Kandidaten-Vorschläge + Multi-Vote + Tie-Break-Ereignis) und braucht eine M5-Szene für die Ziehung. Die aktuelle `Poll`/`PollOption`/`PollVote`-Struktur muss dafür Mehrfachstimmen (bis N pro User) und einen „proposed by user, approved by orga"-Status je Option lernen.*
- **#3 Helfer-Rolle** (Erweiterung des `Role`-Enums aus M0) — Stufe zwischen `participant` und `orga`: darf Ansagen/Trigger auslösen, QR-Check-in machen, Freigaben erteilen (Files/Galerie), **kein** Admin-Panel/Konfig-Zugriff. *Wert hoch / Aufwand gering-mittel. Einordnung: `Role`-Enum + Policies erweitern; macht die M5.7/5.8-Trigger, das QR-Check-in (M2.5) und die Freigabe-Gates (M7.3/M12) erst mehrhändig bedienbar. Sauber über die bestehende Policy-Schicht — `Gate::before` bleibt admin-only, Helfer bekommt gezielte `can`-Regeln. **Cross-cutting: sollte VOR M5.7/5.8 stehen**, sonst kann nur die Orga triggern.*
- **#4 Turnier-Typ „Spiel ohne Server"** (M3-Delta, klein) — Dart/Schere-Stein-Papier/Jenga: die Brackets sind schon spielagnostisch, Ergebnisse werden ohnehin manuell gemeldet/bestätigt. Fehlt nur ein Turniertyp **ohne Gameserver und ohne Auto-Voice**, direkt zur Ergebniseingabe. *Wert mittel / Aufwand klein. Einordnung: ein Flag/Format am `Tournament` (z. B. `offline`), das die M6-Server-Provisionierung und die M3/M8-Voice-Orchestrierung überspringt — macht Offline-Turniere zu Bürgern erster Klasse. Kleiner Hebel.*
- **#5 Flatrate-Bezahlkomfort** (M2-Nachtrag) — die Ticket-Typen SIND die Flatrate (inkl. Essen/Getränke). Fehlt nur Komfort: **PayPal-Link mit Betrag** direkt am Ticket („Meine Anmeldung" + Bestätigung), **automatische Zahl-Erinnerung** nach ein paar Tagen ohne `paid_at` (Scheduler, Outbox-dedupt), Zahl-Häkchen auf der Teilnehmerliste (Orga-Schalter, existiert als Paid-Toggle in M2.4), **„bezahlt von"-Notiz** wenn einer für andere mitüberweist. *Wert mittel-hoch / Aufwand gering-mittel. Einordnung: Erweiterung `Registration` (M2) — Feld `paid_by` + PayPal-Link-Config + ein Reminder-Command analog `lanomat:send-reminders`. **Bewusst KEIN Guthaben-System** (Eventula) — Betriebsaufwand lohnt bei unserer Größe nicht.*

### M11 — LAN-Radio/Jukebox (Feature-Input R2 ⭐, neues Modul, Post-MVP)

Gemeinsame Saal-Playlist, die Community steuert die Reihenfolge. *Wert hoch (LAN-Gefühl) / Aufwand groß / Post-MVP, null Eile.*

- **Motor = echtes Spotify:** ein **Spotify-Premium-Konto der Orga**, Wiedergabe auf der Saal-Anlage über ein **Spotify-Connect-Gerät**. Die **Queue lebt bei uns**: User suchen über die Spotify-API, werfen Songs in die LANoMAT-Queue, **Voting bestimmt die Reihenfolge**, LANoMAT schiebt immer nur den **nächsten** Song zu Spotify rüber (deren API kann Queues nicht umsortieren — erprobtes Muster der Jukebox-Tools).
- **Fairness:** Rotation zwischen Usern, **max. 3 offene Songs pro Person**, nur eigene löschbar, Skip durch Orga/Helfer. Wunschliste schon **vor** der LAN befüllbar → wird zur Anfangs-Queue (bindet an die Countdown-Seite M12).
- **Now-Playing als Infoscreen-Szene** (M5-Szenentechnik). **`MusicClient`-Contract** nach dem Projekt-Muster (Spotify als erste Implementierung, austauschbar). Ehrliche Grenze: braucht Internet + das eine Premium-Konto; fällt Spotify aus, **pausiert nur die Jukebox** (kein Kern-Feature-Ausfall). Reuse: `Voting`-Mechanik für die Reihenfolge, `event.{id}`-Reverb-Kanal für Now-Playing/Queue-Updates.

**Verify-first-Erkenntnis (Recherche 2026-07-15, verbindlich für den M11-Detailplan):**

- **Modell:** LANoMAT ist die **Fernbedienung + Voting-Queue**, ein **Spotify-Connect-Gerät im LAN** ist die **Tonquelle** (an die Anlage per Line-Out). „In LANoMAT starten → läuft auf dem Connect-Gerät im Saal" — LANoMAT fasst nie Audio-Bytes an, nur Steuerung.
- **Baukasten statt Turnkey:** kein bestehendes Jukebox-OSS-Projekt ist tragfähig genug zum Draufbauen (Festify seit 2023 brach, der Rest klein/unreif/YouTube-basiert). Stattdessen **eigenes schlankes Jukebox-Modul** + Contract.
- **Spotify-Backend = go-librespot + Web-API:** ein **Spotify-Connect-Endpunkt** im LAN (empfohlen [`go-librespot`](https://github.com/devgianlu/go-librespot); Fallback der offizielle Spotify-Desktop-Client) gibt den Ton aus; LANoMAT steuert nur über die Web-API-Player-Endpoints (Gerät wählen, Play/Pause/Skip, **nächsten Song via `PUT /me/player/play?device_id=…&uris=[…]`** — Spotifys Queue ist nicht umsortierbar, deshalb **besitzt LANoMAT die Queue**). Nur der **Orga-Premium-Account** macht OAuth → der **5-User-Dev-Mode-Cap** (Spotify seit Feb 2026) greift NICHT; Teilnehmer bedienen nur die LANoMAT-Seite, ohne eigenen Spotify-Login.
- **Erster M11-Task = kleiner Spike:** ein Orga-Premium-Konto, nächsten Song an ein Connect-Gerät schieben, mehrere parallele Suchen — bestätigt billig (a) dass „nur-Host-zählt" gegen den 5-User-Cap hält (starke Herleitung aus der Doku, von Spotify nicht schriftlich garantiert) und (b) die Rate-Limits unter Last. Risiko librespot-Auth-Zicken (Spotify zieht reverse-engineerte Login-Flows periodisch an) → Absicherung: offizieller Spotify-Client als Fallback-Connect-Gerät.
- **`MusicClient`-Contract schmal + Capability-Segregation** (Muster: Mopidy-Optional-Provider, Laravel-Notification-Channels, ISP/„discover interfaces, don't design them"): Kern-Verben `search`/`enqueue`/`vote`/`skip`/`nowPlaying` teilen ALLE Backends; **Auth/Device/Setup pro Backend AUSSERHALB des Contracts** (wie `DiscordClient`/`MumbleClient` — dort liegt die Impl-spezifische Konfiguration schon außerhalb); Playback-/Device-Steuerung als **optionales Capability-Interface** (nur Backends, die es können), NICHT als fette Schnittstelle mit no-op/`NotSupportedException`.
- **Referenz-Code (nicht als Abhängigkeit einbinden):** [`mintopia/musicparty`](https://github.com/mintopia/musicparty) (Laravel + Spotify, gleicher Stack), [`th0rn0/lanops-spotify-jukebox`](https://github.com/th0rn0/lanops-spotify-jukebox) (LAN-spezifisch), [`raveberry`](https://github.com/raveberry/raveberry) (Queue-/Voting-UX-Modell, Multi-Source).
- **Backlog (später, hinter demselben Contract):** optionales **Eigene-Bibliothek-Backend** ohne Internet-Abhängigkeit — **Navidrome** (aktiv, GPL-3.0, Subsonic-`jukeboxControl` = serverseitige Ausgabe via mpv) bzw. MPD als Motor, Musikdateien lokal (nutzt LanCache/Filesharing M7). Vorteil: kein Internet, kein Premium, kein User-Cap; Nachteil: kein „such jeden Song", Bibliothek muss vorbereitet werden. **Erst wenn das Spotify-Backend steht — NICHT Teil des initialen M11-Scopes.**

### M12 — Post-/Pre-LAN-Content (Feature-Input R2, Post-MVP)

Gründe, auch zwischen den LANs auf die Seite zu kommen — zusammen mit dem Event-Archiv (M1). *Wert mittel-hoch / Aufwand mittel / Post-MVP.*

- **#15 Galerie, Recap, News:** Foto-Galerie je Event (alle dürfen einreichen, handytauglich, sichtbar **erst nach Freigabe** durch Orga/Helfer — dasselbe Moderations-Gate wie M7.3/M8); Slideshow als **Infoscreen-Szene** aus freigegebenen Fotos; nach der LAN **Zip-Download**. **Recap-Seite je Event** auto-generiert aus vorhandenen Daten (Sieger/Podien/Leaderboard aus M6.5, Zahlen, Top-Fotos). **News light:** Orga-Posts auf der Startseite („Nächste LAN am …").
- **#16 Countdown-/Hype-Seite vor der LAN:** die Event-Seite (M1.5) zeigt vor dem Event den Vorfreude-Modus — Countdown, wer kommt schon (mit Zahl-Häkchen aus #5), laufendes Spiele-Voting (#1), Jukebox-Wunschliste befüllen (M11), Anreise-Infos. *Kein neues Modul, ein Status-abhängiger Modus der bestehenden Event-Seite.*
- **#17 MVP-des-Abends-Vote** (kaum Extra-Code): nach dem letzten Turnier stimmt die Community über den Spieler des Abends ab — nutzt das `Voting`-Modul + die Show-Ziehung (M5.8), Ergebnis gibt ein Badge (M6.5). 
- **#18 Kür-Einzeiler** (kein Muss): Challenges/LAN-Bingo während der LAN (kleine Aufgaben, Punkte, Leaderboard) als Aufsatz auf die M6.5-Badges.

---

## M13 — Design-Polish (Rams' 10 Prinzipien, cross-cutting)

**Ergebnis:** Die gesamte sichtbare Oberfläche (Teilnehmer-UI, Infoscreen/Beamer, Filament-Panel) folgt einer ruhigen, konsistenten, zeitlosen visuellen Sprache — umgesetzt mit dem **`frontend-design`-Plugin/Skill** und geprüft gegen Dieter Rams' **[10 Prinzipien für gutes Design](https://www.braun-audio.com/de-DE/10principles)**, soweit auf Software übertragbar. Kein neues Feature, sondern ein Qualitäts-Sweep über Bestehendes.

**Einordnung:** Cross-cutting, Post-MVP. **Sinnvoll frühestens nach M5** (dann existiert die erste beamer-taugliche, „zeigenswerte" Fläche) und danach bei größeren UI-Zuwächsen (M6/M10/M11/M12) erneut leicht angefasst. Reine `Tailwind v4 + shadcn-vue`-Politur, keine App-Kern-Abhängigkeit. Jede Phase, die neue UI liefert, hinterlässt hier ggf. einen Nacharbeits-Vermerk.

**Die 10 Prinzipien, auf LANoMAT übertragen (Abnahme-Leitplanke):**

1. **Innovativ** — nutzt aktuelle Web-Plattform-/Framework-Fähigkeiten sinnvoll (Reverb-Live, Inertia, Tailwind v4-Tokens), nicht Neuerung um ihrer selbst willen.
2. **Macht das Produkt brauchbar** — UI dient der Aufgabe (10-Minuten-Prinzip); jeder Ein-Klick-Pfad bleibt der kürzeste; keine Deko, die den Weg verstellt.
3. **Ästhetisch** — konsolidierte, ruhige visuelle Sprache: eine Typo-Skala, ein Spacing-System, definierte Farbrollen (light **und** dark) über Design-Tokens statt Ad-hoc-Klassen.
4. **Verständlich** — selbsterklärende Screens, klare Informationshierarchie, sichtbare Zustände (leer / lädt / Fehler / Erfolg).
5. **Unaufdringlich** — zurückhaltendes Chrome; der Inhalt (Turnierbaum, Programm, Sitzplan) steht im Vordergrund, besonders am Beamer.
6. **Ehrlich** — keine Dark-Patterns, keine Fake-Fortschritte; die UI zeigt den echten Zustand (deckt sich mit „Discord verstärkt, ersetzt nie" — die Glocke ist die Wahrheit).
7. **Langlebig** — tokenbasiertes, wartbares System statt kurzlebiger Trend-Effekte; leicht fortführbar durch künftige Beitragende.
8. **Konsequent bis ins letzte Detail** — Fokus-/Hover-/Aktiv-Zustände, vollständige Tastaturbedienung, konsistente Icons/Abstände/Ränder, dark mode, Beamer-Lesbarkeit auf Distanz.
9. **Umweltfreundlich** (auf Software übertragen) — ressourcenschonend & performant: schlanke Bundles/Assets, Lazy-Loading, effiziente Reverb-Nutzung, gute Ladezeiten; **Barrierefreiheit (a11y)** als Teil davon (Kontrast, ARIA, reduzierter Daten-/Energiebedarf).
10. **So wenig Design wie möglich** — „Weniger, aber besser": jedes Element rechtfertigt seine Existenz; Reduktion vor Ergänzung.

| # | Task |
|---|------|
| 13.1 | **Design-System-Audit & Tokens:** Typo-Skala, Spacing, Farbrollen (light/dark), Radius/Elevation als Tailwind-v4-Tokens + shadcn-vue-Theming konsolidieren; ein kurzer Referenz-Styleguide (`docs/design.md`). Prinzipien 3/7/10. |
| 13.2 | **Teilnehmer-UI-Sweep:** Event-Seite, Anmeldung/QR, Sitzplan, Turniere/Bracket, Schedule, Catering, Voting, LFG — gegen die 10 Prinzipien; leere/lädt/Fehler-Zustände, Fokus/Tastatur/a11y, konsistente Komponenten. Prinzipien 2/4/5/8/9. |
| 13.3 | **Infoscreen/Beamer-Politur:** Distanz-Lesbarkeit, Kontrast, ruhige Rotation/Übergänge, „Weniger"-Prinzip auf jeder Szene (Bracket/Schedule/Sponsors/Winner/Tombola/Status). Prinzipien 3/5/10. |
| 13.4 | **Filament-Panel-Politur:** konsistente Labels/Gruppen/Icons/Navigationsstruktur, sinnvolle Defaults, verständliche Aktionen. Prinzipien 4/8. |
| 13.5 | **„Umweltfreundlich"/Performance & a11y:** Bundle-/Asset-Budget, Lazy-Loading, Bild-/Icon-Optimierung, Lighthouse-/a11y-Checks als wiederholbare Gate-Prüfung. Prinzip 9. |

**Abnahme:** `frontend-design`-Skill für die Umsetzung genutzt; ein `docs/design.md`-Styleguide existiert; alle Teilnehmer-Screens und Infoscreen-Szenen haben konsistente Tokens + vollständige Zustände (leer/lädt/Fehler) + Tastatur-/Fokus-Bedienung; a11y-/Performance-Check dokumentiert; visuell gegen die 10 Prinzipien abgenommen (jedes Prinzip mit mindestens einer konkreten Umsetzung belegbar).

---

## Arbeitsweise

1. **Detailpläne just-in-time:** Vor jedem Phasenstart wird aus dieser Roadmap der Detailplan der Phase erzeugt (Format wie [M0-Plan](2026-07-14-m0-fundament.md): bite-sized Steps, kompletter Code, TDD). Roadmap-Task-Nummern bleiben als Referenz erhalten.
2. **Jede Phase endet mit:** grüner CI, Abnahme-Checkliste erfüllt, Tag `m<N>` im Repo.
3. **Roadmap ist lebendes Dokument:** Erkenntnisse einer Phase (z. B. Ausgang des Pelican-Spikes 6.1) werden hier nachgetragen, bevor der nächste Detailplan entsteht.
