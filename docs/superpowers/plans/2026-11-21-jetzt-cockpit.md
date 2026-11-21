# Participant UI: "Jetzt" home, one server overview, slim navigation

Approved direction: clickable prototype "LANoMAT Jetzt-Cockpit" (Design canvas, private to the
owner), signed off on 2026-11-21. This plan is the executable breakdown, derived just in time per
PR like the earlier phase plans.

**Goal:** a participant opens LANoMAT and immediately sees what is going on — which games are
being played, what they want to play, food, their next match — with few clicks. Navigation shrinks
to what matters for the current LAN, and every kind of game server (match, tournament, event,
permanent) is on one page.

**Order (ruling):** the server overview ships first, then the "Jetzt" home (including the
`navSignals` data), then the slim navigation that consumes both, then a density pass. This order
means no page is orphaned between merges: the nav is rebuilt once, against final routes.

## Global Constraints

- `docs/design.md` (Signalpult) is binding: semantic token utilities only (`bg-card`,
  `text-muted-foreground`, `text-live`, `text-ok`, `text-warn`, `text-down`, `font-mono` for
  machine data only), `LiveIndicator` for live state, all four states (empty/loading/error/normal),
  one amber primary action per screen, visible focus, responsive down to 375 px, `prefers-reduced-motion`.
- Every Inertia page needs an entry in `resources/js/lib/pageLayouts.ts` and must render inside
  `<PageContainer>`; no hand-rolled `mx-auto max-w-` wrappers (`tests/Architecture/PageLayoutsTest.php`).
- Modular monolith: optional modules (GameServers, Lfg, Catering, Presence, Schedule, Voting,
  WebVoice, …) are reached from core only via events, contracts with null implementations, or a
  `Modules::enabled()` guard (`tests/Architecture/ModuleDependenciesTest.php` ratchet).
- Every authorization goes through a Policy; never trust client-supplied user ids.
- UI copy is German, sentence case, no exclamation marks except the existing "Kopiert!" labels;
  code, comments and commits are English, Conventional Commits.
- Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- Wayfinder route helpers in `resources/js/routes` and `resources/js/actions` are generated and
  git-ignored: regenerate with `php artisan wayfinder:generate --with-form` after route changes.
- Gates before a task counts as done: `composer check` (pint --test, phpstan level 8, pest) for PHP
  changes; `npm run types:check && npm run lint:check && npm run format:check && npm run build`
  for frontend changes.
- No real infrastructure data (IPs, host names, ids) in any file; use `lan.example` style placeholders.

---

## PR A — one server overview at `/servers`

### Task 1: Unified server overview — backend

**Goal:** `GET /servers` (route name `servers.index`) serves one Inertia page `Servers/Index` that
lists the current (or a chosen) LAN's servers and the permanent servers together. The old event
URL redirects there.

**Files**
- Modify: `routes/web.php` (GameServers block, around L227–238)
- Modify: `app/Modules/GameServers/Http/GameServerPageController.php`
- Delete: `app/Modules/GameServers/Http/PermanentServerPageController.php` (logic moves into the controller above)
- Modify: `lang/de/gameservers.php` (new `overview` group)
- Tests (adapt/merge, see below)

**Routes** (inside the existing `auth` group of the GameServers block):
- `GET /servers` → `GameServerPageController@index`, name `servers.index`.
- `GET /events/{event:slug}/servers` → `GameServerPageController@legacy`, name `servers.event`:
  `abort_unless($event->isPubliclyVisible(), 404)`; then a **301** redirect to `/servers` when the
  event is the current event (`app(CurrentEvent::class)->get()`), else to `/servers?event={slug}`.
- The name `servers.permanent` is removed. The three POST admin routes stay unchanged.

**`GameServerPageController@index(Request $request)`**
1. `Gate::authorize('viewPermanent', ServerLink::class)` (same gate the permanent page used).
2. Resolve the event: if the query string has `event`, load `Event` by slug (`firstOrFail`) and
   `abort_unless($event->isPubliclyVisible(), 404)`; otherwise `app(CurrentEvent::class)->get()`
   (may be null — then the event section is simply absent).
