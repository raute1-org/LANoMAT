# M7 — Infra & Betrieb Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Derived just-in-time from the roadmap M7 section (`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`), same format as the M4/M5/M6 detail plans, reshaped per the user decisions of 2026-07-17 (see Global Constraints + the "M7-specific reshaping" note).

**Goal:** Operable deployment infra — a **managed remote-hosts registry** (register a host by IP + SSH key) that powers custom game-server starts and a **separate-host LanCache**, plus a moderated LAN file-sharing service, a repeatable README-screenshot pipeline, and (config+docs) a Traefik ingress with TLS and an own Docker registry.

**Architecture:** A new `Hosts` module owns remote hosts behind a `RemoteExecutor` contract (phpseclib SSH2/SFTP impl + Fake, mirroring the M6 `PelicanClient`/M3 `MumbleClient` pattern exactly) — the app **only ever talks SSH through the contract**, so every app-side task is fully testable against `FakeRemoteExecutor` with no real host. SSH private keys are stored **encrypted at rest** (Laravel `encrypted` cast) and never leave memory. Custom servers (7.4) and LanCache (7.5) are host **roles** that run their setup/commands through the executor. A new `Files` module gives moderated LAN file-sharing (Storage-backed, visible only after orga/helper approval). The README-screenshot pipeline is a deterministic seeder + a Playwright script. Traefik (7.1) and the own registry (7.2) are compose/config + docs, verified against real infra later.

**Tech Stack:** PHP 8.4, Laravel 13, **phpseclib/phpseclib ^3** (SSH2/SFTP, pure-PHP, in-memory keys), Filament v5 (Schema API), Inertia v2 + Vue 3 `<script setup lang="ts">` + Tailwind v4 + shadcn-vue (Signalpult tokens), Pest 4, PostgreSQL 16, Redis, Docker Compose (FrankenPHP prod profile), **Traefik v3** (ingress/TLS), **Playwright** (headless screenshots). New external system: **remote SSH hosts** — accessed only through the `RemoteExecutor` contract; **never called in tests** (Fake only).

## Global Constraints

Copied verbatim from the roadmap Global Constraints + the binding conventions from the M2–M6 reviews — every task's requirements implicitly include these:

- Code, comments, commits, docs in **English**; UI copy in **German** via `lang/de/*.php` (no hardcoded strings in components — pass a `labels` prop from `trans('...')`).
- **Conventional Commits** (`feat(scope): …`). TDD: failing test first wherever there is a testable behavior; frequent commits. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. **Stage only task-specific paths** (never `git add -A`).
- PHP: Pint (Laravel preset), Larastan **level 8**, no `mixed` returns, **enums over magic strings**. Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- **Every authorization through a Policy** (registered in `AppServiceProvider::configureAuthorization()`); never trust client-supplied user IDs (`$request->user()`; Discord via `discord_id`). Helpers (`isHelper()`, M5) may operate hosts/files where the roadmap says orga/helper; `canAccessPanel()` stays `isOrga()`.
- **Actions pattern:** one class per use case, single `handle()`, returns the domain result. For capacity/quota/state or "check over child rows": `DB::transaction` + parent-row `lockForUpdate()` **first**; `QueryException` narrowed to SQLSTATE `23505`. Input validation in the domain Action (not only the FormRequest).
- **Privilege/state/secret fields never `$fillable`** (`ssh_private_key`, host fingerprint/status, file storage path, file approval status). Set only inside Actions/Jobs. Factories bypass `$fillable`.
- **Secrets encrypted at rest:** `remote_hosts.ssh_private_key` uses Laravel's `encrypted` cast (AES via `APP_KEY`), is **write-only in the UI** (never rendered back), and **never appears in any DTO, broadcast, log, or Inertia prop**.
- **External systems only via contracts + fakes.** `RemoteExecutor` (new) joins `PelicanClient`/`DiscordClient`/`MumbleClient`; `SshRemoteExecutor` connects with the decrypted in-memory key + **host-key fingerprint verification**; **no real SSH in tests** (`FakeRemoteExecutor` + a `fakeRemote()` helper). `Http::preventStrayRequests()` is global in the external-touching test dirs — add `Feature/Hosts`, `Feature/Files`.
- **Uploads to Laravel Storage**, never Base64 in the DB. File contents live on a disk; the DB holds the path + metadata only.
- **Design system is binding (M13, `docs/design.md`):** all new UI uses semantic token utilities (no raw hex), Space Grotesk + **JetBrains Mono for machine data** (host IP/port/fingerprint, file sizes, quotas), the `LiveIndicator` for host/service status, all four states (empty/loading/error/normal), responsive + focus + `prefers-reduced-motion`. **Invoke the `frontend-design` skill for any UI work** (also enforced by the PreToolUse hook on `.vue`/`.css` edits).
- **Gates green after every task:** `composer check` (pint --test, phpstan L8, `pest -d memory_limit=1G`) **and**, for any `.vue`/`.ts` change, `npm run lint:check && npm run format:check && npm run types:check && npm run build`.
- **i18n-gate:** every task adding `lang/de` keys includes ≥1 Feature-test assertion on a translated label; `.env.testing` has `APP_LOCALE=de`.

## M7-specific reshaping (user decisions 2026-07-17 — binding for this plan)

