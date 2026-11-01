# Room audio (Denon AVR) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The orga steers the room's Denon AV receiver (power, volume with ceiling, mute, input, sound mode) from the LANoMAT admin panel through a venue sidecar.

**Architecture:** New optional module `RoomAudio` (profile `full`, off in `core`). A FastAPI sidecar `docker/denon-admin/` keeps one telnet session to the receiver; Laravel talks HTTP + bearer token to it through a `ReceiverClient` contract (`HttpReceiverClient` / `FakeReceiverClient`). One Action per command, all behind one orga gate; a standalone Filament page renders the state.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5 (Livewire), Pest 4, Python 3.13 + FastAPI (like `docker/teamspeak-admin`).

**Spec:** `docs/superpowers/specs/2026-11-01-room-audio-denon-design.md`. Protocol reference: the owner's Omarchy plugin at `~/.config/omarchy/plugins/kleintonno.denon/scripts/denon_bridge.py` (read it; its state shape and command whitelist are the model, it is not part of the repo and must not be copied with its IP).

## Global Constraints

- Repo is PUBLIC: no real IPs/hostnames/secrets in files, commits, tests, docs. Use `<RECEIVER_IP>` / `203.0.113.x` / `example` placeholders; the receiver IP only in the sidecar env (`DENON_HOST`).
- Code, comments, commits, docs in English; UI copy German in `lang/de/room_audio.php`.
- Optional module rules: `Module::RoomAudio` enum case, gated in providers/Filament/Preflight; no other module imports `App\Modules\RoomAudio` (architecture ratchet `tests/Architecture`, regenerate baselines only if the ratchet requires it).
- External system only through the contract; tests never open a socket (`Http::fake` / `FakeReceiverClient`).
- Authorization only via the gate `room-audio.control` (`$user->isOrga()`); never trust client-supplied values: clamp volume to `0..min(98, ceiling)` in the Action.
- Volume is 0..98 in 0.5 steps; the sidecar waits >= 0.08 s between telnet commands, accepts only whitelisted commands, sanitises receiver text (printable, no `<>&"'` + backtick, max 32 chars).
- Gates per task: `composer check` (pint --test, phpstan 8, pest); sidecar tests via `docker run --rm -v "$PWD/docker/denon-admin:/app" -w /app python:3.13-slim sh -c "pip install -q -r requirements.txt pytest && pytest -q"`; `bash scripts/ops/check-public-safe.sh`. Frontend gates not needed (no JS/TS change).

## Review Focus

- Receiver unreachable / telnet drops mid-command: state `connected:false`, Filament page renders a calm "nicht erreichbar" state, no 500, Actions raise a typed `ReceiverUnavailable`.
- Volume above the ceiling or above 98, negative, non-numeric, NaN: clamped/rejected, never forwarded raw.
- Receiver in standby: volume/mute/input commands while off — the page disables them and shows "Receiver ist aus"; power on works.
- Hostile receiver text (names with markup/long/emoji) never reaches the page unsanitised.
- A non-orga (helper, participant, guest) opening the page or invoking an action: 403/not visible.

---

### Task 1: Denon sidecar

**Files:**
- Create: `docker/denon-admin/app.py`, `docker/denon-admin/requirements.txt`, `docker/denon-admin/Dockerfile`, `docker/denon-admin/README.md`, `docker/denon-admin/test_app.py`
- Modify: `compose.yml` (service `denon-admin`, `profiles: [prod]`, port bound to `127.0.0.1:8087:8000`, env `DENON_HOST`, `DENON_ADMIN_TOKEN`)

