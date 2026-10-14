# Module toggles (`config/modules.php`) — design

**Status:** draft for review · **Date:** 2026-10-14 · **Path:** architectural

## Problem

All 29 modules under `app/Modules` are always active: every route, Filament
resource, scheduler entry, listener and Preflight probe is registered
unconditionally. A fresh install therefore needs ~116 env variables and 13+
external integrations before it is "green", and a LAN that only needs
registration, seating and tournaments still carries the attack surface and
operational weight of Jukebox, Gallery, LanCache, remote hosts and so on.

## Goal

A fresh install boots and passes Preflight with only the **core** modules
(Events, Registration, Seating, Teams, Games, Tournaments, Identity,
Notifications, Preflight). Every other module can be switched on per
deployment. Existing deployments keep today's behaviour by opting into the
`full` profile.

**Success criteria**
- `MODULES_PROFILE=core` (default): app boots, core flows work, every optional module's routes return 404, its Filament resources/nav/scheduler entries/listeners/Preflight probes are absent, and `lanomat:preflight` has no red caused by a disabled module.
- `MODULES_PROFILE=full`: behaviour identical to today; the existing suite passes unchanged.
- Individual overrides (`MODULE_JUKEBOX=true`) work in both profiles.
- Enabling a module without its declared dependencies fails fast at boot with a clear message.

**Non-goals**
- Runtime/admin-UI toggling (Pennant); toggles are deploy-time config.
- Per-module ServiceProviders / splitting `AppServiceProvider` — possible later, module by module; this design must not block it.
- Removing code, tables or migrations of disabled modules.
- Resolving the 32 mutual module cycles (separate refactor, tracked by `tests/Architecture`).

## Core idea

A toggle gates **entry points only**, never the data layer. Migrations always
run, models and classes stay autoloadable, tables exist. A disabled module
therefore cannot cause class-not-found or missing-table errors in an enabled
module; the only risk is an enabled module *invoking behaviour* of a disabled
one, which §5 handles explicitly.

## 1. Configuration

- Backed enum `App\Support\Modules\Module` with one case per module (29), string values = lower-case module key (`jukebox`).
- `config/modules.php` carries only `profile` and `overrides`; the static metadata (`core`, `requires`) lives in the `Module` enum (amended during implementation).
- Resolution order: `MODULE_<KEY>` env override → `MODULES_PROFILE` (`core` default, `full` = everything) → module's `core` flag. Core modules cannot be disabled (override to false is a boot error).
- Class `App\Support\Modules\Modules` (bound as singleton, facade-free): `enabled(Module): bool`, `all(): list<Module>`, `enabledModules(): list<Module>`. Validates `requires` at construction and throws `InvalidModuleConfiguration` listing every violation.

Declared dependencies (as implemented in `app/Support/Modules/Module.php`, derived from runtime use):

| Module | requires |
|---|---|
| CustomServers, Lancache | Hosts |
| Recap | Gallery, Jukebox, Voting, Presence |
| News | Gallery |
| Gallery | Files |
| Stats | Voting |
| Friends | Presence |
| all others (GameServers, Infoscreen, Voice, Discord, Schedule, Catering, Voting, Lfg, Presence, Files, Jukebox, Casting, Hosts) | none (Infoscreen and GameServers read others defensively, see §5) |

The isolation matrix (`tests/Feature/Modules/IsolationMatrixTest.php`) verified that every
optional module boots alone with exactly this closure; it needed no further entries.

**Amendments made during implementation (spec and code agree):**

1. Container **bindings stay unconditional** (they are lazy, and Identity resolves `VoiceProviders` regardless). Only listeners, policies/gates, scheduler, probes, routes and Filament are gated.
2. Artisan **commands stay registered** (`withCommands` runs before config exists); only their *scheduler entries* are gated. Running a disabled module's command by hand is harmless.
3. **Static metadata lives in the enum**, not in the config (see above).
4. **Broadcast channels needed no gating**: channel definitions are inert without the module's events/routes.
5. **Wayfinder `routable()` rule**: the frontend imports generated route helpers for every module, and Wayfinder generates from *registered* routes (dev Vite plugin, CI, Docker build). `Modules::routable()` therefore returns true for every module while `wayfinder:generate` runs; generation is never gated, only serving.
6. **`DiscordChannel` central guard**: the Discord notification channel is a no-op when Discord is off, so every notification (core or optional) can keep listing it in `via()`.
7. An empty `MODULE_<KEY>=` counts as unset, not as an explicit disable.

## 2. Gated entry points

| Entry point | Today | Change |
|---|---|---|
| Routes (`routes/web.php`, 95 routes) | one file | grouped per module behind `Modules::enabled()`; layout in this order: core, then optional modules alphabetically |
| Broadcast channels (`routes/channels.php`) | unconditional | per-module guard |
| Filament resources | 17 hand-written `discoverResources` calls in `AdminPanelProvider` | one loop over enabled modules (+ their pages/widgets); `Preflight` widget stays |
| Scheduler (`routes/console.php`) | 10 entries | each optional entry wrapped by its module flag |
| Console commands (`bootstrap/app.php` `withCommands`) | fixed list | built from enabled modules |
| Listeners, policies, bindings (`AppServiceProvider`, 333 lines) | unconditional | registered inside `if (modules->enabled(...))` blocks per module; grouped by module so the later move to per-module providers is mechanical |
| Preflight probes (`preflight.checks` tag) | all 13 | a probe is tagged only if its owning module is enabled; DB/Redis/Reverb/Queue/Scheduler/Storage/FailedJobs stay core |

