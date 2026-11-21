# LANoMAT Design System — "Signalpult"

The visual identity for LANoMAT (M13 design polish). One idea runs through everything:

> **The app is a quiet control desk. The beamer is the stage.**

The participant and admin UI stays calm, precise and out of the way (Rams: *unobtrusive*, *as little design as possible*). All the loudness — colour, scale, motion, celebration — is spent on the `/screen/{event}` beamer, where it belongs. A single **signal-amber** accent is the through-line: it always means *primary action* or *live/now*, nothing else.

Logo and brand mark: see [logo.md](logo.md).

This document is the source of truth. Every colour and type decision derives from the tokens in `resources/css/app.css`; don't hardcode hex values in components — use the token utilities (`bg-primary`, `text-muted-foreground`, `text-live`, `font-mono`, …).

## Palette

Graphite neutrals + one signal accent + a small operational-status set. Tokens are shadcn-vue roles, so changing them here re-skins the whole app.

**Two-tier architecture** (in `resources/css/app.css`): tier 1 is **palette primitives** — raw values each defined exactly once (`--amber-bright`, `--graphite-950`, `--paper-50`, `--green-deep`, …). Tier 2 is the **semantic roles** (`:root` = light, `.dark` = dark) which only ever `var()`-reference a primitive, never a raw hex. To retint the brand, edit one primitive (e.g. `--amber-bright`) and it propagates to every role that references it (primary, ring, live, sidebar, chart-1). Components must use the semantic role utilities (`bg-primary`, `text-live`, …), not primitives or hex.

### Dark (the signature look)

| Role | Token | Hex | Use |
|------|-------|-----|-----|
| Background | `--background` | `#0F1215` | app canvas (graphite ink) |
| Surface | `--card` / `--popover` | `#171B20` | cards, popovers, sheets |
| Muted surface | `--muted` / `--secondary` | `#1E242B` | quiet fills |
| Hover surface | `--accent` | `#262C33` | ghost/hover, dropdown items |
| Text | `--foreground` | `#E7ECEF` | primary text |
| Muted text | `--muted-foreground` | `#8A949E` | metadata, captions |
| Border | `--border` | `#262C33` | hairlines, dividers |
| **Primary / accent** | `--primary` / `--ring` / `--live` | **`#FFB020`** | primary buttons, focus ring, live state |
| on-primary | `--primary-foreground` | `#17130A` | text on amber |
| OK | `--ok` | `#35C08A` | healthy status |
| Warn | `--warn` | `#E4B34A` | degraded status |
| Down / destructive | `--down` / `--destructive` | `#E5484D` | outage, destructive |

### Light (coherent daytime variant)

Cool paper (not warm cream — that's a template default we avoid). Primary is a **deeper** amber (`#A85A00`) so filled controls keep white text at AA contrast; the bright signal amber lives on as `--live` (`#E8930A`) for dots/highlights.

| Role | Hex |
|------|-----|
| Background `--background` | `#F7F8FA` |
| Surface `--card` | `#FFFFFF` |
| Text `--foreground` | `#14181C` |
| Muted text `--muted-foreground` | `#5B646E` |
| Border `--border` | `#E1E5EA` |
| Primary `--primary` (white text) | `#A85A00` |
| Live/signal `--live` | `#E8930A` |
| OK / Warn / Down | `#12A150` / `#C77D0A` / `#DC2626` |

**Rule:** amber is rationed. If everything is amber, nothing is. Use it for *the* primary action on a screen and for genuinely-live state — never as decoration.

## Typography

Two faces, two jobs. No third face.

- **Space Grotesk** (`--font-sans`, weights 400/500/600/700) — display **and** body. A precise geometric grotesk with enough character to not read as a neutral default. Headings 600/700 with tight tracking (`tracking-tight`); body 400.
- **JetBrains Mono** (`--font-mono`, 400/500/600) — reserved for **machine data**: seat labels, switch ports / IPs, scores, match/round numbers, timers, QR payloads, `lock_version`, IDs. This is a *structural* choice, not decoration: LANoMAT's world is literally ports, seats and clocks, so numbers-as-mono encodes something true (Rams: *structure is information*). Apply with `font-mono` (+ often `tabular-nums`).

Type scale (Tailwind): captions `text-xs`/`text-sm`, body `text-base`, section `text-lg`/`text-xl`, page title `text-2xl`/`text-3xl tracking-tight`. Beamer scenes go much larger (`text-6xl`+) — that's the stage.

## Shape & density

- `--radius: 0.375rem` (6px) — crisper than the shadcn 8px default; control-desk precision.
- Hairline borders over shadows. Shadows stay subtle (`shadow-sm` max) in the app.
- Generous, consistent spacing from the Tailwind scale; align to a grid. Constrained content widths (`max-w-3xl`…`max-w-6xl`) as already used.

## The signature: live-state treatment

The one memorable element. Anything that is *happening now* — a live match, an open check-in window, a running poll, a degraded service — is marked consistently:

- a small **signal dot** in `--live` (or `--ok`/`--warn`/`--down` for health), gently pulsing (respecting `prefers-reduced-motion` → no pulse, static dot);
- a short **mono label** next to it (`LIVE`, a countdown, a timestamp) in `font-mono` uppercase.

Example intent: `● LIVE  17:42` — amber dot + mono clock. The seating grid and the tournament bracket should read like a control diagram, not a marketing graphic. Provide a small shared component/util for this so it stays identical everywhere.

## Avatars

A person's picture is always rendered by `components/UserAvatar.vue`: a round `bg-muted` monogram (initials,
`text-muted-foreground`) with the picture laid over it, `loading="lazy"`, `decoding="async"`, explicit
width/height and the name as alt text. The `src` is LANoMAT's own avatar URL (`User::avatar_src`, served to
logged-in users only), never a Discord CDN address; no avatar, or a failed load, is the monogram. Guests (the
public profile) get no picture at all.