**Interfaces:**
- Produces (HTTP, bearer `DENON_ADMIN_TOKEN`; 401 without):
  - `GET /state` -> `{"connected": bool, "power": bool, "mainZone": bool|null, "volume": float|null, "volumeMax": float, "mute": bool, "source": str, "mode": str, "sources": [{"code": str, "name": str}], "modes": [{"key": str, "name": str}]}` (same shape as the plugin's state, zone 2 omitted)
  - `POST /command` body `{"command": "power"|"volume"|"mute"|"source"|"mode", "value": ...}` -> `200 {"ok": true}`; `422` for a non-whitelisted command or a value outside its domain (power: `"on"|"off"`; volume: number 0..98; mute: `"on"|"off"`; source: `^[A-Z0-9/]{1,12}$`; mode: a key of `MODES`); `503` when not connected.
  - `GET /healthz` -> `200 {"status":"ok","connected":bool}` without auth.
- Consumes: nothing.

- [ ] **Step 1: Write failing pytest tests in `test_app.py`** for the pure parts: `Receiver.parse_line` (`PWON`, `MV325` -> 32.5, `MVMAX 98`, `MUON`, `SIBD`, `MSSTEREO`, `SSFUN END`), `format_volume(32.5) == "MV325"`, `format_volume(5) == "MV05"`, `format_volume(120) == "MV98"`, `sanitize("<b>x</b>"+"a"*50)` has no markup and len <= 32, and command building for each whitelisted command; unknown command raises `ValueError`.
- [ ] **Step 2: Run the pytest gate; expect FAIL** (module missing).
- [ ] **Step 3: Implement `app.py`**: port the `Receiver` state/parse logic and `format_volume`/`sanitize` from `denon_bridge.py` (zone 2 left out), a background thread owning one telnet connection with reconnect every 5 s and the 0.08 s send gap, queries `PW? ZM? MV? MU? SI? MS? SSFUN ?` on connect and every 30 s heartbeat, and the FastAPI routes above with the same bearer-auth style as `docker/teamspeak-admin/app.py` (constant-time compare, refuse to start serving commands when `DENON_ADMIN_TOKEN` is empty). Dockerfile mirrors `docker/teamspeak-admin/Dockerfile` (non-root user).
- [ ] **Step 4: Run the pytest gate; expect PASS.** Also add a FastAPI `TestClient` test for 401, 422 (unknown command, volume 99), 503 (not connected), 200.
- [ ] **Step 5: README + compose service**, then `bash scripts/ops/check-public-safe.sh`; commit `feat(room-audio): Denon telnet sidecar`.

### Task 2: Module scaffold and configuration

**Files:**
- Modify: `app/Support/Modules/Module.php` (case `RoomAudio = 'room_audio'`, alphabetical), `config/services.php` (`'room_audio' => ['rest_url' => env('DENON_ADMIN_REST_URL', 'http://denon-admin:8000'), 'token' => env('DENON_ADMIN_TOKEN')]`), `.env.example` (placeholders only), `tests/Architecture/*` baselines if the ratchet demands
- Create: `app/Modules/RoomAudio/` skeleton dirs, `lang/de/room_audio.php` (empty array for now)
- Test: `tests/Feature/RoomAudio/ModuleToggleTest.php`

**Interfaces:**
- Produces: `Module::RoomAudio`; `Modules::enabled(Module::RoomAudio)` true under `MODULES_PROFILE=full`, false under `core`; config keys `services.room_audio.rest_url|token`.

- [ ] **Step 1: Test** `ModuleToggleTest`: enabled with profile `full`, disabled with `core`, `MODULE_ROOM_AUDIO=false` disables it in `full` (follow how `tests/Feature` toggles other modules).
- [ ] **Step 2: Run it; expect FAIL.**
- [ ] **Step 3: Add the enum case and config**; run the architecture tests and fix the ratchet only as its own instructions say.
- [ ] **Step 4: Run `./vendor/bin/pest tests/Feature/RoomAudio tests/Architecture`; expect PASS.**
- [ ] **Step 5: Commit** `feat(room-audio): module toggle and config`.

### Task 3: ReceiverClient contract, HTTP and fake implementation

**Files:**
- Create: `app/Modules/RoomAudio/Contracts/ReceiverClient.php`, `app/Modules/RoomAudio/Support/ReceiverState.php`, `app/Modules/RoomAudio/Exceptions/ReceiverUnavailable.php`, `app/Modules/RoomAudio/HttpReceiverClient.php`, `app/Modules/RoomAudio/Testing/FakeReceiverClient.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind `ReceiverClient` like `MusicClient`, line ~304)
- Test: `tests/Feature/RoomAudio/HttpReceiverClientTest.php` (model on `tests/Feature/Voice/HttpTeamSpeakClientTest.php`)

**Interfaces:**
- Produces:
  - `final readonly class ReceiverState { bool $connected; bool $power; ?float $volume; float $volumeMax; bool $mute; string $source; string $mode; array $sources /* list<array{code:string,name:string}> */; array $modes /* list<array{key:string,name:string}> */; static function unreachable(): self; static function fromArray(array $d): self }`
  - `interface ReceiverClient { state(): ReceiverState; setPower(bool $on): void; setVolume(float $volume): void; setMute(bool $mute): void; setSource(string $code): void; setMode(string $key): void }`; every setter throws `ReceiverUnavailable` on connection error or HTTP 503; `state()` never throws, it returns `ReceiverState::unreachable()`.
  - `FakeReceiverClient` records calls in a public `array $calls` and holds a settable `ReceiverState`.
- Consumes: sidecar HTTP surface from Task 1; config from Task 2.

- [ ] **Step 1: Tests** with `Http::fake`: `state()` maps the sidecar JSON; `state()` returns `connected:false` on `ConnectionException` and on 503; `setVolume(32.5)` POSTs `{"command":"volume","value":32.5}` with the bearer token; each setter's command/value; `setVolume` throws `ReceiverUnavailable` on 503; a 422 is NOT swallowed (RequestException surfaces).
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** with the same transient-retry shape as `HttpTeamSpeakClient` for `state()` only (setters do not retry: a repeated volume step must not double-apply).
- [ ] **Step 4: Run; expect PASS.** Run `composer check`.
- [ ] **Step 5: Commit** `feat(room-audio): ReceiverClient contract with HTTP and fake`.

### Task 4: Ceiling setting, gate and actions

**Files:**
- Create: migration `create_room_audio_settings_table` (single row: `id`, `volume_ceiling` unsigned tinyint default 60, timestamps), `app/Modules/RoomAudio/Models/RoomAudioSetting.php` (`static current(): self` creates the row on demand), `app/Modules/RoomAudio/Policies/RoomAudioPolicy.php` (`control(User $user): bool` = `$user->isOrga()`), actions `SetReceiverPower`, `SetReceiverVolume`, `SetReceiverMute`, `SetReceiverSource`, `SetReceiverMode`, `SetVolumeCeiling` in `app/Modules/RoomAudio/Actions/`
- Modify: `app/Providers/AppServiceProvider.php` (`Gate::define('room-audio.control', ...)` inside `if ($this->on(Module::RoomAudio))`)
- Test: `tests/Feature/RoomAudio/ActionsTest.php`, `tests/Feature/RoomAudio/GateTest.php`

**Interfaces:**
- Produces: each Action has `__invoke(User $actor, ...)`, first line `Gate::forUser($actor)->authorize('room-audio.control')` (bypass-proof, as in the Friends actions); `SetReceiverVolume::__invoke(User $actor, float $volume): float` returns the value actually sent = `round(clamp(volume, 0, min(98, ceiling)) * 2) / 2`; `SetVolumeCeiling::__invoke(User $actor, int $ceiling): void` (0..98 else `InvalidArgumentException`); `SetReceiverSource` accepts only codes present in `state()->sources` plus `NET`; `SetReceiverMode` only keys in `state()->modes`.
- Consumes: `ReceiverClient`, `ReceiverState` (Task 3).

- [ ] **Step 1: Tests** (bind `FakeReceiverClient`): volume 150 with ceiling 60 sends 60; 32.3 sends 32.5 (0.5 rounding); -5 sends 0; NaN/INF throws `InvalidArgumentException` and sends nothing; helper/participant/guest get `AuthorizationException` for every action and nothing reaches the client; unknown source/mode rejected; ceiling 99 rejected.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** migration, model, gate, actions per the Interfaces block.
- [ ] **Step 4: Run; expect PASS; `composer check`.**
- [ ] **Step 5: Commit** `feat(room-audio): orga gate, ceiling and receiver actions`.

### Task 5: Filament page "Raumsteuerung"

**Files:**
- Create: `app/Modules/RoomAudio/Filament/Pages/RoomControl.php`, `resources/views/filament/pages/room-control.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (in the enabled-module loop also `discoverPages` from `app/Modules/{Module}/Filament/Pages` when the directory exists), `lang/de/room_audio.php`
- Test: `tests/Feature/RoomAudio/RoomControlPageTest.php` (Livewire, as in `tests/Feature/GameServers/ServerResourceAccessTest.php`)

**Interfaces:**
- Consumes: Actions, `ReceiverClient::state()`, `RoomAudioSetting::current()`.
- Produces: page `RoomControl` (`canAccess()` = `auth()->user()?->can('room-audio.control')`), Livewire methods `setVolume(float $v)`, `volumeStep(int $direction)` (±1.0 per call, clamps via the Action), `toggleMute()`, `togglePower()`, `setSource(string $code)`, `setMode(string $key)`, `saveCeiling(int $c)`; the view polls state every 5 s (`wire:poll.5s`) and shows three states: unreachable ("Receiver nicht erreichbar", all controls disabled), standby ("Receiver ist aus", only power enabled), normal.

- [ ] **Step 1: Tests**: orga sees the page and the state values; helper/participant get 403; with `FakeReceiverClient` unreachable the page renders the unreachable state without error; standby disables volume/mute/source (assert `disabled`), power stays enabled; `setVolume(200)` ends with the ceiling sent; `saveCeiling(70)` persists; an `Http`-level `ReceiverUnavailable` during an action shows a Filament danger notification, not a 500.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** the page. Design per `docs/design.md` (Signalpult): semantic tokens only, one amber accent (the volume value), all four states, visible focus, German copy in `lang/de/room_audio.php`; invoke the `frontend-design` skill first. Receiver text is rendered escaped.
- [ ] **Step 4: Run; expect PASS; `composer check`.** Take a screenshot of the three states with the Fake bound and attach it to the PR.
- [ ] **Step 5: Commit** `feat(room-audio): orga receiver control page`.

### Task 6: Preflight probe, docs and ops

**Files:**
- Create: `app/Modules/Preflight/Checks/ReceiverSidecarCheck.php`, `docs/infra/room-audio-denon.md` (deploy recipe with placeholders, network placement at the venue, WireGuard note, how to find the receiver's `NET` source name)
- Modify: `app/Providers/AppServiceProvider.php` (register the check when `Module::RoomAudio` enabled, next to `MusicAssistantCheck`), `lang/de/preflight.php` (`checks.room_audio`, message for `connected:false`), `README.md` (Project state), `CHANGELOG.md`, `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`, `CLAUDE.md` current-state paragraph if it lists modules
- Test: `tests/Feature/Preflight/ReceiverSidecarCheckTest.php`

**Interfaces:**
- Consumes: `ReceiverClient::state()`; `HealthResult::ok|warn|fail|skipped` (see `MusicAssistantCheck`, `HealthResult`).
- Produces: key `room_audio`; skipped when no token configured; fail when the sidecar is unreachable; warn when the sidecar answers but `connected:false`; ok otherwise.

- [ ] **Step 1: Test** the four outcomes with `FakeReceiverClient`/`Http::fake`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement the check, register it, write docs** (no real IPs; run `bash scripts/ops/check-public-safe.sh`).
- [ ] **Step 4: Run the full `composer check` and the sidecar gate; expect all green.**
- [ ] **Step 5: Commit** `feat(room-audio): preflight probe and docs`.
