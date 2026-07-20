# Architecture overview

LANoMAT v2 is a modular monolith on Laravel 13. This document is a concise map of the
codebase conventions; the full rationale and phase plan live in:

- [`docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md`](superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md) — approved architecture & module design
- [`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`](superpowers/plans/2026-07-14-lanomat-v2-roadmap.md) — master roadmap, phases M0–M6

## Module convention

Each domain lives under `app/Modules/<Name>/` with the same internal shape:

```
app/Modules/<Name>/
├── Models/        # Eloquent models owned by this module
├── Actions/        # one class per use case (e.g. RegisterForEvent)
├── Policies/        # authorization — every action that touches a model goes through one
├── Filament/        # admin Resources/Pages/Widgets for this module
├── Jobs/            # queued work (Discord/Mumble/Pelican side effects, notifications)
├── Events/          # domain events other modules can listen to
└── Contracts/       # interfaces for external systems this module talks to
```

Tests mirror this structure under `tests/{Feature,Unit}/<Name>/`. Modules communicate only
via Laravel events and explicit service interfaces (contracts) — never by reaching into
another module's tables directly.

External systems (Discord, Mumble, Pelican) are accessed exclusively through their
contracts (`DiscordClient`, `MumbleClient`, `PelicanClient`), with fake implementations
bound in tests. Tests never call real third-party APIs.

### Module list (design doc §6)

| Module | Phase | Responsibility |
|---|---|---|
| Identity | M0–M1 | Discord OAuth login, profile, roles (`admin`/`orga`/`participant`) |
| Events | M1 | Event CRUD, lifecycle, "current event" context |
| Registration | M2 | Sign-up, ticket tiers, manual payment status, QR check-in |
| Seating | M2 | Seating chart editor, seat selection, network metadata per seat |
| Teams | M3 | Cross-event teams, rosters, join requests |
| Tournaments | M3 | Bracket engine, check-in, match reporting/disputes, live bracket UI |
| Schedule | M4 | Event timeline, manual slots, ICS export |
| Catering | M4 | Group food orders and cost splitting |
| Voting | M4 | Game votes and generic polls per event |
| LFG | M4 | Looking-for-group posts per event |
| Discord | M2–M3 (cross-cutting) | REST client, Interactions endpoint, channel orchestration |
| Voice (Mumble) | M3 (cross-cutting) | Mumble channel orchestration via Ice REST sidecar |
| Infoscreen | M5 | Fullscreen rotating scenes for the venue projector |
| GameServers | M6 | Pelican Panel integration, match server provisioning |
| Notifications | M2 (cross-cutting) | `database` + `discord` notification channels |
| Stats | M6 | Cross-event leaderboards and badges |
| Friends | Friends-System | Cross-user friend requests/blocking, LAN-native suggestions |

The `Tournaments` module additionally has `app/Modules/Tournaments/Domain/` — the bracket
engine (`BracketGenerator`, `BracketProgressor`). It is pure domain code with no IO, and is
exhaustively tested with Pest before any UI work is built on top of it.

## Data model: Event as aggregate root

A LAN party edition (`Event`) is the aggregate root for everything organizational. Its
lifecycle is `draft → announced → registration → live → finished → archived`. Identity
(`User`, `Team`, `Game`) is cross-event, so history/statistics/leaderboards across multiple
events fall out of the model for free.