## States are not optional (Rams: *understandable*, *honest*)

Every list/data surface ships all four states, in the interface's voice (German UI copy via `lang/de`):

- **empty** — an invitation to act, not just muted text ("Noch kein Programm — Orga legt gleich los.");
- **loading** — skeletons (`ui/skeleton`) for async, never a bare jump;
- **error** — says what went wrong and the next step, never a vague apology;
- **success/normal** — the content itself.

No fake progress, no dark patterns. The in-app bell is the truth; Discord mirrors (see "Discord verstärkt, ersetzt nie").

## Quality floor (Rams: *thorough to the last detail* + *environmentally friendly*)

Non-negotiable, unannounced:

- responsive to mobile (test 375px);
- visible keyboard focus everywhere (the amber `--ring`, `focus-visible` only);
- `prefers-reduced-motion` respected by every animation;
- images carry width/height (or `aspect-ratio`) + `loading="lazy"` off-screen → no layout shift;
- "environmentally friendly" for software = lean: small bundles, few dependencies, fast loads, good a11y/contrast (AA), low data/energy use.

## Page anatomy & layout rules (binding)

### 1. Layout matrix

The layout of every Inertia page is decided in **one table**: `resources/js/lib/pageLayouts.ts` (consumed by `layout:` in `resources/js/app.ts`). Unknown pages throw; `tests/Architecture/PageLayoutsTest.php` fails when a page under `resources/js/pages` has no entry or an entry matches no page. Add the entry in the same commit as the page.

| Kind | Shell | Pages |
|------|-------|-------|
| `app` | `AppLayout` (sidebar, user menu, bell, `<Toaster/>`) | every authenticated page: `Dashboard`, `Checkin/*` (phone self check-in, `narrow`, see docs/self-checkin.md), `Event/*`, `Teams/*`, `Tournaments/*`, `Orga/*`, `Jukebox/*`, `Recap/*`, `Gallery/*`, `Schedule/*`, … |
| `settings` | `AppLayout` + `layouts/settings/Layout.vue` | `settings/*` |
| `auth` | `AuthLayout` | `auth/*` |
| `profile` | `AppLayout` if logged in, bare for guests | `Profile/Show` (`/users/{user}` stays publicly reachable), `Launcher/Index` (`/launcher` is public: guests get the bare chrome, logged-in viewers the app shell) |
| `bare` | none | `Welcome` (guest landing, no event data), `Screen/*` (beamer), `Overlay/*` (OBS) |

Choosing for a new page: behind `auth` middleware → `app` (default answer). Reachable by guests → it must be guest-safe and `bare`/`profile`. Unattended or transparent surfaces → `bare`. There is no `PublicShell`; the `<Toaster/>` is mounted once, inside `AppSidebarLayout`, so flash toasts only work on `app`/`settings` pages.

**`Launcher/Index` (`/launcher`)** is a public download page on the `profile` tier: a guest lands on bare chrome (no sidebar, no bell) because a fresh PC installs the launcher before its owner logs in; a logged-in viewer sees it inside `AppLayout`, with the sidebar entry "Launcher". It carries no user data, only the builds and SHA-256 values from `clients-dist/launcher/manifest.json`; the empty state says that no build has been published yet.

### 2. Page anatomy (`app` pages)

- **Container:** every `app`/`profile` page is wrapped in `components/PageContainer.vue` (`<PageContainer width="…" title="…" description="…" :back="…">`). It owns horizontal/vertical padding (`px-4 py-8 sm:px-6 lg:px-8`), the width cap and the standard header; no page hand-rolls `<main class="mx-auto max-w-…">` (the architecture test fails on a missing container or a hand-rolled wrapper). The container is **left-aligned** in the main area, so every page starts at the same x-position as the breadcrumb bar regardless of tier; it is never centered.
- **Width tiers** (`resources/js/lib/pageWidths.ts`, the single source):

| `width` | Cap | Use for |
|---------|-----|---------|
| `narrow` | `max-w-xl` | forms, single-column flows, scanner/check-in, registration |
| `default` | `max-w-3xl` | reading, lists, overviews, detail pages (event page, polls, teams detail) |
| `wide` | `max-w-6xl` | dashboard, card grids, tables, seat map, brackets, galleries |
| `full` | none | canvases that manage their own scrolling (use sparingly) |

