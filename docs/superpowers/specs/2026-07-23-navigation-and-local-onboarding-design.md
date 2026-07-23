# Navigation & Local Onboarding — Design

**Date:** 2026-07-23
**Status:** Draft (awaiting review)
**Origin:** JB (@schn4ppi) feedback after running m13 from a fresh prod-profile
clone — the authenticated sidebar showed only three links, the public pages had
no shared navigation, and there is no way into a local clone without a real
Discord app.

## 1. Goal

Make LANoMAT navigable and locally approachable:

1. **Authenticated sidebar** — replace the three-link starter-kit scaffold with a
   grouped, current-event-aware navigation that surfaces the app's real
   destinations.
2. **Public header** — give the chrome-less public pages (`PublicShell`) a slim
   shared header so visitors can get back and around.
3. **Local seeder-login** — let someone peek into a local clone without a Discord
   app, via a demo login that exists **only** under `APP_ENV=local`.

Non-goals: redesigning the (empty placeholder) Dashboard page; changing the real
Discord-only production login; adding new feature pages.

## 2. Current state

- `resources/js/components/AppSidebar.vue` hardcodes three `NavItem`s (Dashboard,
  Freunde, Voice einrichten). Almost every participant destination is missing.
- `Dashboard.vue` is the unmodified starter-kit placeholder (three
  `PlaceholderPattern` boxes) and is the logo/home target.
- `resources/js/layouts/PublicShell.vue` is eight lines: a `<slot/>` + `<Toaster/>`,
  no navigation. Used by `Event/`, `Orga/`, `Jukebox/`, `Recap/` pages (guest- and
  auth-reachable).
- Login (`auth/Login.vue`) is Discord-only UI (a single "Mit Discord anmelden"
  button → `/auth/discord/redirect`). Fortify password login works in the backend
  (the screenshot pipeline logs in with email+password) but is not exposed in the UI.
- `HandleInertiaRequests::share()` already exposes `currentEvent` (name, slug,
  status, …) to **both guests and authenticated users**, and `auth.user`. The
  `User` model has a `role` (`Role` enum: Admin/Orga/Helper/Participant) and
  `isOrga()`/`isHelper()` helpers, and already appends a computed `has_password`
  boolean for the frontend.
- Route facts that shape the IA: `events.index` (`/events`), `teams.index`
  (`/teams`), `stats.leaderboard` (`/stats/leaderboard`), `friends.index`
  (`/friends`), `voice.setup` (`/voice/setup`) are **global**. `events.show`,
  `events.seating`, `tournaments.index` (`/events/{slug}/tournaments`),
  `schedule.index`, `catering.show`, `polls.index`, `lfg.index`, `servers.index`,
  `files.index`, `presence.show`, `jukebox.index`, `gallery.index`, `recap.show`,
  and check-in are all **event-scoped** (`/events/{slug}/…`).

## 3. Component: authenticated sidebar

Rework `AppSidebar.vue` into two `SidebarGroup`s (shadcn-vue components already
vendored under `components/ui/sidebar`), each with a `SidebarGroupLabel`.

**Group "Allgemein" (always shown), in order:**

| Label | Route | Icon (lucide) |
| --- | --- | --- |
| Aktuelle LAN | `events.show(currentEvent.slug)` if a current event exists, else `events.index` | `PartyPopper` |
| Events | `events.index` | `CalendarDays` |
| Teams | `teams.index` | `Users` |
| Bestenliste | `stats.leaderboard` | `Trophy` |
| Freunde | `friends.index` | `UserPlus` |
| Voice einrichten | `voice.setup` | `Mic` |

(The empty Dashboard is dropped from the nav; its route stays registered.)

**Group "Aktuelle LAN: {name}" — rendered only when `currentEvent !== null`**,
all hrefs built from `currentEvent.slug`, in order:

| Label | Route | Visible to |
| --- | --- | --- |
| Übersicht | `events.show` | all |
| Zeitplan | `schedule.index` | all |
| Turniere | `tournaments.index` | all |
| Sitzplan | `events.seating` | all |
| Präsenz | `presence.show` | all |
| Jukebox | `jukebox.index` | all |
| Galerie | `gallery.index` | all |
| Check-in | `orga.checkin` | **Orga/Helfer only** |

The longer tail (Catering, Abstimmungen, LFG, Dateien, Server) stays reachable
from the event Übersicht page and is deliberately **not** in the sidebar, to keep
it scannable.

**Role gating.** The Check-in link is gated on a boolean the frontend can read
without duplicating role-hierarchy logic. Add an appended computed
`is_staff` boolean to the `User` model (`get: fn () => $this->isHelper()`,
mirroring the existing `has_password` `#[Appends]` pattern; `isHelper()` already
means Admin∨Orga∨Helper) and add `is_staff: boolean` to the `User` TS type. Server
policies remain the only authorization boundary — `is_staff` only decides whether
to render a convenience link, never grants access.

**Active state.** The current route's link is visually marked (the existing
`NavMain`/`SidebarMenuButton` already supports an active style via
`isActive` on `SidebarMenuButton`; use `page.url` prefix matching).