- **Execution mode A:** the code-testable tasks (T1–T7 below) are built now via SDD against `FakeRemoteExecutor`/`Storage::fake()`; the pure-infra tasks (T8 Traefik, T9 own registry) ship as **compose/config + docs**, validated with `docker compose config` and real-verified on actual infra later with the user. The **real SSH execution** against actual hosts (LanCache/custom-server) and the **actual Playwright capture run** are likewise deferred to real infra — the app code + Fakes are complete regardless.
- **LanCache runs on a SEPARATE host, NOT in LANoMAT's compose** (deviation from the roadmap 7.5 text "als Container im prod-Stack"). LANoMAT owns: **registering the LanCache host (IP + SSH key)** in the managed-hosts registry, applying/probing the LanCache setup on it **via the SSH executor**, the per-game "how to get it" catalog field, and the DNS/pre-caching docs. Update roadmap 7.5 to reflect this (T9 step).
- **Managed remote hosts unify 7.4 + 7.5:** both a custom-docker game server and a LanCache box are `RemoteHost`s (role enum) that LANoMAT drives over SSH. This is the through-line of the phase.
- **Verify-first (2026):** before writing `SshRemoteExecutor` (T2), confirm the current **phpseclib v3** API (SSH2 login with an in-memory `PublicKeyLoader` key, `exec`, SFTP `put`, host-key fingerprint retrieval/pinning) and the current **Traefik v3** compose/label config (T8) via context7/WebFetch. Pin whatever latest stable resolves; note deviations in the commit body.

## File Structure

- `app/Modules/Hosts/` — remote host registry + SSH executor. `Models/RemoteHost.php`, `Enums/{HostRole,HostStatus}.php`, `Contracts/RemoteExecutor.php`, `Domain/{CommandResult,HostProbe}.php`, `SshRemoteExecutor.php`, `Testing/FakeRemoteExecutor.php`, `Actions/{RegisterHost,ProbeHost}.php`, `Policies/RemoteHostPolicy.php`, `Filament/Resources/RemoteHosts/…`, `Casts/…` (none — use `encrypted`).
- `app/Modules/CustomServers/` — 7.4. `Models/CustomServer.php`, `Enums/CustomServerStatus.php`, `Actions/{StartCustomServer,StopCustomServer,ProbeCustomServer}.php`, `Policies/CustomServerPolicy.php`, `Filament/…`, `Http/…` (participant surface reuses the M6 server list where sensible).
- `app/Modules/Lancache/` — 7.5 orchestration. `Actions/{ApplyLancacheSetup,ProbeLancache}.php` (host role = lancache), plus the `games.install_hint` field lives in the existing `Games` module.
- `app/Modules/Files/` — 7.3. `Models/SharedFile.php`, `Enums/{FileVisibility}.php`, `Actions/{UploadSharedFile,ApproveSharedFile,RejectSharedFile,DeleteSharedFile}.php`, `Http/FilePageController.php`, `Policies/SharedFilePolicy.php`, `Filament/Resources/SharedFiles/…`, `resources/js/pages/Files/Index.vue`.
- `database/seeders/ScreenshotSeeder.php` + `scripts/screenshots/capture.mjs` + `docs/screenshots/` — 7.6.
- `docker/traefik/` + `compose.yml` prod extension + `docs/traefik-setup.md` — 7.1.
- `.github/workflows/…` + `docs/registry-setup.md` — 7.2.
- `docs/lancache-setup.md` — 7.5 infra docs.

## Task ordering & dependencies

```
Foundation:  T1 (Hosts model + encrypted key + Filament) → T2 (RemoteExecutor contract + phpseclib + Fake)
On the exec: T3 (CustomServers, 7.4)      T4 (LanCache role + games install_hint, 7.5)   [both consume T2]
Independent: T5 (Files upload/list, 7.3) → T6 (Files moderation + quota + Filament, 7.3)
Tooling:     T7 (README screenshot pipeline, 7.6)
Infra/docs:  T8 (Traefik + TLS, 7.1)      T9 (own registry, 7.2, + roadmap/lancache/prod-test docs)
```
T8/T9 (and the real SSH/Playwright runs) are infra — they don't block the coded tasks (all Fake-/Storage-tested). Recommended execution: T1 → T2 → T3 → T4 → T5 → T6 → T7 → T8 → T9. Each task ends green on all gates and is committed + pushed.

---

# Task 1: `Hosts` module — `RemoteHost` model, encrypted SSH key, Filament registry