- **Header:** `title` renders the one `<h1>` (`text-2xl font-semibold tracking-tight`) with the optional one-line `description` below; pages with a bespoke hero (event page, team header, tournament header) fill the `#header` slot instead but keep the same h1 size (event hero may be larger). The primary action and secondary actions go in the `#actions` slot (right of the title, wraps below on narrow screens). Settings subpages use `Heading` + a `sr-only` h1.
- **Back link:** sub-pages pass `:back="{ href, label }"` — rendered as an arrow-icon link above the title ("Zurück zum Event", "Zurück zu allen Turnieren"). Label always names the destination.
- **Spacing:** below the header, sections stack with `mt-8` (or `space-y-8` via `class` on the container); `gap-4` in grids.
- **Primary action:** one primary (amber) `Button` per screen, next to the thing it acts on (card footer or under the title). Secondary actions use `variant="outline"`; destructive ones sit apart and confirm.
- **Sections & cards:** a section is `<h2 class="text-lg font-semibold tracking-tight">` + content; group into a card (`rounded-md border border-border bg-card p-5`) only when the content is a self-contained unit. Hairline borders, no shadows beyond `shadow-sm`.
- **Breadcrumbs / back links:** root sidebar pages need none. Settings and deep pages pass `defineOptions({ layout: { breadcrumbs: [...] } })`; detail pages use the `back` prop of `PageContainer`.
- **Status & hints:** state of the thing (event status, ticket, registration) goes directly under the title in a `rounded-md border bg-card p-4` hint row; live state uses `LiveIndicator`. Hints are calm text, actions are buttons.

### 3. States (all four, every data surface)

| State | Pattern | Component |
|-------|---------|-----------|
| empty | `rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground`, says what to do next | plain markup, copy in `lang/de/<area>.php` |
| loading | skeletons mirroring the final layout, shown while `router` `start`…`finish` | `@/components/ui/skeleton` + router listeners (see `pages/Dashboard.vue`, `settings/Connections.vue`) |
| error | `rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center text-destructive`, names the failure and the next step | `router.on('error')` flag |
| normal | the content | — |

**Spielserver page (`Servers/Index`).** One card per server; the `LiveIndicator` maps `ready` to ok, `provisioning` and `not_responding` to warn (a calm hint sentence under the title explains what is going on, no alarm styling), `failed`/`install_failed` to down (never listed to participants). Players and map come from the last server query and are mono data; a connect string without a URL scheme (a plain `address:port`) is shown as a copyable mono value instead of a dead button. The list reloads partially every 20 s while the tab is visible.

**Serverbrowser (`Servers/Global`, `/servers`).** The cross-game answer to "which servers are running?": the event lists of every public, non-archived event plus the permanent servers, grouped under an `<h2>` per game (`Servers/ServerCard.vue`, same `JoinOptions` and install-hint vocabulary as the event page). The game filter (`<select>`) and the search are server-side URL state (`?game=`, `?q=`) and visit partially (`only`), so the controls keep focus, scroll and state while filtering; an unknown game means "all games". The result count is mono data, and the empty state distinguishes "nothing is running" from "nothing matches the filter" (the latter offers a filter reset). Login required, like the event server list; the permanent servers keep their own page at `/servers/permanent`.

### 4. Navigation

- **Reachable:** every page is reachable from the sidebar (`components/AppSidebar.vue`) or a visible in-page link/CTA. No orphan pages. Cross-event items go in the "Allgemein" group, event-scoped items in the "Aktuelle LAN" group (shown when `currentEvent` exists); secondary event modules with a user-facing index (Essensbestellung, Abstimmungen, Mitspieler finden, Dateien, Spielserver) live in the "Mehr" group right below it, each gated by `useModules().has(...)`, and are mirrored as buttons on the event overview (`Event/Show`); staff get "Check-in" and "Infoscreen-Steuerung" in the event group; status-dependent items (e.g. "Anmeldung" only while `status === 'registration'`) are conditional entries.
- **Desktop app entry points:** the shared Inertia prop `desktopClient` (`DesktopClientOffer`: `null` for guests and without WebVoice routes, else `{url, version, os, available}`; manifest summary cached 60 s) drives the sidebar entry "Desktop-App" (dot = a build for your OS), the dismissible `DesktopClientHint` on the Dashboard (only with a matching build, never on phones/tablets, dismissal kept in `localStorage` per client version) and the quiet `DesktopClientLink` on `/talk/connect`, `settings/connections` and the registered state of `Event/Register`. `GET /app` (`client.download.short`) redirects to `/talk/client` for printed material.
- **Contextual instead of sidebar:** detail pages, flows and one-off pages (ticket, a single tournament, team edit) are reached via links from their list or the Dashboard.
- **Module gating:** frontend `useModules().has('<key>')` around nav items and links; backend routes via `$modules->routable(Module::X)`; backend cross-module calls via `Modules::enabled()`/events/contracts (architecture ratchet in `tests/Architecture`).
- **Active state:** `NavMain` highlights by exact URL; set `prefix: true` on a `NavItem` whose detail pages live below it (e.g. `/teams/{id}`), or `isActive` to override (the "Aktuelle LAN" link yields to the event "Übersicht").