```
users                (id, discord_id UNIQUE, name, avatar_url, bio, steam_url, profile_color, role, ...)
events               (id, name, slug, status, location, starts_at, ends_at, max_participants, settings JSONB)
event_registrations  (id, event_id, user_id, ticket_type, status, paid_at, checked_in_at, qr_token UNIQUE)
seats                (id, event_id, label, pos_x, pos_y, meta JSONB)
seat_assignments     (id, seat_id UNIQUE, registration_id UNIQUE)
games                (id, name, slug, icon_path, min/max_team_size, pelican_egg_id NULLABLE, default_server_config JSONB)
teams                (id, name, tag, logo_path, owner_id)
team_members         (id, team_id, user_id, role, UNIQUE(team_id,user_id))
team_join_requests   (id, team_id, user_id, status, message)
tournaments          (id, event_id, game_id, name, format, status, team_size, max_entries, rules, ...)
tournament_entries   (id, tournament_id, team_id NULLABLE, user_id NULLABLE, display_name, seed, roster_snapshot JSONB, status)
matches              (id, tournament_id, round, bracket, position, entry1_id, entry2_id, score1, score2,
                      winner_entry_id, status, lock_version, next_match_id, next_slot,
                      loser_match_id, loser_slot, discord_channels JSONB, voice_channels JSONB, server_link_id NULLABLE)
match_reports        (id, match_id, reported_by, score1, score2, status[pending|confirmed|disputed], ...)
schedule_items       (id, event_id, title, type, starts_at, ends_at, ref_type/ref_id NULLABLE)
food_orders          (id, event_id, title, vendor, menu JSONB, opens_at, closes_at, status)
food_order_items     (id, food_order_id, user_id, selection JSONB, price_cents, paid_at)
polls                (id, event_id, question, type, opens_at, closes_at) + poll_options + poll_votes(UNIQUE(poll_id,user_id))
lfg_posts            (id, event_id, user_id, game_id, title, description, players_needed, skill_level, expires_at, status)
infoscreen_scenes    (id, event_id, type, config JSONB, duration_sec, sort, enabled)
server_links         (id, match_id NULLABLE, tournament_id NULLABLE, pelican_server_id, join_info JSONB, status)
notifications        (Laravel standard) · discord_outbox (id, kind, dedup_key UNIQUE, sent_at)
```

Key decisions (see design doc §7 for full rationale):

- `tournament_entries` unifies solo and team participation; `matches` only ever references entries.
- `roster_snapshot` freezes a team's lineup at tournament start, so later roster changes don't rewrite history.
- `match_reports` carries the report/confirm/dispute flow; `matches` holds the confirmed state.
- `discord_outbox.dedup_key` gives idempotent announcement delivery (replaces in-memory dedup from v1).
- File uploads (icons, logos) go to Laravel Storage (`*_path` columns), never Base64 in the database.

## Identity & account linking

Login stays **Discord-only**. `users.discord_id` is a unique-nullable column and remains
the sole authentication anchor and the routing key for Discord DMs and Interactions — it is
never touched by anything in this section.

A separate `linked_accounts` table holds *secondary* platform accounts a user optionally
connects on top of their Discord login — currently Steam and Twitch, with `battlenet`,
`epic`, and `gog` reserved as `LinkedAccountProvider` enum members for later (`GOG` has no
public OAuth flow, so it would need a manual-link path rather than the connector flow below).
Discord is deliberately **not** a row in this table.

```
linked_accounts  (id, user_id FK, provider, provider_user_id, nickname,
                  access_token NULLABLE encrypted, refresh_token NULLABLE encrypted,
                  token_expires_at, scopes JSONB, meta JSONB,
                  UNIQUE(provider, provider_user_id), UNIQUE(user_id, provider))
```

`access_token`/`refresh_token` use Eloquent's `encrypted` cast, are never `$fillable` (set
only via `forceFill()` inside the actions that own the OAuth exchange), and are never
serialized to the frontend. `UNIQUE(provider, provider_user_id)` guarantees one external
account maps to at most one LANoMAT user; `UNIQUE(user_id, provider)` caps a user to one
linked account per provider.

Each provider is accessed only through the `LinkedAccountConnector` contract
(`redirectUrl()`, `resolveCallback()`, `refresh()`, `ownsApp()`), resolved per-provider via
the `LinkedAccountConnectors` registry rather than a `match` over a fixed set — this lets
providers be added incrementally without touching the registry. Steam links via OpenID
(identity only — `hasTokenLifecycle()` is `false`, so it never issues or refreshes a token).
Twitch links via OAuth2 with an encrypted refresh token; an hourly `RefreshExpiringTokensJob`
sweeps accounts whose `token_expires_at` is approaching and refreshes them, and a refresh
failure flags the account `needs_reauth` in `meta` and sends the owner a
`LinkedAccountReauthRequired` notification rather than failing silently. Tests exercise this
entirely through `FakeLinkedAccountConnector` / the `fakeLinkedAccounts()` helper — never a
real Steam/Twitch API call.

