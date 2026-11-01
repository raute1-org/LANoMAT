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
| `profile` | `AppLayout` if logged in, bare for guests | `Profile/Show` (`/users/{user}` stays publicly reachable) |
| `bare` | none | `Welcome` (guest landing, no event data), `Screen/*` (beamer), `Overlay/*` (OBS) |

Choosing for a new page: behind `auth` middleware → `app` (default answer). Reachable by guests → it must be guest-safe and `bare`/`profile`. Unattended or transparent surfaces → `bare`. There is no `PublicShell`; the `<Toaster/>` is mounted once, inside `AppSidebarLayout`, so flash toasts only work on `app`/`settings` pages.

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

### 4. Navigation

- **Reachable:** every page is reachable from the sidebar (`components/AppSidebar.vue`) or a visible in-page link/CTA. No orphan pages. Cross-event items go in the "Allgemein" group, event-scoped items in the "Aktuelle LAN" group (shown when `currentEvent` exists); secondary event modules with a user-facing index (Essensbestellung, Abstimmungen, Mitspieler finden, Dateien, Spielserver) live in the "Mehr" group right below it, each gated by `useModules().has(...)`, and are mirrored as buttons on the event overview (`Event/Show`); staff get "Check-in" and "Infoscreen-Steuerung" in the event group; status-dependent items (e.g. "Anmeldung" only while `status === 'registration'`) are conditional entries.
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