### 5. Copy

- German, sentence case, plain verbs; one name per action across button, toast and heading ("Anmelden" → "Angemeldet"). UI copy comes from `lang/de/*.php` via props, or inline German for static chrome; no English leftovers (also `aria-label`, placeholders, `<title>`).
- Empty states invite ("Du bist noch in keinem Team. Gründe eins oder tritt einem bei."); errors say what failed and what to do, never apologise; no exclamation marks.

### 6. Event status → what the user sees (`Event/Show.vue`, `Event/Seating.vue`, `Dashboard.vue`)

| Status | Registered? | Event page | Seating page |
|--------|-------------|-----------|--------------|
| `draft` | any | not publicly visible (404) | — |
| `announced` | no | hint "Die Anmeldung öffnet bald." (no button) | "Der Sitzplan öffnet mit der Anmeldung." |
| `registration` | no, spots free | primary "Jetzt anmelden" (+ "Noch X Plätze frei" if limited) | "Melde dich zuerst zum Event an …" + button "Zur Anmeldung" |
| `registration` | no, full | hint "Ausgebucht" (no button) | "Ausgebucht — alle Plätze sind vergeben …" |
| `registration` | yes | "Du bist angemeldet" + Ticket / Sitzplatz links | claim/release seat |
| `live` | no, spots free | hint "Das Event läuft — du kannst dich noch anmelden." + primary "Jetzt anmelden" (walk-ins) | same as `registration` |
| `live` | no, full | hint "Ausgebucht" | "Ausgebucht — …" |
| `live` | yes | "Du bist angemeldet" (+ "eingecheckt") + links, `LiveIndicator` | claim/release seat |
| `finished`/`archived` | any | "Dieses Event ist vorbei." + "Zum Rückblick" (recap module) | registration-closed hint |

**One rule decides "registration open":** `Registration\Enums\RegistrationAvailability::for($event)` (`upcoming|open|full|closed`) — open in `registration` OR `live` while capacity remains (`max_participants`, `null` = unlimited, cancelled registrations do not count). RegisterForEvent, `RegistrationPolicy::create`, the controllers and every Inertia prop (`availability` + `freeSpots`; on `currentEvent` for the sidebar "Anmeldung" item) use it — never compare `EventStatus` for this.

Arrival plan (optional, see [arrival-planning.md](arrival-planning.md)): with a registration, `Event/Register.vue` shows "Ankunft und Abreise" (values or "noch offen" + "Angeben"/"Ändern") and the Dashboard ticket card the same lines; editable until the event is `finished`/`archived`, read-only with a hint afterwards (`registration.arrival.editable`). The registration form carries the same optional fields.

Backing props: `availability`/`freeSpots` (see above), `registration` (`Registration\Support\MyRegistration::summary`, never the QR payload) and `registrationState` (`upcoming|open|closed`).

**Tournament entry CTAs** (`components/tournaments/TournamentEntryActions.vue`, shared by `Tournaments/Index` and `Tournaments/Show`; decided by `TournamentPolicy`, see [qa/rulings.md](qa/rulings.md) A/B): enrolling and checking in need a non-cancelled event registration (orga exempt) — without one the CTA is replaced by "Melde dich zuerst zum Event an …" plus "Zur Event-Anmeldung" while `RegistrationAvailability` is `open`, otherwise "Die Event-Anmeldung ist derzeit nicht geöffnet." (props `registration.{required,open,url}`).

| Tournament status | Viewer state | Shown |
|---|---|---|
| `announced` | any | hint "Anmeldung öffnet bald" (no sign-up button); the show page lists game, mode, slots, start and the rules |
| `enrollment` | registered for event, no entry | primary "Anmelden" |
| `enrollment` | entry | badge "Angemeldet" + ghost "Abmelden" (`confirm()`, own entry / team owner only) |
| `check_in` | entry not checked in | outline "Einchecken" + "Abmelden" |
| `check_in` | checked in | badge "Eingecheckt" + "Abmelden" |
| `live`/`finished` | any | no entry actions (withdraw closes when the tournament starts) |

**Match card, reporter side:** while the own claim is still pending the card shows "Warte auf Bestätigung durch <Gegner>" plus "Ergebnis ändern" (inline form prefilled with the claim, "Änderung melden") and "Meldung zurückziehen". Both are gone as soon as the opponent confirms or disputes (`ReportStatus` Withdrawn is the audit trail of a taken-back claim).