3. Props:
   - `event`: `null` or `['name' => …, 'slug' => …, 'isCurrent' => bool]`.
   - `servers`: when an event is resolved, `ServerListProjection::linksForEvent($event)` mapped to
     `ServerListProjection::dto($link, withPlayerList: true) + ['isMine' => ServerListProjection::viewerPlaysIn($link->match, $viewer)]`
     (`isMine` is false for links without a match); otherwise `[]`. Matchservers stay visible to every
     logged-in user (owner ruling).
   - `permanent`: exactly what `PermanentServerPageController` built before (DTO via
     `ServerListProjection::permanentDto($link, withPlayerList: true)` plus `canManage` and, only when
     manageable, `admin {gunGame, canReloadMap, running}`).
   - `adminMaps`: `Cs2Settings::MAPS` when at least one permanent server is manageable, else `null`.
   - `labels`: `trans('gameservers.page') + trans('gameservers.join_ui')` (unchanged shape).
   - `permanentLabels`: `trans('gameservers.permanent.page')`.
   - `overviewLabels`: `trans('gameservers.overview')`.
4. Render `Servers/Index`.

**New lang group `gameservers.overview`** (German):
```php
'overview' => [
    'title' => 'Server',
    'summary' => ':players Spieler auf :online von :total Servern',
    'filter_label' => 'Nach Spiel filtern',
    'filter_all' => 'Alle',
    'section_mine' => 'Dein Match',
    'section_permanent' => 'Dauerserver',
    'section_empty' => 'Hier läuft gerade nichts für diesen Filter.',
    'event_empty' => 'Für diese LAN läuft noch kein Server. Er erscheint hier, sobald ein Match startet oder die Orga einen Server startet.',
    'permanent_empty' => 'Es sind noch keine Dauerserver eingerichtet.',
    'other_event_hint' => 'Du siehst die Server von :event.',
    'back_to_current' => 'Zur aktuellen LAN',
    'details' => 'Details',
    'on_server' => 'Auf dem Server',
    'connect_other' => 'Anders verbinden',
    'orga' => 'Orga',
],
```

**Tests**
- Merge `tests/Feature/GameServers/ServerPageTest.php` and
  `tests/Feature/PermanentServers/PermanentServerPageTest.php` into
  `tests/Feature/GameServers/ServerOverviewPageTest.php` (delete the two old files), keeping every
  behaviour they asserted, re-pointed at `GET /servers` and the new prop names (`servers` for event
  servers, `permanent` for permanent ones): guest redirect to login; draft/unpublished event via
  `?event=` → 404; other events' servers excluded; preset/not-responding/failed status filters;
  no secrets in props; player list present for logged-in viewers; `canManage`/`admin`/`adminMaps` rules.
- New: no current event → `event` null, `servers` `[]`, permanent still listed; `?event=` of a past
  public event lists that event's servers with `isCurrent` false; `isMine` true only for a server
  whose match the viewer plays in; `GET /events/{slug}/servers` → 301 to `/servers` for the current
  event and to `/servers?event={slug}` for another public event, 404 for a draft event.
- `tests/Feature/PermanentServers/PermanentServerIsolationTest.php`: invert deliberately — permanent
  servers appear only in `permanent`, never in `servers`; event servers appear only in `servers`,
  never in `permanent` (the page now shows both, the projection still separates them).
- Re-point `tests/Feature/GameServers/JoinLinkTemplatesTest.php` (≈L72–117),
  `tests/Feature/GameServers/RestartRoundTest.php` (≈L87–133),
  `tests/Feature/Events/EventsRequireLoginTest.php` (≈L25),
  `tests/Feature/Modules/IsolationMatrixTest.php` (≈L46, route name map),
  `tests/Feature/Security/RouteAuthorizationMatrixTest.php` (≈L166 `GET servers`, the `{event}`
  draft-404 sweep ≈L501 now covers `servers.event`, ≈L662–666 admin POSTs) to the new routes.
- Run the full suite: any other test referencing `servers.permanent`, `PermanentServerPageController`
  or the `Servers/Permanent` component must be updated.

### Task 2: Unified server overview — frontend

**Goal:** `resources/js/pages/Servers/Index.vue` renders the prototype's server page: compact rows
instead of cards, sections "Dein Match" / the LAN's name / "Dauerserver", game filter chips, inline
expand, connect + copy in the row.