Two consumers build on top of the linked accounts:

- **`DisplayNameResolver`** — in a given provider's context (e.g. a Steam-specific view),
  shows that provider's linked nickname; otherwise falls back to the LANoMAT name. Pure and
  IO-free, reading only the already-loaded relation.
- **`GameOwnershipHint`** (paired with `games.provider`/`games.provider_app_id`) — an
  **advisory-only** signal computed from a linked account's provider-reported ownership. This
  is a **binding invariant, not an implementation detail**: it must never gate tournament
  enrollment, regardless of whether it resolves to owned, not-owned, or unknown (no provider
  mapping on the game, no linked account, private profile, or a failed provider call all
  collapse to `Unknown`). Enrollment must always succeed; the hint may only render as a
  calm, non-blocking warning. This is pinned by
  `tests/Feature/Identity/OwnershipHintNeverBlocksTest.php`.

`users.id` — never `discord_id` — is the sole foreign-key and merge anchor across the
schema. This matters beyond M9: a future community/user-fusion feature is expected to
repoint foreign keys from a "loser" user onto a surviving user, turning the loser into a
tombstone that reads follow. That merge is **not built** — there is no
`merged_into_user_id` column and no merge logic (YAGNI until a real fusion lands) — but the
guardrail is fixed now: keep `users.id` the sole anchor, keep FKs and history merge-capable,
and never let `discord_id` become the sole identity anchor anywhere in the schema.

## Friends & social

`Friends` is a cross-user module (`app/Modules/Friends/`), cross-event like `Teams` — it
lives outside the `Event` aggregate entirely. `users.id` is its sole anchor, matching the
identity guardrail above.

```
friendships   (id, requester_id, addressee_id, status[pending|accepted], UNIQUE(requester_id,addressee_id))
user_blocks   (id, blocker_id, blocked_id, UNIQUE(blocker_id,blocked_id))
```

- **Mutual friendship via request → accept.** `friendships` stores one directed row per
  ordered pair (`requester_id` sent the request to `addressee_id`); `accepted` means the two
  users are symmetric friends, queried in either direction via `Friendship::scopeBetweenUsers()`.
  If `A` requests `B` while a reverse pending row (`B` → `A`) already exists,
  `SendFriendRequest` auto-accepts that existing row instead of creating a duplicate, so two
  simultaneous requests collapse into one accepted friendship rather than a dangling pair.
- **Blocking supersedes friendship.** `user_blocks` is a separate directed table. A block
  prevents new friend requests in either direction (`FriendService::blockedEitherWay()`,
  checked before every `SendFriendRequest`) and, on creation, `BlockUser` transactionally
  deletes any existing `friendships` row between the two users (accepted or pending, either
  direction) — a block always wins over a prior friendship.
- **Actions + policy.** `SendFriendRequest`, `RespondToFriendRequest`, `CancelFriendRequest`,
  `RemoveFriend`, `BlockUser`, `UnblockUser` are the module's use cases. Every state
  transition is authorized through `FriendshipPolicy` via `Gate::forUser($actor)->authorize(...)`
  called *inside* the action (not just the controller) so the check cannot be bypassed by a
  caller that skips the HTTP layer; the acting user is always `auth()->user()`, never a
  client-supplied id.
- **LAN-native suggestions (`FriendSuggestions`).** A pure, IO-light read-model that ranks
  candidates by shared LAN context, reading each fact through its owning module's own Eloquent
  model (`EventRegistration`, `TeamMember`, `TournamentEntry` via `EntryRoster`) rather than a
  raw cross-module query, mirroring the `PresenceProjection` precedent. Candidates are excluded
  if they are the user themself, an existing friend, have a pending request in either
  direction, or are blocked either way; the remainder is ranked by the count of distinct shared
  events/teams/tournaments.