Team tournaments (`team_size > 1`) replace the one-click "Anmelden" with a team picker (shadcn `Select` of the viewer's own teams, `TeamPicker` read model) + "Mit Team anmelden"; teams whose roster is not exactly `team_size` are listed but disabled ("2 von 3 Mitgliedern"). A viewer without a team gets a hint plus "Team erstellen" (link to `/teams`). Solo tournaments stay one-click.

**Admin lifecycle actions (Filament, orga only, `TournamentLifecycleActions`):** the status badge sits under the tournament title (`EditTournament::getSubheading`, `x-filament::badge`) and the next steps are header actions (also row actions on the list), each with a confirmation modal and conditioned on the status. A tournament is created as `draft` and is invisible to everyone but orga (tournaments pages, overlays, Discord `/tournament`, schedule) until announced; `Tournament::scopeVisibleTo` / `isVisibleTo` is the one rule:

| Status | Admin actions shown |
|---|---|
| `draft` | "Ankündigen" and "Anmeldung öffnen" (shortcut, announces and opens in one step); both validate game, max. Anmeldungen, Check-in-Fenster and list what is missing |
| `announced` | "Anmeldung öffnen" and "Zurück zum Entwurf" |
| `enrollment` | "Zur Check-in-Phase", "Turnier starten", and "Zurück zum Entwurf" (only while there are no non-withdrawn entries) |
| `check_in` | "Turnier starten", "Zurück zum Entwurf" (no entries only) |
| `live`/`finished` | none (bracket/dispute tooling only) |

**Dates and times:** always render instants through `resources/js/lib/datetime.ts` (`formatInstant`, `dayKey`), which formats in the one display timezone (`displayTimezone` shared prop, `APP_DISPLAY_TIMEZONE`, default Europe/Berlin) — never `toLocaleString()` on the browser zone. See [deploy-lan-raute1.md](deploy-lan-raute1.md#timezone).

### 7. New page checklist

- [ ] Entry in `resources/js/lib/pageLayouts.ts` (guard test green).
- [ ] Route behind `auth` (or consciously public + guest-safe), module-gated if optional.
- [ ] Authorization through a Policy; no client-supplied user IDs.
- [ ] Reachable: sidebar entry, Dashboard/contextual link, or visible in-page CTA.
- [ ] `<PageContainer>` with a width tier, one `<h1>` via `title`/`#header`, `back` for sub-pages, spacing from the scale.
- [ ] Empty, loading, error and normal states implemented.
- [ ] Only semantic tokens (no hex, no `<style>`); amber only for primary action/live.
- [ ] Visible focus, `motion-reduce`, usable at 375px, AA contrast.
- [ ] German copy, consistent action names, no English leftovers.
- [ ] Pest feature test for new props/controller; `composer check` + `types:check`/`lint:check`/`format:check`/`build` green.

## Start-page chat (the front door) — pilot

Since the one-click launcher pilot the guest landing page (`Welcome`, `/`) has one job: get a
first-time visitor from "what is this?" to a running game without reading a manual. It is laid out
in two bands, and the order is binding.

**Above the fold, in this order:**

- the **chat** — greeting plus input, the first interactive thing on the page;
- the **three large primary tiles**, left to right: **Spiele verwalten** → `/games`,
  **Serverbrowser** → `/servers`, **Voice-Chats** → `/talk` while voice is live, otherwise the
  native voice client or its download entry;
- a fourth, smaller **`?` Handbuch** button beside them, which opens the user-facing handbook
  (usage around the three activities, food ordering, chat integration, and a preloaded example
  conversation that shows the launcher install).

Everything that used to be the landing page (tagline, Discord login, feature list) moves **below
the fold**; the Discord login stays reachable there and in the sidebar. A huge **Launcher** button
sits at the very top of the sidebar, above the nav, not only in the hero. `Welcome` keeps the
`bare` tier and stays guest-safe: no sidebar, no bell, no user data, no modal on load, no cookie
banner.

The page must render with no active event, no recipes and no optional modules: a module that is
off hides its tile (and its chat cards) instead of showing an empty one, and the Handbuch stays.
The chat never takes focus on load; `Tab` reaches it.

### Chat anatomy

| Part | Rule |
|---|---|
| Header row | the node-L mark + wordmark `LANoMAT`, one `LiveIndicator` for the chat's reachability (`ok` "verbunden", `warn` "nur Lesen", `down` "offline") and one quiet ghost link to the Handbuch. No second navigation. |
| Transcript | one `role="log"`/`aria-live="polite"` region, `max-h-[60svh]`, scrolls internally only; assistant prose is plain and left-aligned (`text-sm`, no bubble), the visitor's own lines sit right in a `bg-muted` bubble. Streaming appends to the same paragraph (`aria-busy` while running) and is announced once when it settles, never per character. |
| Greeting | instant, not animated, no skeleton: what LANoMAT is, what is playable on this LAN right now, and the three tiles named in one sentence. It is deterministic copy (from `lang/de`), not model output, so the front door is never empty when the model is unavailable. |
| Suggestions | up to three question buttons above the input ("Was kann ich hier tun?", "Installier mir UT2004.", "Wer ist auf dem Server?"); they fill the input and send on the second press, they do not send on the first. |
| Input | one real `<form>`: Enter sends, Shift+Enter is a newline, send is a `Button` with an accessible name ("Fragen"). The input stays enabled while an answer streams and during every error state. |
| Cards | rendered inline in the transcript from tool/API data only — never invented, never a screenshot. See below. |
| Offline | calm banner inside the chat: "Der Chat ist offline. Spiele und Server funktionieren weiter." The tiles stay live; the input stays usable and the deterministic fallback still answers from the catalogue. |

The chat is the third door to the same data, not a second store: every card is the same read model
as the page it links to (`/games/<slug>`, `/servers`, the launcher's install record). A card is
only rendered when its data exists; the answer text alone is the fallback.

### Typewriter and backspace (the suggestion line)

While the input is **empty and unfocused**, its placeholder cycles through the three suggestions
as a typewriter. The rules are deliberately boring — this is the only ambient motion on the page:

- **Type** at about 35 characters per second (one character every ~28 ms), including the trailing
  punctuation; no per-character random jitter.
- **Hold** the finished phrase for 2.4 s so it can be read.
- **Delete** at about 70 characters per second (~14 ms per character) — visibly faster than typing,
  which reads as "backspace", not as a second animation.
- **Pause** empty for 0.4 s, then type the next phrase. No fade, no slide, no bounce.
- **Stop permanently** on the first of: focus into the input, the first keystroke, a sent message,
  `visibilitychange` to hidden (a background tab does not burn frames) — and after one full cycle
  through the three phrases the first one is left standing. Motion that never ends is noise.
- **Layout stability:** the line owns a fixed single-line height (`min-h-11`) and truncates; the
  typed text never wraps and never pushes the tiles. The caret is a 1 px `--primary` bar that
  blinks at ~1.1 s only while characters are being typed or deleted.
- **Screen readers:** the cycling text is `aria-hidden="true"`; the input keeps one stable
  placeholder/label (the first phrase), so nothing re-announces per keystroke. The typed string is
  decoration, not content.
- **`prefers-reduced-motion: reduce`:** no typing, no deleting, no caret blink — the first
  suggestion appears instantly and stays. The same switch removes card entry fades and the
  streaming caret; text still streams, it just does not animate.

### Inline cards

All three card types use the app card (`rounded-md border border-border bg-card p-5`), one heading,
one line of prose, mono for machine data, and **at most one** amber action. They fold to full width
at 375 px and keep their action order.

| Card | Content | Actions |
|---|---|---|
| **Game** | title, one-line summary, platform/version in mono, `LAN` badge when the content or a server is on the LAN | `Installieren`; without a launcher build `Launcher herunterladen`; with an install record `Starten`; always one quiet "Seite öffnen" → `/games/<slug>` |
| **Server** | server name, map and `players/max` in mono, the join string as a copyable mono value (a plain `address:port` is never a dead button) | `Beitreten` when a join template exists, quiet "Serverbrowser" → `/servers` |
| **Install** | the launcher's real state: phase (Planen, Herunterladen, Entpacken, Patchen, Prüfen, Fertig), current file, `bytes_done / bytes_total`, speed, ETA and a determinate or indeterminate bar; a percent that is unknown stays indeterminate | `Abbrechen`, then `Erneut versuchen` or `Starten`; without launcher data the card only offers the download |

No fake progress anywhere: the browser shows the launcher's last reported state and never
simulates a bar; a percent the launcher does not know is `—`, not `0 %`.

### States (chat and cards)

| State | Pattern |
|---|---|
| empty | no transcript history: greeting + suggestions only, no empty frame with "keine Nachrichten" |
| loading | skeletons only for restored history; greeting and tiles are already there |
| error | "Der Chat antwortet gerade nicht." in the transcript, input stays usable, one `Erneut fragen` |
| normal | answers stream, cards appear as their data arrives |

### Why this stays Signalpult

The chat is a quiet desk, not a mascot: Space Grotesk prose, mono only for numbers/addresses,
one rationed amber action per card, no avatar animation, no sound. The launcher windows use the
same anatomy and the same German action words (`Installieren`, `Starten`, `Abbrechen`,
`Erneut versuchen`) and the same six phase names; only their chrome follows the launcher's own
UT2004/Epic brand world (see the one-click launcher spec). A change to the card anatomy, the phase
names or the action wording touches both surfaces in the same PR.

## Multi-terminal LAN (one launcher per machine)

A LAN party is not one computer. The model is deliberately boring, and the UI must not contradict
it:

- **The server owns the catalogue.** `/games`, the start-page chat and every launcher read the same
  published recipes and server read models; a recipe the Orga publishes appears on every terminal
  without per-machine configuration.
- **Every terminal is its own device.** Each launcher pairs separately through the device flow and
  stores its own bearer token (0600); there is no "the" machine and no shared credential. The
  launcher appears as its own entry under **Verbundene Geräte** on `/mcp/connect`, and revoking it
  there disconnects exactly that terminal.
- **Installs are local.** Download, extract, patch and verify happen on the terminal into its own
  target folder (`~/Games/...`, `C:\Games\...`). Two machines installing the same game are two
  installs, and the second one still downloads from the LAN file host — never from the internet.
- **The local library is the truth for "installed".** It survives a restart and an offline server;
  a terminal without a reachable LANoMAT shows its verified installs and can still start them,
  while the catalogue area says the server is offline.
- **One setting per machine: the LAN URL.** No ports, no paths, no per-game configuration — the
  address typed once in **Verbinden**, and the recipe carries everything else.
- **LAN-first, always.** The pilot's files come from the LAN file host (portable archive first, ISO
  as fallback); an internet download is a fallback the recipe pins, not the default path.

UI consequences: copy says "auf diesem PC" (not "dein Account"), every install/percent/`Starten`
state is per terminal, and the browser never guesses another machine's installs. The web `/games`
page may show what a paired launcher reported for *this* device; it must not invent a global
"installed" flag.

### Terminals in the room (checklist)

- [ ] Each machine runs its own launcher and its own pairing — no copied config file between PCs.
- [ ] The LAN URL is the same everywhere; the display name of the device is recognisable in
      `/mcp/connect` (rename it there before the LAN, not during it).
- [ ] The catalogue is published once on the server; no terminal needs recipes copied locally.
- [ ] The LAN file host serves the install files; check one fresh terminal with no internet.
- [ ] A terminal that installed a game keeps **Starten** while the server is offline.
- [ ] Departed or re-imaged machines are revoked under **Verbundene Geräte** on `/mcp/connect`.
- [ ] Only UT2004 is installable in the launcher today; every other published recipe is
      catalog-only until the recipe engine is generic.

### Arcade terminals: sign in by QR

An arcade terminal is a shared machine in the room, not a personal PC. Nobody types an account
there — the terminal shows the device flow as a **QR code** and the player signs in with the phone
they already have in their pocket.

| Step | What the screen shows | What the phone does |
|---|---|---|
| 1 | **Verbinden** → a QR of `/mcp/connect?code=…` and the same code as large mono text, plus the server address | scan the QR (or open the URL) |
| 2 | "Warte auf Bestätigung" with a mono countdown; the code is `aria-hidden`, the wait text is the live region | Discord login, then approve the device name shown on the page |
| 3 | "Angemeldet als <Name>" and the library | — |
| 4 | expired / denied / network error with **Neuen Code erzeugen** or **Serveradresse ändern** | — |

Rules:

- **One terminal, one device, one token.** The terminal stores its own bearer token exactly like a
  personal launcher; the account can be the same on ten machines, the devices are ten entries.
  Approving on `/mcp/connect` is the only write; `/mcp/connect` lists every terminal under
  **Verbundene Geräte** with its state (online, current game, last seen) and revokes one at a time.
- **The QR contains no secret.** It is the same `verification_uri_complete` the personal pairing
  already uses; the code is also printed as text so a screen reader and a broken camera both work.
  A QR is never a login by itself: approval happens in the signed-in browser session.
- **Arcade mode is kiosk.** Fullscreen, no OS chrome beyond the window frame, large targets,
  complete keyboard operation and gamepad operation for the same actions (focus order: QR →
  **Verbinden** → library → **Installieren**/**Starten** → **Abmelden**). No hover-only affordances,
  no drag, no text selection on the kiosk surface.
- **Handover is explicit.** **Abmelden** (labelled "Abmelden / Reset" in arcade mode) clears the
  local session and the pairing so the next player scans their own code; it does **not** delete
  installed files, so the terminal's local library stays usable and the next player sees the same
  verified games. One confirmation, no second dialog chain.
- **Monitoring is per terminal.** The device row in `/mcp/connect` (and the Preflight view that
  reads it) shows **online**, **aktuelles Spiel** and **zuletzt gesehen** for each terminal; a
  terminal that goes dark is visible there, not by walking the room.
- **No personal data on the kiosk surface.** Display name only; no e-mail, no Discord id, no token;
  the library and the install progress are terminal state and stay on screen for whoever sits down.

## Two apps, one shell (parity rules)

LANoMAT ships **two UI stacks** that must read as one product:

| | Participant app | Admin panel |
|---|---|---|
| Stack | Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue | Filament v5 (Livewire/Blade) at `/admin` |
| Shell | `resources/js/layouts/*`, `components/AppSidebar.vue`, `AppLogo*.vue`, `UserMenuContent.vue` | `app/Providers/Filament/AdminPanelProvider.php`, `resources/views/filament/**` |
| Tokens/CSS | `resources/css/app.css` (compiled by Vite) | Filament's own compiled CSS and `--primary-*` / `--gray-*` variables |

**Why Filament ignores app Tailwind classes:** the panel loads Filament's prebuilt stylesheet, not our Vite bundle. A class such as `h-6` or `gap-2` in a Blade view under `resources/views/filament/` silently does nothing. Use `fi-*` classes, Filament components (`<x-filament::button>`), render hooks and inline styles (or a small scoped `<style>`) there.

### Shell contract

Single definition: `App\Support\Brand\ShellParity`. Canonical logo geometry: `public/logo-mark.svg` (the Filament brand view derives from it at render time).

| Contract row | Participant app | Admin panel |
|---|---|---|
| Brand name / wordmark `LANoMAT` | `AppLogo.vue` | `brandName()` + `filament/brand.blade.php` |
| Logo mark geometry | `AppLogoIcon.vue` (inline, for HMR) | `filament/brand.blade.php` (derived from `logo-mark.svg`); `favicon.svg` copy |
| User menu at sidebar bottom | `AppSidebar.vue` footer | `userMenu(position: Sidebar)` |
| Sidebar collapsible on desktop | shadcn `Sidebar` | `sidebarCollapsibleOnDesktop()` |
| User-menu items, German | "Admin-Bereich", "Einstellungen", "Abmelden" | "Zur App", Filament profile/logout |
| Admin marker present **only** in admin | none (must stay absent) | neutral "Admin" badge in the brand + 3px top stripe (`PanelsRenderHook::BODY_START`) |
| Back link to app always visible in admin | n/a | "Zur App" button (`PanelsRenderHook::TOPBAR_END`) + user-menu item |
| "Desktop-App" entry (while the WebVoice module is routable) | `AppSidebar.vue` "Allgemein" group, green dot when a build for the visitor's OS exists | Filament navigation item to `/app` (`ShellParity::DESKTOP_APP_LABEL`) |
| "Launcher" entry (while the GameRecipes module is routable) | `AppSidebar.vue` "Allgemein" group (`ShellParity::LAUNCHER_LABEL`, `/launcher`) | Filament navigation item to `/launcher` (same label/URL) |
| "Geräte & MCP" user-menu entry | `AppSidebar.vue` / user menu (`ShellParity::DEVICES_MCP_LABEL`, `/mcp/connect`) | Filament user-menu item (same label/URL) |

**Marker colour choice:** the badge and stripe are neutral high-contrast (Filament `gray-950` on light, `gray-50` on dark), not amber. Amber is rationed to the primary action and live state (see above); using it for "you are in admin" would dilute that signal.

**Rule: a change to any row of the contract touches both apps in the same PR.**

**Verify visually:** log in with the dev "Als Demo-Orga" button, then look at both `/dashboard` (participant) and `/admin`, in light and dark. Tailwind-only changes can look right in one and broken in the other.

**The net:** `tests/Feature/ShellParity/ShellParityTest.php` fails if the logo copies drift, the panel config leaves the contract, the user-menu labels change, the admin marker/back link disappear or leak into the app, or a Filament view uses app-only Tailwind utilities (a regex heuristic over `class="..."` in `resources/views/filament/**`; `fi-*` is allowed). The PR template carries the matching checklist item.

## Beamer (the loud surface)

`/screen/{event}` is the exception to "quiet". Near-black canvas, huge type, the amber accent at full brightness, celebratory motion (winner confetti, tombola reveal) — but still disciplined: one focal thing per scene, legible at distance, high contrast. The winner/celebration colour aligns to `--live` amber (not an ad-hoc yellow).

### Beamer legibility floor and bracket fitting

Nobody scrolls a beamer: no scene may rely on scrolling or silent clipping. Lists have a fixed cap with a "+N weitere" line (upcoming matches 8, presence 5 per column, schedule 6 rows after now/next); announcement text is limited to 280 characters (headline clamps to 3 lines, body to 6); scene duration is 5 to 300 s.

Bracket scenes and the OBS bracket overlay use `BracketView` in `fit` mode: the bracket is scaled with a CSS transform to its box (small brackets scale up to 2.5x, there are no scrollbars; the overlay keeps its transparent background). The legibility floor is scale 1.15 at 1920x1080 (names about 16 px, status labels about 14 px; proportionally less on smaller viewports). A bracket that cannot fit at the floor (64 players, double elimination 16 and up) is not shrunk into illegibility: rounds with too many matches are left out (always the early ones, announced by "Frühe Runden ausgeblendet"), the remaining rounds are split into windows that rotate every 10 s ("Ansicht 1 von 2"), and the window holding the round currently being played starts. Reason: a beamer is read from several metres, a complete but unreadable tree conveys less than the decisive rounds.

The tombola reveal is time-boxed (the 20 s override); the rotation scene always shows the prize board. The public beamer shows display names of seated participants and tombola winners (product decision); e-mail, Discord id, user ids and network data never appear in HTML, props or broadcasts.

## Rams' ten principles → how they land here

1. **Innovativ** — uses the live web platform deliberately (Reverb live state, Inertia), not novelty for its own sake.
2. **Brauchbar** — serves the 10-minute principle; the shortest path stays shortest.
3. **Ästhetisch** — one calm token system, graphite + a single rationed accent.
4. **Verständlich** — clear hierarchy, all four states, plain German copy.
5. **Unaufdringlich** — the app recedes; content (bracket, schedule, seats) leads.
6. **Ehrlich** — real state, no dark patterns; bell = truth.
7. **Langlebig** — token-based, trend-free, maintainable.
8. **Konsequent bis ins Detail** — focus/keyboard/dark-mode/beamer-legibility.
9. **Umweltfreundlich** (→ Software) — lean bundles, performance, a11y.
10. **So wenig Design wie möglich** — reduction before addition; amber rationed; two faces only.