**Files**
- Rewrite: `resources/js/pages/Servers/Index.vue`
- Create: `resources/js/components/servers/ServerRow.vue`
- Delete: `resources/js/pages/Servers/Permanent.vue`
- Modify: `resources/js/types/gameservers.ts` (`isMine?: boolean` on `GameServerDto`; a page-props type if useful)
- Modify: `resources/js/components/AppSidebar.vue` — interim only: the "Dauerserver" item becomes
  `{ title: 'Server', href: serversIndex(), icon: Server }`; the "Spielserver" item in "Mehr" is removed.
- Modify: `resources/js/pages/Event/Show.vue` — the "Zu den Spielservern" button links to `serversIndex()`.
- Modify: `resources/js/pages/Games/Show.vue` only if it imports `servers.permanent`.
- Modify: `docs/design.md` — replace the "Spielserver page (`Servers/Index`)" paragraph with the new
  row layout description (sections, filter chips, expand, one amber button only on "Dein Match").

**Page props:** `event: {name, slug, isCurrent} | null`, `servers: GameServerDto[]`,
`permanent: GameServerDto[]` (with `description`, `canManage`, `admin`), `adminMaps: string[] | null`,
`labels`, `permanentLabels`, `overviewLabels` (all `Record<string, string>`).

**Layout** (`PageContainer width="wide" :title="overviewLabels.title"`):
```
Server   26 Spieler auf 7 von 8 Servern                      ← h1 + mono summary
[Alle 9] [Counter-Strike 2 4] [UT2004 2] [Minecraft 1] …     ← filter chips (role=group, aria-pressed)
Dein Match (only if any server isMine)
 ● CS2-Cup · Viertelfinale   de_mirage  4/10  lan.example:27016  [Verbinden][⧉] ▾
Winter-LAN 2026  (event name; if !isCurrent: hint "Du siehst die Server von …" + link back)
 ● CS2 Casual               de_dust2   6/10  lan.example:27015  [Verbinden][⧉] ▾
Dauerserver
 ● Minecraft Survival        survival   4/20  lan.example:25565  [Verbinden][⧉] ▾
 ○ Garry's Mod Sandbox       offline
```
- Filter chips: one per distinct `game` across both lists plus "Alle" (default); counts in mono;
  selected chip filled (`bg-foreground text-background`). Filter state is local (`ref`), not in the URL.
- Sections render as `<section>` with an `h2` (`text-sm font-medium text-muted-foreground`) and a
  count; each section's rows sit in one `rounded-md border bg-card` container with `divide-y`.
  "Dein Match" is omitted when empty; event and permanent sections show their own empty sentence
  (`overviewLabels.event_empty` / `permanent_empty`; with an active filter `section_empty`). With
  `event === null` the event section is absent.
- **`ServerRow.vue`** (props: `server`, `labels`, `permanentLabels`, `overviewLabels`,
  `adminMaps`, `primary: boolean`): one `flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2.5` row:
  - an expand `<button aria-expanded>` holding a `LiveIndicator` (existing status→variant mapping,
    pulse only for `provisioning`), title (`server.title ?? server.matchLabel ?? server.game`) and a
    muted subline (`matchLabel`/`game`/`description`), chevron rotating 180° when open;
  - map (`font-mono text-xs text-muted-foreground`, hidden below `sm`), players `players/max`
    (`font-mono tabular-nums`, "–" without query), address `address:port` (`font-mono text-xs`, hidden below `sm`);
  - actions: when the server is joinable (status `ready`/`not_responding` and a connect string):
    "Verbinden" (`Button size="sm"`, default amber variant only when `primary`, else `variant="outline"`;
    a plain `address:port` connect string has no link → copy only) and an icon-only copy button
    (`aria-label` "Adresse kopieren", shows a check for 1.5 s, "Kopieren nicht möglich" on failure);
    otherwise the status label as muted text (e.g. "offline", "Wird angelegt").
  - expanded panel (full-width, `pl-5`): `PlayerListDisclosure` (open by default here), `JoinOptions`
    (when `joinOptions` and address), not-responding / starting / offline hint sentences, install hint
    block (existing markup from the old page), RAM estimate (event servers only), and
    `ServerAdminControls` when `canManage && admin && adminMaps`.
  - Only one row is expanded at a time; the parent owns `openId`.