**Files:**
- Create: `database/migrations/2026_07_17_100000_create_remote_hosts_table.php`
- Create: `app/Modules/Hosts/Enums/HostRole.php`, `app/Modules/Hosts/Enums/HostStatus.php`, `app/Modules/Hosts/Models/RemoteHost.php`, `database/factories/RemoteHostFactory.php`
- Create: `app/Modules/Hosts/Actions/RegisterHost.php`, `app/Modules/Hosts/Policies/RemoteHostPolicy.php`
- Create: `app/Modules/Hosts/Filament/Resources/RemoteHosts/{RemoteHostResource, Schemas/RemoteHostForm, Tables/RemoteHostsTable, Pages/{ListRemoteHosts,CreateRemoteHost,EditRemoteHost}}.php`
- Create: `lang/de/hosts.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover Hosts resources), `app/Providers/AppServiceProvider.php` (register `RemoteHostPolicy`), `tests/Pest.php` (add `Unit/Hosts` + `Feature/Hosts` to the `RefreshDatabase` group)
- Test: `tests/Unit/Hosts/RemoteHostTest.php`, `tests/Feature/Hosts/RemoteHostResourceAccessTest.php`

**Interfaces:**
- Produces:
  - `HostRole: string { Lancache='lancache', GameServer='gameserver', Generic='generic' }` + `label()`.
  - `HostStatus: string { Unknown='unknown', Reachable='reachable', Unreachable='unreachable' }` + `label()`.
  - `RemoteHost`: `id, name, hostname (string, IP or DNS), ssh_port int default 22, ssh_user string, ssh_private_key (encrypted, text), host_fingerprint (string nullable — SSH host-key SHA256, pinned on first connect), role (HostRole), event_id (nullable FK), status (HostStatus default Unknown), last_probed_at nullable, timestamps`. Fillable (descriptive): `name, hostname, ssh_port, ssh_user, role, event_id`. **Non-fillable:** `ssh_private_key` (set via RegisterHost/Filament override), `host_fingerprint`, `status`, `last_probed_at`. `casts`: `ssh_private_key => 'encrypted'`, `role => HostRole::class`, `status => HostStatus::class`, `last_probed_at => 'datetime'`. `event(): BelongsTo` (nullable).
  - `RegisterHost::handle(array $data, string $privateKeyPem, User $actor): RemoteHost` — authorize `isOrga()`; validates the PEM parses as a key; persists with the key set (encrypted) but never logged.
  - `RemoteHostPolicy`: `viewAny/create/update/delete => isOrga()`.

- [ ] **Step 1: Failing tests** — `RemoteHostTest`: factory row; `ssh_private_key` round-trips through the `encrypted` cast (write "PRIVATE-KEY-PEM", re-fetch, equals) **and** the raw DB column value is NOT the plaintext (`DB::table('remote_hosts')->value('ssh_private_key') !== 'PRIVATE-KEY-PEM'` — proves at-rest encryption); `ssh_private_key`/`host_fingerprint`/`status` are NOT in `$fillable` (getFillable assertion); `role`/`status` German labels (`lang/de/hosts.php`). `RemoteHostResourceAccessTest`: participant `GET /admin/remote-hosts` → 403; helper → 403; orga → 200 and the list renders a seeded host name; **the table/list output does NOT contain the plaintext private key** (assert the response body has no `ssh_private_key` value).
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Hosts tests/Feature/Hosts` → FAIL.
- [ ] **Step 3: Implement** — migration (below), enums, model, factory (factory sets an encrypted dummy key directly), `RegisterHost`, policy + registration, discovery entry, Filament resource. The `RemoteHostForm` SSH-key field is a `Textarea`/`FileUpload` that is **write-only** (`->dehydrated(fn ($state) => filled($state))` so an empty edit keeps the existing key; never pre-fill it — do NOT `->formatStateUsing` the key back into the form). Persist the non-fillable key via a Create/Edit page override (mirror the M6 `default_server_config` / Catering precedent). The table column for the key shows a masked placeholder (`••••` or "hinterlegt"), never the value.
```php
// remote_hosts migration up()
Schema::create('remote_hosts', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('hostname');
    $table->unsignedSmallInteger('ssh_port')->default(22);
    $table->string('ssh_user');
    $table->text('ssh_private_key'); // stored via Laravel 'encrypted' cast — ciphertext at rest
    $table->string('host_fingerprint')->nullable();
    $table->string('role')->default('generic');
    $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
    $table->string('status')->default('unknown');
    $table->timestamp('last_probed_at')->nullable();
    $table->timestamps();
    $table->index('role');
    $table->index('event_id');
});
```
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Hosts tests/Feature/Hosts && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_17_100000_create_remote_hosts_table.php app/Modules/Hosts database/factories/RemoteHostFactory.php app/Providers/Filament/AdminPanelProvider.php app/Providers/AppServiceProvider.php lang/de/hosts.php tests/Pest.php tests/Unit/Hosts tests/Feature/Hosts
git commit -m "feat(hosts): remote-host registry with encrypted ssh key and filament resource"
```

# Task 2: `RemoteExecutor` contract + `SshRemoteExecutor` (phpseclib) + `FakeRemoteExecutor`

**Files:**
- Create: `app/Modules/Hosts/Contracts/RemoteExecutor.php`, `app/Modules/Hosts/Domain/{CommandResult,HostProbe}.php`, `app/Modules/Hosts/SshRemoteExecutor.php`, `app/Modules/Hosts/Testing/FakeRemoteExecutor.php`, `app/Modules/Hosts/Exceptions/RemoteExecutionException.php`
- Create: `app/Modules/Hosts/Actions/ProbeHost.php`
- Modify: `composer.json` (require `phpseclib/phpseclib:^3`), `config/services.php` (add a `hosts` block for connect timeout + `strict_host_key_checking`), `app/Providers/AppServiceProvider.php` (bind `RemoteExecutor` → `SshRemoteExecutor`), `tests/Pest.php` (add a `fakeRemote()` helper)
- Test: `tests/Unit/Hosts/FakeRemoteExecutorTest.php`, `tests/Feature/Hosts/ProbeHostTest.php`

**Interfaces:**
- Consumes: `RemoteHost` (T1).
- Produces:
  - `CommandResult` (readonly): `int $exitCode, string $stdout, string $stderr`. `ok(): bool` = `exitCode === 0`.
  - `HostProbe` (readonly): `bool $reachable, ?string $fingerprint, ?string $error`.
  - `RemoteExecutor`: `run(RemoteHost $host, string $command): CommandResult`; `upload(RemoteHost $host, string $contents, string $remotePath): void`; `probe(RemoteHost $host): HostProbe`.
  - `SshRemoteExecutor implements RemoteExecutor` — constructor `(int $connectTimeout, bool $strictHostKey)` from `config('services.hosts')`; loads the key with `phpseclib3\Crypt\PublicKeyLoader::load($host->ssh_private_key)`; on connect, reads the server host key fingerprint (`$ssh->getServerPublicHostKey()` → SHA256) and, when `$strictHostKey` and `$host->host_fingerprint` is set, **aborts if it doesn't match** (throws `RemoteExecutionException`); `run` returns exit status + stdout/stderr; `upload` uses `phpseclib3\Net\SFTP::put`. **Verify the exact phpseclib v3 API before writing.** Never writes the key to disk; never logs the key or command output verbatim at info level.
  - `FakeRemoteExecutor implements RemoteExecutor` — `public array $commands = []` (each `['host_id','command']`), `public array $uploads = []`, a settable `array $results` keyed by a command substring + a default `CommandResult(0,'','')`, a settable `HostProbe $nextProbe`; assertion helpers `assertRan(string $commandSubstring)`, `assertUploaded(string $remotePath)`, `assertNothingRan()`; `queueResult(string $match, CommandResult $r)`.
  - `ProbeHost::handle(RemoteHost $host): RemoteHost` — calls `probe()`, stores `status` (Reachable/Unreachable), pins `host_fingerprint` if empty, stamps `last_probed_at`. Non-fillable writes via forceFill/assignment.
  - `RemoteExecutionException` with German `translationKey`s (connect failed, fingerprint mismatch, command failed).

- [ ] **Step 1: Failing tests** — `FakeRemoteExecutorTest`: `run` records + returns the queued/default `CommandResult`; `upload` recorded + `assertUploaded`; `probe` returns `nextProbe`; `assertNothingRan` on a fresh fake; `assertRan('docker ps')` after a matching run. `ProbeHostTest` (`fakeRemote()`): a reachable probe sets `status = Reachable` + pins `host_fingerprint` + stamps `last_probed_at`; an unreachable probe sets `Unreachable`; a German label asserted. (No `SshRemoteExecutor` unit test against a real server — it's the un-faked impl; a thin construction/argument test is enough, real SSH is out of scope for tests.)
- [ ] **Step 2: Run red** — `./vendor/bin/pest tests/Unit/Hosts tests/Feature/Hosts` → FAIL.
- [ ] **Step 3: Implement** — `composer require phpseclib/phpseclib:^3`; contract, DTOs, Ssh impl (verify phpseclib API first), Fake, `ProbeHost`, config block, binding, `fakeRemote()` helper.
```php
// config/services.php — add
'hosts' => [
    'connect_timeout' => (int) env('HOSTS_SSH_TIMEOUT', 10),
    'strict_host_key' => (bool) env('HOSTS_STRICT_HOST_KEY', true),
],
```
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Hosts tests/Feature/Hosts && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Hosts/Contracts app/Modules/Hosts/Domain app/Modules/Hosts/SshRemoteExecutor.php app/Modules/Hosts/Testing app/Modules/Hosts/Exceptions app/Modules/Hosts/Actions/ProbeHost.php composer.json composer.lock config/services.php app/Providers/AppServiceProvider.php tests/Pest.php tests/Unit/Hosts tests/Feature/Hosts
git commit -m "feat(hosts): RemoteExecutor contract, phpseclib ssh impl, fake and host probe"
```