**Signalpult.** Semantic token utilities only (no raw hex), lucide icons sized
consistently, visible focus, existing sidebar collapse behaviour retained. Invoke
`frontend-design` before building the sidebar UI.

## 4. Component: home target

The logo/home link currently points at `dashboard()`. Change both the sidebar
header link and the public header logo to a shared helper that resolves to
`events.show(currentEvent.slug)` when a current event exists, else `events.index`.
The `dashboard` route stays registered (reachable directly), it is just no longer
the entry point.

## 5. Component: public header (`PublicShell.vue`)

Add a slim `<header>` above the existing `<slot/>` (keep `<Toaster/>`).

Contents (single row, wraps on mobile):

- **Left:** logo → home (same resolver as §4).
- **Centre/left:** current LAN name; when the event is live, prefixed by the
  `LiveIndicator` signature component. Hidden entirely when `currentEvent === null`.
- **Public links** (only when `currentEvent !== null`, hrefs from its slug):
  Präsenz (`presence.show`), Jukebox (`jukebox.index`), Recap (`recap.show`).
- **Right:** if `auth.user` is null → a "Anmelden" button (→ `login`); else a
  compact user affordance (name/avatar linking to `profile.show` for that user,
  reusing existing avatar rendering — no full sidebar `NavUser` dropdown needed).

Calm Signalpult styling (this is the calm app, not the loud beamer): muted
background, one hairline divider, `font-mono` only for machine-ish data if any,
responsive down to 375px, visible focus, `prefers-reduced-motion` respected by the
`LiveIndicator`. Invoke `frontend-design` before building.

`PublicShell` is used by authenticated pages too (Event/Orga/Jukebox) and
guest-reachable ones (Recap, public Jukebox), so the header must render correctly
for both — the auth/guest branch on `auth.user` covers this. `currentEvent` may be
null (e.g. a past-LAN recap whose event is not the "current" one) — every
event-dependent element is guarded on `currentEvent`.

## 6. Component: local seeder-login

**Route.** `POST /dev/login/{role?}` (name `dev.login`, `role` ∈ {`participant`,
`orga`}, default `participant`), handled by a `DevLoginController`. **First line
guards `abort_unless(app()->environment('local'), 404)`** — the route is a 404 in
every non-local environment (testing/staging/production), so it cannot exist as an
attack surface off a dev machine.

**Behaviour.** `firstOrCreate` an idempotent demo user by a fixed email
(`demo-participant@lanomat.local` / `demo-orga@lanomat.local`), with a
`name` ("Demo-Teilnehmer" / "Demo-Orga"), the matching `role` (forceFill — `role`
is a privilege field, never mass-assigned), and a random password. `Auth::login()`
it, regenerate the session, redirect to home. No Discord, no snowflake check.

**UI.** A `devLoginEnabled` boolean is shared to the login page (via the Login
view's Inertia props from `FortifyServiceProvider::loginView`, computed as
`app()->environment('local')`). `auth/Login.vue` renders a "Demo-Login (nur lokal)"
block with the two buttons **only when `devLoginEnabled` is true**. The real
Discord button stays the primary, always-present path.

**Data caveat (documented, not built).** The demo users are not auto-registered to
any event; the richest experience (the "Aktuelle LAN" sidebar group, live pages)
appears after seeding demo data (`ScreenshotSeeder`). The setup docs note: migrate
→ seed (`ScreenshotSeeder`) → demo-login.

**Boundary.** The demo users use `@lanomat.local` addresses so they are trivially
distinguishable and never collide with real Discord-provisioned accounts (which
have `discord_id` set and real emails).

## 7. Testing (Pest, sequential; Vue gates)

- **Seeder-login (the security core):**
  - Under `local`: `POST dev.login` creates+logs in the demo participant; a second
    call is idempotent (no duplicate user); `dev.login/orga` yields an Orga-role
    user.
  - Under a non-local environment (`app()['env'] = 'production'` in the test):
    `POST dev.login` returns **404** and no user is authenticated. This is the
    load-bearing assertion.
  - The login view shares `devLoginEnabled === true` under local and `false`
    otherwise.
- **Sidebar / header (Vue):** `npm run types:check`/`lint:check`/`build` stay
  green. Lightweight Inertia/component assertions where practical: the "Aktuelle
  LAN" group and the public-header LAN links render only when `currentEvent` is
  present; the Check-in link renders only for an `is_staff` user. (No screenshot
  assertions.)
- **Shared user prop:** `auth.user.is_staff` is `true` for an Orga/Helfer and
  `false` for a plain participant (feature test on the middleware/Inertia share).

## 8. Docs

- `README.md` setup section: a short "Peek into a local clone" note — under
  `APP_ENV=local`, seed demo data and use the Demo-Login buttons (no Discord app
  needed).
- Keep `README.md`'s project-state accurate if these ship (per the standing
  "keep README current" rule).

## 9. Decomposition & order

One spec, one implementation plan. Natural task order: (1) `is_staff` shared prop,
(2) home-target resolver + sidebar rework, (3) public header, (4) seeder-login
(route + controller + login-view flag + UI), (5) docs. The seeder-login is
independent of the nav work; the nav pieces share the home-target resolver.