- "Verbinden" is primary (amber) only for rows in "Dein Match"; everywhere else outline.
- Summary line: total `query.players` over all listed servers, number of servers with status `ready`, total count.
- Partial reload every 20 s of `['servers', 'permanent']` while the tab is visible (keep the
  existing `usePageLoadState` loading/error handling: skeleton rows while navigating, error box on failure).
- Keyboard: all controls are real buttons/links with visible focus; chips are `aria-pressed` toggles.

---

## PR B — "Jetzt" home

Tasks 3–6 are independent read models in different modules and run in parallel (separate
worktrees, separate test databases). Task 8 (frontend) runs in parallel against the props contract
below. Task 7 integrates them and runs last.

### Props contract of the `Now` page (binding for Tasks 3–8)

Rendered by `App\Http\Controllers\NowController` for logged-in users at `GET /` (route `home`);
page component `Now` (`resources/js/pages/Now.vue`, layout kind `app`). Every optional block is
`null` when its module is off — the page hides it then.

```ts
type NowState = 'live' | 'upcoming' | 'none';   // live: event status live; upcoming: announced/registration; none: no current event

interface NowProps {
  state: NowState;
  event: null | {
    id: number; name: string; slug: string; status: string;
    startsAt: string | null; endsAt: string | null; location: string | null;
    availability: string; freeSpots: number | null;          // RegistrationAvailability::summary
    checkedInCount: number; registeredCount: number;          // non-cancelled registrations / checked-in among them
  };
  forYou: { cards: NowCardDto[]; more: NowCardDto[] };       // NowFeed::for(); empty lists when no event
  nowPlaying: NowPlayingRow[] | null;                          // DEFERRED prop; null when game_servers AND presence are off
  wantToPlay: WantToPlayDto | null;                            // DEFERRED prop; null when lfg off or no event
  food: FoodNowDto | null;                                     // null when catering off or no event
  schedule: null | { current: ScheduleItemDto | null; upcoming: ScheduleItemDto[] };   // null when schedule off or no event; upcoming max 3
  me: null | {                                                 // null when no event
    registration: object | null;                               // MyRegistration::summary
    seat: string | null;                                       // seat label from MySeat::for
    team: string | null;                                       // first team name from MyTeams::for
  };
  news: { id: number; title: string; publishedAt: string | null }[];   // NewsQuery::published(3); [] when news off
  permanent: PermanentShortDto[] | null;                       // only for state upcoming|none, max 4; null when game_servers off
  labels: Record<string, string>;                              // trans('now')
}

interface NowCardDto { kind: 'match'|'vote'|'order'|'schedule'|'notification'; id: string; state: string;
  title: string; body: string | null; at: string | null; data: Record<string, unknown>; href: string | null; }

interface NowPlayingRow {
  game: string;          // display name
  people: number;        // max(located participants on this game, players on our servers for this game)
  servers: number;       // our running servers for this game in the current event + permanent
  players: number; maxPlayers: number;   // summed over those servers (0/0 without query data)
  join: null | { serverId: number; title: string; connectString: string; connectIsLink: boolean;
                 address: string | null; port: number | null; players: number; maxPlayers: number };
}                         // sorted by people desc, then game; max 8 rows

interface WantToPlayDto {
  games: { game: string; count: number; names: string[]; mine: boolean }[];  // names: up to 3 other people (display names), sorted count desc
  suggestions: string[];  // game names to offer under "+ Spiel" that are not in `games` (library + currently played), max 8
  hours: number;          // lifetime of a "Bock auf" signal (3)
  toggleUrl: string;      // POST target, see Task 3
}

interface FoodNowDto {
  open: null | { title: string; closesAt: string | null; orderCount: number; mine: string | null; href: string; orderable: boolean };
  next: null | { title: string; opensAt: string };
}

interface ScheduleItemDto { title: string; startsAt: string; endsAt: string | null; }

interface PermanentShortDto { id: number; title: string; game: string | null; status: string;
  players: number | null; maxPlayers: number | null; connectString: string; connectIsLink: boolean; }
```

### Task 3: "Bock auf" — Lfg read model and toggle (Lfg module)

**Files:** create `app/Modules/Lfg/Support/WantToPlayBoard.php`, `app/Modules/Lfg/Actions/ToggleWantToPlay.php`,
`app/Modules/Lfg/Http/WantToPlayController.php`; modify `routes/web.php` (Lfg block), `lang/de/lfg.php`;
tests in `tests/Feature/Lfg/WantToPlayTest.php` (+ `RouteAuthorizationMatrixTest` entry for the new route).