# Task 3: Custom-server command execution (7.4 app-side) — `CustomServers` module

**Files:**
- Create: `database/migrations/2026_07_17_110000_create_custom_servers_table.php`
- Create: `app/Modules/CustomServers/Enums/CustomServerStatus.php`, `app/Modules/CustomServers/Models/CustomServer.php`, `database/factories/CustomServerFactory.php`
- Create: `app/Modules/CustomServers/Actions/{StartCustomServer,StopCustomServer,ProbeCustomServer}.php`, `app/Modules/CustomServers/Policies/CustomServerPolicy.php`
- Create: `app/Modules/CustomServers/Filament/Resources/CustomServers/{CustomServerResource, Schemas/CustomServerForm, Tables/CustomServersTable, Pages/{ListCustomServers,CreateCustomServer,EditCustomServer}}.php`
- Create: `lang/de/customservers.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover), `app/Providers/AppServiceProvider.php` (policy), `tests/Pest.php` (`Unit/CustomServers`, `Feature/CustomServers` → RefreshDatabase + stray-guard)
- Test: `tests/Feature/CustomServers/CustomServerLifecycleTest.php`

**Interfaces:**
- Consumes: `RemoteHost` (T1), `RemoteExecutor`/`CommandResult` (T2).
- Produces:
  - `CustomServerStatus: string { Stopped='stopped', Starting='starting', Running='running', Failed='failed' }` + `label()`.
  - `CustomServer`: `id, name, remote_host_id (FK), event_id (nullable), image string, command string nullable, ports string nullable, env jsonb nullable, container_name string, status (CustomServerStatus default Stopped), last_output text nullable, timestamps`. Non-fillable: `status`, `last_output`. Fillable: `name, remote_host_id, event_id, image, command, ports, env, container_name`. `host(): BelongsTo(RemoteHost)`.
  - `StartCustomServer::handle(CustomServer $server, User $actor): CustomServer` — authorize `isOrga()`; builds a `docker run -d --name {container_name} [-p ports] [-e env] {image} [command]` command from the **structured fields** (never a raw user string spliced in unescaped — each value passed through `escapeshellarg`), runs it via `RemoteExecutor::run($server->host, …)`, sets `status = Running` on exit 0 else `Failed` + stores `last_output` (stderr trimmed). `StopCustomServer` runs `docker rm -f {container_name}` → `Stopped`. `ProbeCustomServer` runs `docker inspect -f '{{.State.Running}}' {container_name}` → Running/Stopped.
  - `CustomServerPolicy`: `viewAny/create/update/delete/start/stop => isOrga()`.

- [ ] **Step 1: Failing test** — `CustomServerLifecycleTest` (`fakeRemote()`): `StartCustomServer` runs a `docker run` command containing the image + container name (`$fake->assertRan('docker run')`) and flips status to Running; a non-zero exit (queue a `CommandResult(1,'','boom')`) → status Failed + `last_output` contains "boom"; `StopCustomServer` runs `docker rm -f` → Stopped; **command injection guard**: a server with `command = '; rm -rf /'` produces a command where the payload is a single `escapeshellarg`-quoted token (assert the composed command contains the quoted form, not a bare `; rm -rf /`); a participant → 403; German label asserted.
- [ ] **Step 2: Run red** → FAIL.
- [ ] **Step 3: Implement** — migration, enum, model, factory, actions (structured command builder with `escapeshellarg` per value), policy + registration + discovery, Filament resource, labels.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/CustomServers && composer check` → PASS.
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_17_110000_create_custom_servers_table.php app/Modules/CustomServers database/factories/CustomServerFactory.php app/Providers/Filament/AdminPanelProvider.php app/Providers/AppServiceProvider.php lang/de/customservers.php tests/Pest.php tests/Feature/CustomServers
git commit -m "feat(customservers): docker-command game servers on registered hosts via ssh executor"
```

# Task 4: LanCache host role + games `install_hint` field (7.5 app-side)

**Files:**
- Create: `database/migrations/2026_07_17_120000_add_install_hint_to_games.php`
- Create: `app/Modules/Games/Domain/InstallHint.php` (readonly DTO), `app/Modules/Games/Casts/InstallHintCast.php`
- Create: `app/Modules/Lancache/Actions/{ApplyLancacheSetup,ProbeLancache}.php`, `app/Modules/Lancache/Exceptions/LancacheException.php`
- Modify: `app/Modules/Games/Models/Game.php` (add `install_hint` cast, non-fillable), `app/Modules/Games/Filament/Resources/Games/Schemas/GameForm.php` (install-hint fields), `app/Modules/GameServers/Support/ServerListProjection.php` (surface `installHint` on the participant server/games view), `resources/js/pages/Servers/Index.vue` (show the "So kommst du ran" hint, mono for the steam:// URL), `lang/de/{games,lancache}.php`
- Test: `tests/Unit/Games/InstallHintTest.php`, `tests/Feature/Lancache/ApplyLancacheSetupTest.php`

**Interfaces:**
- Consumes: `RemoteHost` (role Lancache, T1), `RemoteExecutor` (T2), `Game` (M6 T1).
- Produces:
  - `InstallHint` (readonly): `?string $steamUrl` (e.g. `steam://install/730`), `?string $shareUrl` (link into the Files service, T5), `?string $versionNote`. `toArray()`/`fromArray()` (drop empties).
  - `InstallHintCast implements CastsAttributes` — jsonb ⇄ `InstallHint`, tolerant `get()`, `is_array`/`instanceof` guard + `JSON_THROW_ON_ERROR` on `set()` (mirror the M6 `ServerConfigCast`/`SceneConfigCast` precedent). `Game::install_hint` non-fillable typed jsonb, edited via Filament + Create/Edit override.
  - `ApplyLancacheSetup::handle(RemoteHost $host, User $actor): CommandResult` — authorize `isOrga()`; require `$host->role === HostRole::Lancache` (else `LancacheException`); run the lancache-bootstrap on the host via `RemoteExecutor` (a documented, structured docker/compose command that pulls & starts the `lancachenet/monolithic` stack — the **exact command lives in `docs/lancache-setup.md`**, T9; here it is built from config, not free-typed). `ProbeLancache::handle(RemoteHost)` → runs a health command, returns reachability.
  - `LancacheException` with German `translationKey`s.