- **Steam-friend suggestion source (`shared_steam_friend`).** A fourth, cross-event source
  layered onto the three LAN-native ones above: `LinkedAccountConnector::friendProviderIds()`
  is a best-effort call to Steam's `GetFriendList` Web API endpoint — a private friend list
  (HTTP 401) or any other failure (missing key, network error, malformed response) resolves to
  `[]` rather than throwing, since this sits behind an advisory-only suggestion and must never
  be able to propagate an exception into a caller that expects it to be safe to call
  unconditionally. The returned SteamID64s are cached for ~15 minutes per (user, SteamID) pair
  — `FriendSuggestions` is the only caller, never Steam's API directly — and intersected live
  (never cached) against LANoMAT users who also linked a Steam account, then merged into the
  suggestion index under the `shared_steam_friend` reason, with the same self/friend/pending/
  blocked exclusions as the LAN-native sources. External provider friend-lists other than Steam
  remain out of scope.
- **`EntryRoster` N+1 fixed.** The previously-deferred per-entry `User` query fan-out is gone:
  `EntryRoster::userIdsFor()` extracts a `TournamentEntry`'s roster user ids without issuing any
  query at all (used by `FriendSuggestions`, which only needs ids), and
  `EntryRoster::usersForEntries()` resolves a whole `Collection` of entries' rosters in a single
  batched `User` query (used by `usersForMatch()`/`usersForTournament()`, and in turn by
  `PresenceProjection`). Behavior is unchanged in both the Friends suggestions read-model and
  the M10 presence projection — only the query count dropped.
- **Notifications are bell-only.** `FriendRequestReceived` and `FriendRequestAccepted` are
  `database`-channel only (no Discord mirror) — a friend request is a low-urgency, in-app
  signal, deliberately not given the dual-channel treatment reserved for time-sensitive
  events like check-in opening.
- **Presence "friends only" filter — a per-viewer decoration, never a public fact.**
  `ParticipantPresence` (the `PresenceProjection` DTO) gained a `userId` field purely so an
  authorized layer can match participants against a viewer's friend list; the projection
  itself stays viewer-agnostic and never computes `isFriend`. `PresencePageController` is the
  **only** place `isFriend` is added, decorating each participant in the Inertia payload for
  the current request's viewer only (a guest sees `isFriend` false for everyone). This is a
  **binding invariant**: `isFriend` must never appear in `PresenceUpdated::broadcastWith()`
  (which stays `[]`, exactly as before Friends existed) or in the beamer infoscreen scene
  (which never receives the participant roster at all) — both remain public-channel-safe with
  zero per-viewer data leakage.

## Real-time

Reverb channels are scoped per context: `event.{id}` (infoscreen control, announcements),
`tournament.{id}` (bracket/match updates). Real-time is an enhancement, not a dependency —
every page must remain correct after a plain reload.

## Current implementation status

M0–M2 are complete:

- **M0:** Laravel 13 + Filament v5 + Inertia/Vue scaffold, Docker dev stack, Discord OAuth
  login, role middleware, admin panel gating, and CI.
- **M1:** `Events` module — `Event` aggregate with lifecycle transitions, public event page,
  profile editing.
- **M2:** `Registration` module — sign-up with QR-code tickets, manual paid status, orga
  QR check-in. `Seating` module — seat/seat-assignment models, participant SVG seating
  chart with claim/switch, and a standalone Filament `SeatResource` (grid bulk-creation,
  per-seat network metadata) rather than an Event tab — see the roadmap's M2 insights for
  the reasoning. `Notifications` module — `database` channel, bell dropdown, per-category
  preferences. `Discord` module — `DiscordClient` contract with `HttpDiscordClient`
  (`Http::fake()`-only in tests) and `FakeDiscordClient`, a per-user DM notification
  channel, and outbox-deduplicated event announcements/reminders driven by the
  `lanomat:send-reminders` scheduler command.

`Identity`, `Events`, `Registration`, `Seating`, `Notifications`, and `Discord` now exist
under `app/Modules/`; the remaining modules above are built out phase by phase per the
roadmap (M3 onward).