Disabled-module behaviour for HTTP: route not registered → 404 (not 403), so a
disabled module is indistinguishable from a non-existent one.

## 3. Frontend

- `HandleInertiaRequests` shares `modules: string[]` (enabled keys).
- `AppSidebar`, `PublicShell`, `Event/Show` (tabs/cards for schedule, catering, polls, LFG, servers, jukebox, gallery, news) and the Beamer/overlay entry links render only for enabled modules, via one composable `useModules().has('jukebox')`.
- Pages of disabled modules stay in the bundle (no build-time pruning); they are simply unreachable.
- Follows `docs/design.md`: hiding a module removes its nav entry without leaving empty states; no new visual elements.

## 4. Discord, specifically

`Discord` is optional. Login via Socialite stays always on (it lives in
`AppServiceProvider`/Fortify, not in the module). With Discord disabled:
announcements, reminders, DM channel, per-match channels, slash commands, the
Gateway ingress/interactions routes and the outbox sweep are all off, and the
Notifications module delivers in-app (bell) only. The `discord-gateway` compose
service is unaffected (it just has nothing to talk to); the three-server
checklist notes it as skippable.

## 5. Enabled module → disabled module (the real risk)

The architecture baseline shows these core→optional references that execute at
runtime. Each gets an explicit guard; none may throw when its target is off.

| Caller (core) | Calls (optional) | Guard |
|---|---|---|
| `Events\EventPageController` | News, Voting queries | skip props when module off |
| `Tournaments\TournamentPageController`, `BracketMatchProjection`, `GameMatch` | Voice join links/occupancy, GameServers `ServerLink`/join info | omit those props/fields; projection returns them empty |
| `Tournaments\MatchReadyBell`, `Registration\CheckinOpened` | `DiscordChannel`, `MatchEmbed` | `via()` drops the Discord channel when off |
| `Registration\CheckinOpened`, `Seating\SeatProjection`, `Tournaments` | Infoscreen actions/`ScenePayload` | skip trigger / keep payload builder usable (pure) |
| `Registration\CheckInRegistration`, `EventRegistration` | Presence event/projection | event dispatch is harmless without listeners; projection call guarded |
| `Tournaments\TournamentScheduleParticipantResolver` | Schedule | binding only registered when Schedule on (already contract-based) |
| `Identity\ProfileController`, `LinkedAccountConnectors` | Friends, Voice | friend data/voice providers omitted when off |

Rule for new code: an enabled module reaches an optional module only through
(a) a Laravel event, (b) a contract with a null implementation, or (c) a
`Modules::enabled()` guard. The architecture test is extended with a second
ratchet: **core → optional edges** may not grow beyond this table.

## 6. Testing

- `phpunit.xml` pins `MODULES_PROFILE=full`: the existing ~1586 tests are unchanged.
- New `tests/Feature/Modules/`:
  - `ModulesConfigTest`: profile resolution, env overrides, core-cannot-be-disabled, `requires` violations list every problem.
  - `CoreProfileTest` (runs with `core`): optional routes → 404 (data-driven over route names), Filament resource registry contains only core resources, scheduler has no optional entries, Preflight probes exclude optional ones, Inertia `modules` prop exact.
  - One test per §5 guard: page/projection/notification works with the target module off.
- Architecture ratchet #2 (core → optional) as described above.
- CI adds a short smoke job: `MODULES_PROFILE=core` boot + `lanomat:preflight` + the `CoreProfileTest` file.

## 7. Migration & docs

- `.env.example`: `MODULES_PROFILE=core` documented, with the per-module override list; a comment states that existing installs must set `MODULES_PROFILE=full` to keep today's behaviour.
- README `## Project state`, CLAUDE.md (Architecture rules: "toggle gate, §5 rule") and `docs/architecture.md` updated in the finalize step; `docs/three-server-setup.md` notes which services are skippable per profile.
- The local dev `.env` of existing checkouts gets `MODULES_PROFILE=full` in the same change.

## 8. Rollout order (becomes the implementation plan's task list)

1. Enum + config + `Modules` service + validation + config tests.
2. Inertia prop + `useModules` composable (no behaviour change yet).
3. Filament discovery loop.
4. Routes + channels split per module.
5. `AppServiceProvider` registration grouped and gated per module.
6. Scheduler, commands, Preflight probe gating.
7. §5 guards one by one, each with its test.
8. Frontend nav/tab gating.
9. Core-profile tests, ratchet #2, CI smoke job, docs.

Each step keeps `MODULES_PROFILE=full` green; steps 3–7 are independently
mergeable PRs.

## Open risks

- **Hidden core→optional runtime paths** not visible as `use` imports (container-resolved classes, string morph types). Mitigation: `CoreProfileTest` exercises every core page, plus the core-profile CI smoke job.
- **Config caching:** `config/modules.php` must read env only via `config()` at boot; covered by a test running with `config:cache`.
- **Default flip:** the `core` default changes behaviour for anyone who pulls without reading the docs; mitigated by the documented `full` opt-in and a Preflight note when profile is `core`.