- [ ] **Step 1: Failing tests** — `InstallHintTest`: `install_hint` casts to `InstallHint`; non-fillable (getFillable); `toArray` drops empty fields; German label. `ApplyLancacheSetupTest` (`fakeRemote()`): applying on a role=Lancache host runs the bootstrap command (`$fake->assertRan('lancache')`); applying on a role=Generic host throws `LancacheException`; a participant → 403.
- [ ] **Step 2: Run red** → FAIL.
- [ ] **Step 3: Implement** — migration, DTO, cast, `Game` field, Filament install-hint fields, projection + Vue hint (Signalpult: `font-mono` for the `steam://` URL + copy), the two Lancache actions, exception, labels.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Unit/Games tests/Feature/Lancache && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_17_120000_add_install_hint_to_games.php app/Modules/Games/Domain/InstallHint.php app/Modules/Games/Casts/InstallHintCast.php app/Modules/Games/Models/Game.php app/Modules/Games/Filament/Resources/Games/Schemas/GameForm.php app/Modules/Lancache app/Modules/GameServers/Support/ServerListProjection.php resources/js/pages/Servers/Index.vue lang/de/games.php lang/de/lancache.php tests/Unit/Games/InstallHintTest.php tests/Feature/Lancache
git commit -m "feat(lancache): lancache-host setup via ssh executor and per-game install hint"
```

# Task 5: `Files` module — upload + storage + participant list (7.3, part 1)

**Files:**
- Create: `database/migrations/2026_07_17_130000_create_shared_files_table.php`
- Create: `app/Modules/Files/Enums/FileVisibility.php`, `app/Modules/Files/Models/SharedFile.php`, `database/factories/SharedFileFactory.php`
- Create: `app/Modules/Files/Actions/{UploadSharedFile,DeleteSharedFile}.php`, `app/Modules/Files/Exceptions/FileException.php`, `app/Modules/Files/Policies/SharedFilePolicy.php`
- Create: `app/Modules/Files/Http/FilePageController.php`, `resources/js/pages/Files/Index.vue`, `resources/js/types/files.ts`
- Modify: `routes/web.php` (public `GET /events/{event:slug}/files`, auth `POST /events/{event:slug}/files`, `GET /files/{sharedFile}/download`, `DELETE /files/{sharedFile}`), `app/Providers/AppServiceProvider.php` (policy), `lang/de/files.php`, `tests/Pest.php` (`Feature/Files` RefreshDatabase)
- Test: `tests/Feature/Files/FileUploadTest.php`, `tests/Feature/Files/FileVisibilityTest.php`

**Interfaces:**
- Produces:
  - `FileVisibility: string { Pending='pending', Approved='approved', Rejected='rejected' }` + `label()`.
  - `SharedFile`: `id, event_id (FK), user_id (uploader FK), disk string default 'local', path string, original_name string, size_bytes bigint, mime string nullable, visibility (FileVisibility default Pending), reviewed_by nullable, reviewed_at nullable, timestamps`. Non-fillable: `disk, path, size_bytes, mime, visibility, reviewed_by, reviewed_at` (Action-only). Fillable: `event_id, user_id, original_name` — but `user_id` set from the authenticated actor in the Action, never from client input. `event(): BelongsTo`, `uploader(): BelongsTo(User,'user_id')`. Store files on the **private `local` disk** (not public) — downloads go through the authorized route, never a public URL, so pending/rejected files are never world-readable.
  - `UploadSharedFile::handle(Event $event, User $actor, UploadedFile $file): SharedFile` — authorize `isParticipant`-or-above via policy; validate size ≤ config cap + mime allowlist; **enforce per-event per-user quota** (sum of the user's existing files in the event < `config('files.per_user_quota_mb')`) inside a `DB::transaction` with a lock; store to `local` disk under `event-{id}/…`; create the row with `visibility = Pending`. Returns the model.
  - `DeleteSharedFile::handle(SharedFile $file): void` — deletes the disk file + row (owner or orga; policy-gated).
  - `SharedFilePolicy`: `create => auth user`; `view => visibility Approved OR owner OR isOrga`; `download => same as view`; `delete => owner OR isOrga`; `approve/reject => isHelper` (used in T6).
  - `FilePageController@index` → `Files/Index` with the event's **approved** files (+ the viewer's own pending, flagged) + `labels`; `@download` streams via `Storage::disk($file->disk)->download(...)` after `authorize('download', $file)`.

- [ ] **Step 1: Failing tests** — `FileUploadTest` (`Storage::fake('local')`): an authed participant POSTs a file → stored on the `local` disk (assert `Storage::disk('local')->exists($path)`, **not** Base64/DB), row `visibility = Pending`, `user_id` = the actor (a client-supplied `user_id` in the payload is IGNORED); over-quota upload → `FileException` (German), no file stored; over-size/bad-mime → validation error. `FileVisibilityTest`: the participant list shows **approved** files to everyone; a **pending** file is visible to its uploader (flagged) but NOT to another participant; a draft event → 404; download of an approved file streams 200, download of someone else's pending file → 403.
- [ ] **Step 2: Run red** → FAIL.
- [ ] **Step 3: Implement** — migration, enum, model, factory, actions (quota in a locked tx), exception, policy + registration, controller + routes + Vue page (`frontend-design` skill: Signalpult, `font-mono` for file sizes, empty/loading/error states, upload affordance) + types + labels.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Files && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add database/migrations/2026_07_17_130000_create_shared_files_table.php app/Modules/Files/Enums app/Modules/Files/Models app/Modules/Files/Actions app/Modules/Files/Exceptions app/Modules/Files/Policies app/Modules/Files/Http resources/js/pages/Files resources/js/types/files.ts routes/web.php app/Providers/AppServiceProvider.php lang/de/files.php tests/Pest.php tests/Feature/Files
git commit -m "feat(files): moderated LAN file-sharing upload, storage and participant list"
```