- A "Bock auf" signal **is an `LfgPost`** (no migration): `game` = game name, `title` =
  `trans('lfg.want_to_play.title', ['game' => $game])` ("Bock auf :game"), `body` null,
  `slots_needed` null, `expires_at` = now + 3 h (`ToggleWantToPlay::HOURS = 3`). It is recognised
  as a signal by `body === null && slots_needed === null && title === <that title>`; the existing LFG
  page keeps showing it like any post.
- `WantToPlayBoard::for(Event $event, User $viewer): array` returns the `WantToPlayDto` shape minus
  `toggleUrl`: groups **active** posts of the event by game, case-insensitive and trimmed (display
  name = the most recent post's spelling); `count` = distinct users; `names` = up to 3 display names
  of other users (most recent first), excluding the viewer and users blocked either way with the
  viewer (use `FriendService::blockedUserIdsEitherWay` only when `Modules::enabled(Module::Friends)`);
  `mine` = the viewer has an active post for that game. Posts without a game are ignored. Sorted by
  count desc, then game. `suggestions`: names of `Game` models (core Games module; use the model's
  display name column), not already in `games`, alphabetical, max 8.
- `ToggleWantToPlay::handle(User $user, Event $event, string $game): bool` (returns new state):
  game trimmed, 1–80 chars. If the user has an active signal for that game (case-insensitive) → delete
  all of them, return false; else create one, return true. Authorize through the existing LFG policy
  (create on the event / delete own post); a user must be allowed to create LFG posts on the event
  exactly as on the LFG page (same registration rules as `CreateLfgPost`).
- Route `POST /events/{event:slug}/want-to-play` → `WantToPlayController@toggle`, name
  `lfg.want-to-play`, `auth`, inside the Lfg `routable` block; validates `game` (required string max 80);
  redirects back (`back()`), no flash toast.
- Tests: grouping and case-insensitivity, expired posts ignored, names exclude viewer and blocked
  users, `mine`, suggestions, toggle on/off, a user cannot remove someone else's signal, guest → login,
  user not allowed to post → 403, draft event → 404.

### Task 4: server summary per game (GameServers module)

**Files:** create `app/Modules/GameServers/Support/ServerGameSummary.php`; tests
`tests/Unit/GameServers/ServerGameSummaryTest.php` or `tests/Feature/GameServers/ServerGameSummaryTest.php`.

- `ServerGameSummary::forEvent(?Event $event): list<array{game: string, servers: int, players: int, maxPlayers: int, join: array|null}>`
  over `ServerListProjection::linksForEvent($event)` (when `$event` not null) plus
  `ServerListProjection::permanentLinks()`, counting only links with status `ready` or `not_responding`.
  Group by the DTO's `game` (links without a game are grouped under their `title`; skip if both null).
  `players`/`maxPlayers` sum the last query (`query.players`/`query.maxPlayers`, 0 when null).
  `join` = the joinable server with free slots and the most players (tie: lowest id), shaped
  `{serverId, title (title ?? matchLabel ?? game), connectString, connectIsLink, address, port, players, maxPlayers}`;
  null when no server of that game has free slots or a connect string.
- `ServerGameSummary::totalPlayers(?Event $event): int` — sum of players over the same set (used by the nav badge).
- Reuse `ServerListProjection::dto()`; do not duplicate its status/game resolution.
- Tests cover grouping, status filter, permanent + event merge, join choice, null event.

### Task 5: located participants per game (Presence module)

**Files:** create `app/Modules/Presence/Support/LocatedGames.php`; tests `tests/Feature/Presence/LocatedGamesTest.php`.

- `LocatedGames::countsFor(Event $event, User $viewer): array<string, int>` — game display name →
  number of event participants (non-cancelled registrations) whose cached location
  (`LocationSnapshotStore::get()`, `people[userId]->game->game`) names that game. Apply exactly the
  privacy rules of `PeopleDirectory::list()`: skip users with `share_location` false and users
  blocked either way with the viewer (Friends module guarded); the viewer counts themselves. Cold
  cache → dispatch `RefreshLocationSnapshotJob` like `PeopleDirectory` and return `[]` if still empty.
- Extract the shared "who may be shown to this viewer" filter from `PeopleDirectory` into one private-free
  helper both classes use (e.g. `PresenceVisibility::visibleParticipants(Event, User): Collection<User>`),
  instead of copying the rules. `PeopleDirectory` behaviour must not change (its tests stay green).
- Tests: counts per game, opted-out user not counted, blocked user not counted, viewer counted, cold cache.

### Task 6: food summary (Catering module)

**Files:** create `app/Modules/Catering/Support/FoodNow.php`; tests `tests/Feature/Catering/FoodNowTest.php`.

- `FoodNow::for(Event $event, User $viewer): array` returns the `FoodNowDto` shape. `open` = the food
  order round of the event that is open now (`FoodOrder::isOpenNow()`) and closes soonest; `title` its
  title, `closesAt` ISO, `orderCount` number of distinct users with items in it, `mine` = a short German
  summary of the viewer's items in that round (e.g. "1× Margherita, 1× Cola") or null, `href` =
  the catering page URL for the event (`route('catering.show', $event)`). `next` = the next round that
  opens in the future (title, opensAt) or null. Read via the existing `ListedFoodOrders` /
  models — check `app/Modules/Catering/Support/ListedFoodOrders.php` and `NativeOrderCards.php` for
  the established queries and reuse them.
- Tests: open round with/without own order, closed/expired rounds ignored, next round, no rounds.

### Task 7: `NowController`, routes, nav signals (integration — after Tasks 3–6)

**Files:** create `app/Http/Controllers/NowController.php`, `app/Support/Now/NavSignals.php`,
`lang/de/now.php`; modify `routes/web.php`, `app/Modules/Events/Http/EventPageController.php`,
`app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/lib/pageLayouts.ts` (only if Task 8
has not added `Now`); delete `app/Http/Controllers/DashboardController.php`; tests
`tests/Feature/Now/NowPageTest.php`, `tests/Feature/Now/NavSignalsTest.php`, adapt existing
dashboard tests and route matrices.

- `GET /` stays `EventPageController@home` (route `home`): guests → `Welcome` (unchanged); logged in →
  delegate to `NowController` (no more `Event/Show` or `archive()` at `/`). `GET /dashboard` (route
  name `dashboard` kept for Fortify) → redirect to `/`.
- `NowController` builds exactly the props contract. It lives in `app/Http` (outside the module
  graph, like the old `DashboardController`) and guards every optional module with
  `Modules::enabled()`:
  `forYou` via `App\Modules\WebVoice\NativeClient\Support\NowFeed::for()` (its sources are already
  null-bound per module), `href` added per card (match → tournament page, vote → poll page, order →
  catering page, schedule → schedule page, notification → null);
  `nowPlaying` = `Inertia::defer(...)` merging `ServerGameSummary::forEvent($event)` (GameServers) with
  `LocatedGames::countsFor()` (Presence; only when an event exists) — `people = max(located, players)`;
  games only located (no server) get `servers 0`, `join null`;
  `wantToPlay` = `Inertia::defer(...)` `WantToPlayBoard::for()` + `toggleUrl`;
  `food` = `FoodNow::for()`; `schedule` from `ScheduleProjection` (`currentItem`, `itemsFor` → next 3 after now);
  `me` from `MyRegistration::summary`, `MySeat::for`, `MyTeams::for`; `news` from `NewsQuery::published(3)`;
  `permanent` from `ServerListProjection::forPermanent()` (first 4) for `upcoming`/`none`.
- `NavSignals::for(User $user): array` — shared Inertia prop `navSignals` (lazy closure, logged-in only,
  cached per user for 30 s): `{ serversOnline: int|null, tournament: 'ready'|'checkin'|null, foodClosesAt: string|null, seat: string|null, present: int|null }`
  (`serversOnline` = `ServerGameSummary::totalPlayers`; `tournament` from NowFeed match cards: any
  card with state `ready`/`live`/`confirm_result` → 'ready', else `checkin_open` → 'checkin';
  `foodClosesAt` = open round's `closesAt` only while the viewer has not ordered; `seat` = own seat
  label; `present` = checked-in count of the current event). Null fields when the module is off or no event.
  Add its TypeScript type to the shared page props type.
- Tests: three states, module gating (`MODULES_PROFILE=core` → optional props null), deferred props
  load on partial reload, `/dashboard` redirect, guest still gets `Welcome`, nav signal values + cache.

### Task 8: `Now` page frontend (parallel, against the contract)

**Files:** create `resources/js/pages/Now.vue`, `resources/js/types/now.ts`, components in
`resources/js/components/now/`: `Panel.vue`, `ForYouPanel.vue`, `NowPlayingPanel.vue`,
`WantToPlayPanel.vue`, `FoodPanel.vue`, `NextPanel.vue`, `MeStrip.vue`, `PermanentShortPanel.vue`,
`NewsPanel.vue`, `CountdownLine.vue`; modify `resources/js/lib/pageLayouts.ts` (`Now: 'app'`);
delete `resources/js/pages/Dashboard.vue` (and its now-unused components only if nothing else imports
them); modify `resources/js/pages/Event/Show.vue` (slim "LAN-Info": remove the module button row,
h1 `text-3xl`, status hint a single line; keep when/where, arrival info, hype, news).

Build exactly the approved prototype (`Main`, `PreLan`, `NoLan`, `Mobile` artboards):
- `PageContainer width="wide"`; header row: event name as h1 (`text-2xl font-semibold`), then
  `LiveIndicator` "LIVE" + mono "Tag N · HH:MM · X von Y da" for live; for upcoming a `CountdownLine`
  (days/hours/minutes in mono, updates every 30 s, `role="timer"` with an aria-label); for none the
  heading "Gerade läuft keine LAN" + one explanatory sentence.
- `Panel.vue`: `section.rounded-md.border.bg-card` with a header row (`h2 text-sm font-medium
  text-muted-foreground` + optional right link) and default slot; all panels use it.
- Live layout: `ForYouPanel` full width (one row per card: status dot, bold title + muted body,
  mono time, actions; the first `match` card with state `ready` gets the only amber button
  "Verbinden" when `data.match.server.connectString` exists, every other action is outline and
  links to `href`; hidden entirely when `cards` is empty); then a two-column flex-wrap row
  `NowPlayingPanel` (flex 1.4) + `WantToPlayPanel` (flex 1); then `FoodPanel` + `NextPanel`; then `MeStrip` (dashed border, one line).
- `NowPlayingPanel`: one row per game — name, a 12-segment meter (`span` segments 5×16 px,
  lit `bg-foreground/80`, unlit `bg-border`, `role="img"` with aria-label "N Leute spielen X"), mono
  count, muted "where" (`servers` Server · `players/maxPlayers` Plätze, or "ohne eigenen Server"),
  "Beitreten" outline button (connect link or copy) when `join`; header link "Alle Server" → `/servers`;
  footnote "Gezählt werden Leute, die ihren Standort teilen, und alle auf unseren Servern."; skeleton
  rows while the deferred prop loads (`<Deferred>`), empty sentence when `[]`.
- `WantToPlayPanel`: chips (`rounded-full border`, selected = `bg-foreground text-background` with a
  check icon, mono count), `aria-pressed`; click → `router.post(toggleUrl, {game}, {preserveScroll: true, only: ['wantToPlay','navSignals']})`
  with an optimistic toggle; "+ Spiel" opens an inline list of `suggestions` plus a free-text input;
  below: for each `mine` game one line "Game · A, B, C und du" (or "bisher nur du") and, when a
  `nowPlaying` row of that game has `join`, its server + "Beitreten"; empty hint "Tipp ein Spiel an,
  dann sehen die anderen, dass du Lust hast. Nach drei Stunden verschwindet es von selbst."
- `FoodPanel`, `NextPanel`, `MeStrip`, `NewsPanel`, `PermanentShortPanel` as in the prototype.
- Upcoming layout (PreLan artboard): header with countdown, `ForYouPanel` (title "Für dich vor der LAN"),
  `WantToPlayPanel` + a "Wer kommt" panel (registeredCount of capacity), `NewsPanel` + `PermanentShortPanel` ("Bis dahin offen").
- None layout (NoLan artboard): `PermanentShortPanel` ("Dauerserver") + a "Als Nächstes" link to `/events`, `NewsPanel`.
- Live updates: `useEventChannel` on the event channel → partial reload `['nowPlaying','forYou']` on
  `presence.updated`; plus a 30 s visible-tab partial reload of `['forYou','nowPlaying','food','schedule']`.
- All copy from `labels` (`trans('now')`); the frontend agent writes `lang/de/now.php` with every key
  it uses (Task 7 only adds keys it needs for server-side strings).
- Feature test is Task 7's; this task's gate is the four frontend checks plus
  `./vendor/bin/pest tests/Architecture`.

## PR C — slim navigation with live badges

### Task 9: slim sidebar with collapsible groups and live badges (after PR A and PR B are merged)

**Files:** modify `resources/js/components/AppSidebar.vue`, `resources/js/components/NavMain.vue`,
`resources/js/types/navigation.ts`; create `resources/js/components/NavCollapsibleGroup.vue`;
modify `docs/design.md` § "4. Navigation" (and the "Desktop app entry points" mention of the
Dashboard hint if it moved); tests: extend `tests/Feature/ShellParity/ShellParityTest.php` only if a
contract row changes (it must not: logo, user menu, "Desktop-App" label stay).

Target (prototype `Main` artboard sidebar):
```
[logo] LANoMAT                       → '/'
<event name> (group label, LiveIndicator dot when status live; no label without current event)
  Jetzt                              → '/'                 (exact match)
  Server        <serversOnline> online → servers.index     (has('game_servers'))
  Turniere      ● Match bereit | Check-in offen → tournaments.index(slug)   (event)
  Essen         bis HH:MM (text-warn) → catering.show(slug)   (event, has('catering'))
  Zeitplan                           → schedule.index(slug) (event, has('schedule'))
  Sitzplan      <seat>               → events.seating(slug) (event)
  Leute         <present> da         → presence.show(slug)  (event, has('presence'))
  Anmeldung                          → events.register(slug) (event, availability === 'open' and no own registration — use `currentEvent` + registration info if available in shared props; otherwise availability only)
▸ Mehr: Abstimmungen (voting, prefix), Mitspieler finden (lfg), Jukebox (jukebox), Galerie (gallery),
        Dateien (files), LAN-Info & Anreise (events.show(slug)), Spiele ('/games', game_recipes),
        Teams (prefix), Freunde (friends), Bestenliste (stats), Alle Events (events.index)
▸ Voice: Sprachchat (web_voice), Voice einrichten (voice), Desktop-App (web_voice && desktopClient; keeps the indicator dot)
▸ Orga (is_staff, event): Check-in, Infoscreen-Steuerung (infoscreen)
[NavUser]
```
- Without a current event the first group has no label and contains only Jetzt and Server.
- `NavItem` gains `badge?: { text: string; variant?: 'live' | 'ok' | 'warn' | 'muted' }`.
  `NavMain` renders it right-aligned in `font-mono text-xs` (`muted` → `text-muted-foreground`,
  `warn` → `text-warn`, `ok` → `text-ok`, `live` → `LiveIndicator` with the text as label), hidden
  in icon-collapsed mode (`group-data-[collapsible=icon]:hidden`). `label` becomes optional.
- Badge values come from the shared prop `navSignals` (Task 7: `serversOnline`, `tournament`,
  `foodClosesAt` (format HH:MM in Europe/Berlin via the existing date helpers), `seat`, `present`);
  a null field → no badge. "Server" shows a badge only when `serversOnline > 0`.
- `NavCollapsibleGroup.vue` (props: `label`, `icon`, `items`, `storageKey`): shadcn `Collapsible`
  inside a `SidebarGroup`; trigger is a `SidebarMenuButton` with the icon, label and a chevron
  rotating 90° when open; content = `SidebarMenuSub` with `SidebarMenuSubButton` links (active state
  like `NavMain`). Open state: open by default when one of its items is active; otherwise the last
  state from `localStorage` key `lanomat.nav.<storageKey>` (wrapped in try/catch; default closed).
  In icon-collapsed sidebar mode a click on the trigger first expands the sidebar (`useSidebar().setOpen(true)`) and opens the group.
- Groups with zero visible items are not rendered.
- "Desktop-App" stays in `AppSidebar.vue` (Shell parity test reads it).
- Gates: four frontend checks + `./vendor/bin/pest tests/Architecture tests/Feature/ShellParity`.

## PR D — density pass (tasks added when PR C is merged)