# Task 6: `Files` moderation gate + Filament + quota surfacing (7.3, part 2)

**Files:**
- Create: `app/Modules/Files/Actions/{ApproveSharedFile,RejectSharedFile}.php`
- Create: `app/Modules/Files/Filament/Resources/SharedFiles/{SharedFileResource, Tables/SharedFilesTable, Pages/ListSharedFiles}.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (discover), `resources/js/pages/Files/Index.vue` (show remaining quota + a pending badge on own files), `lang/de/files.php`
- Test: `tests/Feature/Files/FileModerationTest.php`, `tests/Feature/Files/FileResourceAccessTest.php`

**Interfaces:**
- Consumes: `SharedFile`/`FileVisibility` (T5), `RemoteHostPolicy` pattern.
- Produces:
  - `ApproveSharedFile::handle(SharedFile $file, User $actor): SharedFile` — authorize `isHelper()` in the Action; set `visibility = Approved`, `reviewed_by = actor->id`, `reviewed_at = now()`. `RejectSharedFile` → `Rejected` (file stays on disk for the uploader/orga but is hidden from others; a later prune is out of scope).
  - Filament `SharedFileResource` (`/admin/shared-files`): orga sees ALL files (uploader, event, size mono, visibility badge, download link); row actions **Freigeben/Ablehnen** (`->authorize('approve')->requiresConfirmation()`, call the actions, catch `FileException` → `Notification::danger`). `SharedFileResource` policy = the T5 `SharedFilePolicy` (viewAny = isOrga).
  - `Files/Index.vue` gains: remaining quota readout (`font-mono`, e.g. "312 / 500 MB"), a "Wartet auf Freigabe" badge on the viewer's own pending files.

- [ ] **Step 1: Failing tests** — `FileModerationTest`: a helper `ApproveSharedFile` flips a pending file to Approved (+ reviewer stamped) and it now appears for other participants; a participant calling approve → 403; `RejectSharedFile` hides it from others but the uploader still sees it. `FileResourceAccessTest`: participant `GET /admin/shared-files` → 403; orga → 200 + sees a pending file; the Freigeben row action calls `ApproveSharedFile` (Livewire test).
- [ ] **Step 2: Run red** → FAIL.
- [ ] **Step 3: Implement** — approve/reject actions, Filament resource + row actions + discovery, Vue quota/pending surfacing (`frontend-design` skill), labels.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/Files && composer check && npm run lint:check && npm run format:check && npm run types:check && npm run build` → PASS.
- [ ] **Step 5: Commit**
```bash
git add app/Modules/Files/Actions/ApproveSharedFile.php app/Modules/Files/Actions/RejectSharedFile.php app/Modules/Files/Filament app/Providers/Filament/AdminPanelProvider.php resources/js/pages/Files/Index.vue lang/de/files.php tests/Feature/Files/FileModerationTest.php tests/Feature/Files/FileResourceAccessTest.php
git commit -m "feat(files): orga/helper moderation gate, filament review and quota surfacing"
```

# Task 7: README screenshot pipeline (7.6)

**Files:**
- Create: `database/seeders/ScreenshotSeeder.php`, `scripts/screenshots/capture.mjs`, `scripts/screenshots/README.md`, `docs/screenshots/.gitkeep`
- Modify: `package.json` (add `"screenshots": "node scripts/screenshots/capture.mjs"` + a `@playwright/test` devDependency), `README.md` (embed the generated screens), `database/seeders/DatabaseSeeder.php` (do NOT auto-call ScreenshotSeeder; it is invoked explicitly)
- Test: `tests/Feature/ScreenshotSeederTest.php`

**Interfaces:**
- Produces:
  - `ScreenshotSeeder` — deterministic (fixed names/slugs, no `fake()`-randomness that changes across runs; seed a stable RNG or use literal values): one demo event `screenshot-demo` in a running state with a started tournament (a few entries + a Ready/live match), a filled seating grid, an open poll, a couple of approved shared files, a schedule + catering menu — enough to populate the core screens. Idempotent (`updateOrCreate` on stable slugs).
  - `capture.mjs` — a Playwright script: boot assumes a running server at `APP_URL` (default `http://localhost:8000`), logs in as a seeded orga where needed, visits ~6–8 routes (event page, registration/QR, seating, live bracket, schedule/catering/voting/lfg, files, `/admin`, infoscreen `/screen/{slug}`), captures light + dark (`colorScheme`) at a fixed viewport to `docs/screenshots/<name>-<light|dark>.png`.
- **Honest scope:** the seeder + script are the deliverable and are tested/committed; the **actual capture run** needs a running server + a Playwright browser and is documented in `scripts/screenshots/README.md` — run it against a real/staging instance (the sandbox preview harness is unreliable). The README embeds the expected filenames; committing the actual PNGs is a manual follow-up when the pipeline is run for real.

- [ ] **Step 1: Failing test** — `ScreenshotSeederTest`: running `ScreenshotSeeder` twice is idempotent (second run doesn't duplicate the demo event — `Event::where('slug','screenshot-demo')->count() === 1`) and produces the expected fixtures (a started tournament with ≥1 match, ≥1 approved shared file, an open poll). Assert on the DB state, not on screenshots.
- [ ] **Step 2: Run red** → FAIL.
- [ ] **Step 3: Implement** — the seeder (reusing existing factories deterministically), the Playwright script, the npm wiring, `scripts/screenshots/README.md` (how to run: `php artisan db:seed --class=ScreenshotSeeder` then `npm run screenshots`), README embed section + `docs/screenshots/.gitkeep`.
- [ ] **Step 4: Green + gates** — `./vendor/bin/pest tests/Feature/ScreenshotSeederTest.php && composer check` → PASS. (`npm run build` only if you touched app `.ts`; the `.mjs` script is not part of the app bundle — run `npm run lint:check` if your eslint config covers `scripts/`.)
- [ ] **Step 5: Commit**
```bash
git add database/seeders/ScreenshotSeeder.php database/seeders/DatabaseSeeder.php scripts/screenshots package.json package-lock.json README.md docs/screenshots/.gitkeep tests/Feature/ScreenshotSeederTest.php
git commit -m "feat(tooling): deterministic screenshot seeder and playwright capture pipeline"
```

# Task 8: Traefik reverse proxy + TLS (7.1, config + docs — real-verify later)

**Files:**
- Create: `docker/traefik/traefik.yml` (static config), `docker/traefik/dynamic.yml` (middlewares/TLS options), `docs/traefik-setup.md`
- Modify: `compose.yml` (add a `traefik` service under the `prod` profile + router labels on `app`/`reverb-prod`; keep the dev stack unchanged), `.env.example` (`TRAEFIK_*`, `ACME_EMAIL`, `APP_DOMAIN`), `README.md` (link the setup doc)
- Test: n/a (infra) — a **validation step** instead.

**Interfaces:**
- Produces: Traefik v3 as ingress in front of `app` (FrankenPHP, M5.6), `reverb-prod` (WebSocket upgrade must be preserved — a router + the WS-safe middleware), and Filament `/admin` (same `app` service, path-routed). TLS via ACME (Let's Encrypt) with an internal-CA/self-signed fallback for a pure-LAN deployment (documented). Pelican/Mumble routing documented as optional. **Verify the current Traefik v3 compose/label syntax before writing.**

- [ ] **Step 1:** Write the Traefik static + dynamic config and the compose service/labels (routers for `app`, `reverb-prod` with WS upgrade, `/admin` path). Add `.env.example` keys.
- [ ] **Step 2 (validation):** `docker compose --profile prod config` parses without error (run it; paste the tail into the report). Do NOT attempt a real ACME cert in this environment.
- [ ] **Step 3:** Write `docs/traefik-setup.md` — DNS/`APP_DOMAIN`, ACME vs internal-CA, the Reverb WS caveat, how `/admin` is routed, and how this composes with the M5.6 prod profile; link it from README + `docs/prod-test.md`.
- [ ] **Step 4: Commit**
```bash
git add docker/traefik docs/traefik-setup.md compose.yml .env.example README.md
git commit -m "feat(infra): traefik v3 ingress with tls for the prod profile (config + docs)"
```

# Task 9: Own Docker registry (7.2) + infra docs consolidation (LanCache/prod-test/roadmap)

**Files:**
- Create: `docs/registry-setup.md`, `docs/lancache-setup.md`
- Modify: `.github/workflows/` (a `publish-images` workflow: build the FrankenPHP `app` image from M5.6 + push to the configured registry on tag/release; auth via CI secrets), `compose.yml` (optionally an internal `registry:2` service under a `registry` profile OR document an external registry — **document, do not force a container**), `docs/prod-test.md` (add Registry + LanCache + Files + Custom-server sections), `README.md`, `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (update 7.5 to "separate host via the managed-hosts registry", not a prod-stack container)
- Test: n/a (infra/CI/docs) — a **validation step**.

**Interfaces:**
- Produces: `docs/registry-setup.md` (private registry for the LANoMAT `app` image + gameserver images/Pelican eggs from M6.1; push/pull auth; how deploy pulls from it); a CI workflow that builds + pushes the `app` image (guard it so it only runs where registry secrets exist); `docs/lancache-setup.md` (the **separate LanCache host** setup: register it as a `RemoteHost` role=lancache in LANoMAT, the exact bootstrap command `ApplyLancacheSetup` runs, DNS pointing for Steam/Epic/Battle.net, the pre-caching-the-vote-winner checklist). Roadmap 7.5 corrected.

- [ ] **Step 1:** Write the `publish-images` CI workflow (build from `docker/Dockerfile` M5.6, tag from the git tag, push to `${{ secrets.REGISTRY_* }}`; `if:` guard on secret presence). Validate YAML with `actionlint` if available, else a careful read.
- [ ] **Step 2:** Write `docs/registry-setup.md` + `docs/lancache-setup.md` (ground every step in real `.env` keys, `RemoteHost`/`ApplyLancacheSetup`, and the M5.6 image); extend `docs/prod-test.md`.
- [ ] **Step 3:** Update roadmap 7.5 text (separate host, managed-hosts registry) + add the "Erkenntnisse M7" scaffold note that LanCache is not a prod-stack container.
- [ ] **Step 4: Commit**
```bash
git add .github/workflows docs/registry-setup.md docs/lancache-setup.md docs/prod-test.md compose.yml README.md docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md
git commit -m "docs(infra): own-registry ci + separate-host lancache setup + prod-test/roadmap sync"
```

---

## Phase acceptance (M7)

- **Code (Fake-/Storage-tested):** a `RemoteHost` registered with an encrypted-at-rest SSH key (raw column ≠ plaintext, verified); `ProbeHost` sets status + pins the fingerprint; a `CustomServer` start/stop runs `docker run`/`docker rm -f` on its host via `FakeRemoteExecutor` with `escapeshellarg`-guarded values; `ApplyLancacheSetup` runs the bootstrap on a role=lancache host and rejects a non-lancache host; a participant uploads a file that lands on the private disk as `Pending`, is invisible to others until a helper approves it, and over-quota/over-size uploads are rejected; the Filament moderation gate approves/rejects; the games `install_hint` surfaces on the participant view; the screenshot seeder is deterministic/idempotent.
- **Infra (config + docs, real-verify later):** `docker compose --profile prod config` is valid with Traefik; the `publish-images` CI workflow is present + guarded; `docs/{traefik,registry,lancache}-setup.md` + the extended `docs/prod-test.md` ground every step in real `.env`/services/commands.
- Green CI on all six gates; **all new UI verified against the Signalpult design system** (`docs/design.md`) via the `frontend-design` skill + preview; i18n-gate satisfied per task.
- Whole-branch review on **opus** (base = tag `m6`) → one consolidated fix wave → tag `m7`; close/advance the GitHub M7 milestone (#8) + board item; push to `origin`. Update the roadmap M7 section with an "Erkenntnisse M7" block (esp. the phpseclib/host-key + SSH-security findings and the LanCache reshaping).

## Deferred / explicitly out of scope for M7

- **Real infra runs:** actual SSH against real hosts (LanCache/custom-server), a real Traefik ACME cert, a real registry push, and the actual Playwright capture run — done on real infra with the user (like M6-T13).
- **Discord guild-membership auth** ([#8](https://github.com/raute1-org/LANoMAT/issues/8)) — a separate identity concern, not infra; belongs with M9 Identity+, not M7.
- **Voice multiprovider / TeamSpeak** ([#2](https://github.com/raute1-org/LANoMAT/issues/2)) — M8.
- File antivirus scanning, chunked/resumable uploads, and rejected-file pruning — post-M7 polish if the LAN needs them.
