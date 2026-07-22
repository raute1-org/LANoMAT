# Preflight Ampel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `lanomat:preflight` command + Filament status tile + scheduled orga bell that probe every internal and external system LANoMAT depends on and report a traffic-light (ampel) result.

**Architecture:** A new `app/Modules/Preflight/` module with a `HealthCheck` interface, a tagged registry run by `RunPreflight`, self-contained diagnostic probes per system (no changes to the domain contracts), a CLI ampel, a Filament dashboard widget, an edge-triggered scheduled bell, and a `lanomat:heartbeat` liveness marker feeding the scheduler/queue-worker checks.

**Tech Stack:** PHP 8.4 · Laravel 13 · Filament v5 · Pest 4 · Redis/Postgres · Reverb.

**Spec:** `docs/superpowers/specs/2026-07-22-preflight-ampel-design.md` (§11 open decisions resolved: `failed_jobs>0` = `warn`; bell on any `down`, `skipped` never bells; cadences heartbeat 1 min / watch 5 min / widget cache 15 s / staleness 2 min).

## Global Constraints

- All logic in PHP; **never call a real external API in tests** — every external probe is driven by `Http::fake()` / config presence.
- External checks return **`skipped`** when their config/credentials are absent (Mode-A tolerant) — never `down`.
- A check must never throw out of `RunPreflight`: a throwing check is caught and reported `down`.
- Every HTTP probe uses a short timeout (≈3 s) so a dead system can't hang the ampel.
- Orga-facing copy in German via `lang/de/preflight.php`; code/comments/commits English. Filament widget follows the Signalpult design system (semantic status colours; amber is the one rationed signal; mono for machine data).
- Pest runs sequentially. `composer check` green after every task.
- Conventional Commits; TDD; frequent commits.

## File Structure

**Create:**
- `app/Modules/Preflight/Enums/HealthStatus.php`
- `app/Modules/Preflight/Support/HealthResult.php`
- `app/Modules/Preflight/Contracts/HealthCheck.php`
- `app/Modules/Preflight/Actions/RunPreflight.php`
- `app/Modules/Preflight/Checks/{Database,Redis,StorageWritable,FailedJobs,Reverb,SchedulerHeartbeat,QueueWorker,DiscordApi,DiscordGatewaySidecar,MumbleSidecar,TeamSpeakSidecar,Pelican,MusicAssistant}Check.php`
- `app/Modules/Preflight/Console/{Preflight,Heartbeat,HealthWatch}Command.php`
- `app/Modules/Preflight/Jobs/QueueHeartbeatJob.php`
- `app/Modules/Preflight/Notifications/SystemUnhealthy.php`
- `app/Modules/Preflight/Filament/Widgets/PreflightStatusWidget.php`
- `resources/views/filament/widgets/preflight-status.blade.php`
- `lang/de/preflight.php`
- `tests/Feature/Preflight/{RunPreflight,InternalChecks,Heartbeat,ExternalChecks,PreflightCommand,HealthWatch}Test.php`

**Modify:**
- `app/Providers/AppServiceProvider.php` (tag checks + bind `RunPreflight` — where the other client bindings live)
- `bootstrap/app.php` (`withCommands`: add `app/Modules/Preflight/Console`)
- `routes/console.php` (schedule `lanomat:heartbeat` + `lanomat:health-watch`)
- `app/Providers/Filament/AdminPanelProvider.php` (register the widget)
- `docs/pre-lan-acceptance-checklist.md`, `docs/prod-test.md`

---

### Task 1: Health-check core (contract, result, registry) + wiring

**Files:**
- Create: `Enums/HealthStatus.php`, `Support/HealthResult.php`, `Contracts/HealthCheck.php`, `Actions/RunPreflight.php`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`
- Test: `tests/Feature/Preflight/RunPreflightTest.php`

**Interfaces:**
- Produces: `HealthStatus` (enum `ok|warn|down|skipped`); `HealthResult{status,message}` + static `ok/warn/down/skipped(string $message='')`; `HealthCheck` interface (`key():string`, `label():string`, `run():HealthResult`); `RunPreflight::handle(): list<array{key:string,label:string,status:string,message:string}>` — resolves tagged `preflight.checks`, runs each in try/catch (throw → `down`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/RunPreflightTest.php`:
```php
<?php

use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

function fakeCheck(string $key, callable $run): HealthCheck
{
    return new class($key, $run) implements HealthCheck {
        public function __construct(private string $k, private $run) {}
        public function key(): string { return $this->k; }
        public function label(): string { return ucfirst($this->k); }
        public function run(): HealthResult { return ($this->run)(); }
    };
}

it('collects results from all checks', function () {
    $run = new RunPreflight([
        fakeCheck('a', fn () => HealthResult::ok('fine')),
        fakeCheck('b', fn () => HealthResult::skipped('n/a')),
    ]);

    expect($run->handle())->toBe([
        ['key' => 'a', 'label' => 'A', 'status' => 'ok', 'message' => 'fine'],
        ['key' => 'b', 'label' => 'B', 'status' => 'skipped', 'message' => 'n/a'],
    ]);
});

it('reports a throwing check as down instead of propagating', function () {
    $run = new RunPreflight([
        fakeCheck('boom', fn () => throw new RuntimeException('kaputt')),
    ]);

    $result = $run->handle();
    expect($result[0]['status'])->toBe('down')
        ->and($result[0]['message'])->toBe('kaputt');
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=RunPreflight`)

- [ ] **Step 3: Implement core**

`app/Modules/Preflight/Enums/HealthStatus.php`:
```php
<?php

namespace App\Modules\Preflight\Enums;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Down = 'down';
    case Skipped = 'skipped';
}
```

`app/Modules/Preflight/Support/HealthResult.php`:
```php
<?php

namespace App\Modules\Preflight\Support;

use App\Modules\Preflight\Enums\HealthStatus;

final class HealthResult
{
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $message = '',
    ) {}

    public static function ok(string $message = ''): self { return new self(HealthStatus::Ok, $message); }
    public static function warn(string $message = ''): self { return new self(HealthStatus::Warn, $message); }
    public static function down(string $message = ''): self { return new self(HealthStatus::Down, $message); }
    public static function skipped(string $message = ''): self { return new self(HealthStatus::Skipped, $message); }
}
```

`app/Modules/Preflight/Contracts/HealthCheck.php`:
```php
<?php

namespace App\Modules\Preflight\Contracts;

use App\Modules\Preflight\Support\HealthResult;

interface HealthCheck
{
    /** Stable machine id, e.g. 'database'. */
    public function key(): string;

    /** German UI label (via lang/de/preflight.php). */
    public function label(): string;

    public function run(): HealthResult;
}
```

`app/Modules/Preflight/Actions/RunPreflight.php`:
```php
<?php

namespace App\Modules\Preflight\Actions;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Throwable;

/**
 * Runs every registered {@see HealthCheck} and flattens the results. A check
 * that throws is reported `down` (with its message), never propagated — the
 * ampel must always render.
 */
final class RunPreflight
{
    /** @param iterable<HealthCheck> $checks */
    public function __construct(private readonly iterable $checks) {}

    /**
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function handle(): array
    {
        $out = [];

        foreach ($this->checks as $check) {
            try {
                $result = $check->run();
            } catch (Throwable $e) {
                $result = HealthResult::down($e->getMessage());
            }

            $out[] = [
                'key' => $check->key(),
                'label' => $check->label(),
                'status' => $result->status->value,
                'message' => $result->message,
            ];
        }

        return $out;
    }
}
```

`app/Providers/AppServiceProvider.php` — in `register()`, alongside the existing client bindings, bind the action against an (initially empty) `preflight.checks` tag. **The tag starts empty on purpose** — each later check task (Tasks 2, 3, 4) appends its own check classes to this array as it creates them. Referencing a not-yet-created `::class` here would fail phpstan level 8 ("class not found"), so do **not** pre-list them:
```php
// Preflight health checks are tagged here; each Checks/* class is added to
// this array by the task that creates it (see the preflight plan Tasks 2-4).
$this->app->tag([], 'preflight.checks');

$this->app->bind(
    \App\Modules\Preflight\Actions\RunPreflight::class,
    fn ($app) => new \App\Modules\Preflight\Actions\RunPreflight($app->tagged('preflight.checks')),
);
```
(Task 1's `RunPreflightTest` constructs `RunPreflight` directly with fake checks, so an empty tag is correct at this task.)

`bootstrap/app.php` — add to the `withCommands([...])` list:
```php
            __DIR__.'/../app/Modules/Preflight/Console',
```

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=RunPreflight`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight/Enums app/Modules/Preflight/Support app/Modules/Preflight/Contracts app/Modules/Preflight/Actions app/Providers/AppServiceProvider.php bootstrap/app.php tests/Feature/Preflight/RunPreflightTest.php
git commit -m "feat(preflight): health-check contract, result, and registry"
```

---

### Task 2: Internal checks (database, redis, storage, failed_jobs, reverb)

**Files:**
- Create: `Checks/DatabaseCheck.php`, `RedisCheck.php`, `StorageWritableCheck.php`, `FailedJobsCheck.php`, `ReverbCheck.php`
- Create/append: `lang/de/preflight.php` (labels)
- Test: `tests/Feature/Preflight/InternalChecksTest.php`

**Interfaces:**
- Consumes: `HealthCheck`, `HealthResult`.
- Produces: five checks with keys `database`, `redis`, `storage`, `failed_jobs`, `reverb`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/InternalChecksTest.php`:
```php
<?php

use App\Modules\Preflight\Checks\DatabaseCheck;
use App\Modules\Preflight\Checks\FailedJobsCheck;
use App\Modules\Preflight\Checks\StorageWritableCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reports the database as ok when reachable', function () {
    expect(app(DatabaseCheck::class)->run()->status->value)->toBe('ok');
});

it('reports storage ok when the default disk is writable', function () {
    expect(app(StorageWritableCheck::class)->run()->status->value)->toBe('ok');
});

it('warns when failed_jobs is non-empty, ok when empty', function () {
    expect(app(FailedJobsCheck::class)->run()->status->value)->toBe('ok');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    expect(app(FailedJobsCheck::class)->run()->status->value)->toBe('warn');
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=InternalChecks`)

- [ ] **Step 3: Implement the five checks + labels**

`app/Modules/Preflight/Checks/DatabaseCheck.php`:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseCheck implements HealthCheck
{
    public function key(): string { return 'database'; }
    public function label(): string { return __('preflight.checks.database'); }

    public function run(): HealthResult
    {
        try {
            DB::connection()->select('select 1');

            return HealthResult::ok();
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
```

`RedisCheck.php` (same shape; `Illuminate\Support\Facades\Redis::connection()->ping()` in the try; key `redis`).

`StorageWritableCheck.php`:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageWritableCheck implements HealthCheck
{
    public function key(): string { return 'storage'; }
    public function label(): string { return __('preflight.checks.storage'); }

    public function run(): HealthResult
    {
        $probe = 'preflight/.write-'.uniqid();

        try {
            Storage::put($probe, 'ok');
            $ok = Storage::get($probe) === 'ok';
            Storage::delete($probe);

            return $ok ? HealthResult::ok() : HealthResult::down(__('preflight.messages.storage_mismatch'));
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
```

`FailedJobsCheck.php`:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\DB;

class FailedJobsCheck implements HealthCheck
{
    public function key(): string { return 'failed_jobs'; }
    public function label(): string { return __('preflight.checks.failed_jobs'); }

    public function run(): HealthResult
    {
        $count = DB::table('failed_jobs')->count();

        return $count === 0
            ? HealthResult::ok()
            : HealthResult::warn(__('preflight.messages.failed_jobs', ['count' => $count]));
    }
}
```

`ReverbCheck.php` — `skipped` unless broadcasting is reverb; else TCP-connect to the reverb connection host/port:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class ReverbCheck implements HealthCheck
{
    public function key(): string { return 'reverb'; }
    public function label(): string { return __('preflight.checks.reverb'); }

    public function run(): HealthResult
    {
        if (config('broadcasting.default') !== 'reverb') {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $options = config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? '127.0.0.1';
        $port = (int) ($options['port'] ?? 8080);

        $socket = @fsockopen($host, $port, $errno, $errstr, 3.0);

        if ($socket === false) {
            return HealthResult::down("{$host}:{$port} — {$errstr}");
        }

        fclose($socket);

        return HealthResult::ok();
    }
}
```

`lang/de/preflight.php` (create; append keys in later tasks):
```php
<?php

return [
    'checks' => [
        'database' => 'Datenbank',
        'redis' => 'Redis',
        'storage' => 'Dateispeicher',
        'failed_jobs' => 'Fehlgeschlagene Jobs',
        'reverb' => 'Reverb (Echtzeit)',
    ],
    'messages' => [
        'not_configured' => 'Nicht konfiguriert.',
        'storage_mismatch' => 'Schreibprobe stimmte nicht überein.',
        'failed_jobs' => ':count fehlgeschlagene(r) Job(s).',
    ],
];
```

Then append these five classes to the `preflight.checks` tag array in `app/Providers/AppServiceProvider.php` (`register()`): `DatabaseCheck`, `RedisCheck`, `StorageWritableCheck`, `FailedJobsCheck`, `ReverbCheck` (fully-qualified). Add `app/Providers/AppServiceProvider.php` to this task's modified files.

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=InternalChecks`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight/Checks lang/de/preflight.php tests/Feature/Preflight/InternalChecksTest.php
git commit -m "feat(preflight): internal checks (database, redis, storage, failed_jobs, reverb)"
```

---

### Task 3: Liveness heartbeat + scheduler/queue-worker checks

**Files:**
- Create: `Console/HeartbeatCommand.php`, `Jobs/QueueHeartbeatJob.php`, `Checks/SchedulerHeartbeatCheck.php`, `Checks/QueueWorkerCheck.php`
- Modify: `routes/console.php`, `lang/de/preflight.php`
- Test: `tests/Feature/Preflight/HeartbeatTest.php`

**Interfaces:**
- Produces: `lanomat:heartbeat` (writes `cache('preflight.scheduler_tick')`, dispatches `QueueHeartbeatJob` which writes `cache('preflight.queue_tick')`); checks `scheduler` + `queue_worker` (`ok` if marker within 2 min, else `down`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/HeartbeatTest.php`:
```php
<?php

use App\Modules\Preflight\Checks\QueueWorkerCheck;
use App\Modules\Preflight\Checks\SchedulerHeartbeatCheck;
use App\Modules\Preflight\Jobs\QueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('scheduler check is down without a marker, ok with a fresh one', function () {
    Cache::forget('preflight.scheduler_tick');
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('down');

    Cache::put('preflight.scheduler_tick', now(), now()->addMinutes(10));
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('ok');
});

it('queue check is down when the marker is stale', function () {
    Cache::put('preflight.queue_tick', now()->subMinutes(5), now()->addMinutes(10));
    expect(app(QueueWorkerCheck::class)->run()->status->value)->toBe('down');
});

it('heartbeat command writes the scheduler marker and queues the worker ping', function () {
    Queue::fake();
    Cache::forget('preflight.scheduler_tick');

    $this->artisan('lanomat:heartbeat')->assertOk();

    expect(Cache::has('preflight.scheduler_tick'))->toBeTrue();
    Queue::assertPushed(QueueHeartbeatJob::class);
});

it('the queue heartbeat job writes its marker', function () {
    Cache::forget('preflight.queue_tick');
    (new QueueHeartbeatJob)->handle();
    expect(Cache::has('preflight.queue_tick'))->toBeTrue();
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=Heartbeat`)

- [ ] **Step 3: Implement command, job, checks**

`app/Modules/Preflight/Jobs/QueueHeartbeatJob.php`:
```php
<?php

namespace App\Modules\Preflight\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Proves a worker is consuming the queue by stamping a cache marker. */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('preflight.queue_tick', now(), now()->addMinutes(10));
    }
}
```

`app/Modules/Preflight/Console/HeartbeatCommand.php`:
```php
<?php

namespace App\Modules\Preflight\Console;

use App\Modules\Preflight\Jobs\QueueHeartbeatJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class HeartbeatCommand extends Command
{
    protected $signature = 'lanomat:heartbeat';
    protected $description = 'Writes scheduler + queue-worker liveness markers for preflight.';

    public function handle(): int
    {
        Cache::put('preflight.scheduler_tick', now(), now()->addMinutes(10));
        QueueHeartbeatJob::dispatch();

        return self::SUCCESS;
    }
}
```

`app/Modules/Preflight/Checks/SchedulerHeartbeatCheck.php`:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeatCheck implements HealthCheck
{
    public function key(): string { return 'scheduler'; }
    public function label(): string { return __('preflight.checks.scheduler'); }

    public function run(): HealthResult
    {
        $tick = Cache::get('preflight.scheduler_tick');

        if (! $tick instanceof Carbon || $tick->lt(now()->subMinutes(2))) {
            return HealthResult::down(__('preflight.messages.stale'));
        }

        return HealthResult::ok();
    }
}
```

`QueueWorkerCheck.php` — identical shape, key `queue_worker`, label `preflight.checks.queue_worker`, reading `preflight.queue_tick`.

`routes/console.php` — add:
```php
Schedule::command('lanomat:heartbeat')->everyMinute();
```

`lang/de/preflight.php` — add under `checks`: `'scheduler' => 'Scheduler', 'queue_worker' => 'Queue-Worker',` and under `messages`: `'stale' => 'Kein aktuelles Lebenszeichen.',`.

Then append `SchedulerHeartbeatCheck` and `QueueWorkerCheck` to the `preflight.checks` tag array in `app/Providers/AppServiceProvider.php`. Add that file to this task's modified files.

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=Heartbeat`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight routes/console.php lang/de/preflight.php tests/Feature/Preflight/HeartbeatTest.php
git commit -m "feat(preflight): liveness heartbeat and scheduler/queue-worker checks"
```

---

### Task 4: External checks (Mode-A tolerant)

**Files:**
- Create: `Checks/DiscordApiCheck.php`, `DiscordGatewaySidecarCheck.php`, `MumbleSidecarCheck.php`, `TeamSpeakSidecarCheck.php`, `PelicanCheck.php`, `MusicAssistantCheck.php`
- Modify: `lang/de/preflight.php`, `config/services.php` (add `discord.gateway_health_url`), `.env.example`
- Test: `tests/Feature/Preflight/ExternalChecksTest.php`

**Interfaces:**
- Produces: six checks, each `skipped` when its config is absent, `ok` on a 2xx probe, `down` on error/timeout. Keys: `discord_api`, `discord_gateway`, `mumble`, `teamspeak`, `pelican`, `music_assistant`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/ExternalChecksTest.php`:
```php
<?php

use App\Modules\Preflight\Checks\DiscordApiCheck;
use App\Modules\Preflight\Checks\PelicanCheck;
use Illuminate\Support\Facades\Http;

it('skips the discord api check when no bot token is configured', function () {
    config(['services.discord.bot_token' => null]);
    expect(app(DiscordApiCheck::class)->run()->status->value)->toBe('skipped');
});

it('reports discord api ok on 200 and down on error', function () {
    config(['services.discord.bot_token' => 'tok']);

    Http::fake(['discord.com/*' => Http::response(['username' => 'LANoMAT'], 200)]);
    expect(app(DiscordApiCheck::class)->run()->status->value)->toBe('ok');

    Http::fake(['discord.com/*' => Http::response('', 500)]);
    expect(app(DiscordApiCheck::class)->run()->status->value)->toBe('down');
});

it('skips pelican when panel url is unset, down when unreachable', function () {
    config(['services.pelican.panel_url' => null]);
    expect(app(PelicanCheck::class)->run()->status->value)->toBe('skipped');

    config(['services.pelican.panel_url' => 'https://pelican.test']);
    Http::fake(['pelican.test/*' => Http::response('', 502)]);
    expect(app(PelicanCheck::class)->run()->status->value)->toBe('down');
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=ExternalChecks`)

- [ ] **Step 3: Implement the external checks**

`app/Modules/Preflight/Checks/DiscordApiCheck.php`:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class DiscordApiCheck implements HealthCheck
{
    public function key(): string { return 'discord_api'; }
    public function label(): string { return __('preflight.checks.discord_api'); }

    public function run(): HealthResult
    {
        $token = config('services.discord.bot_token');

        if (blank($token)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Bot {$token}"])
                ->timeout(3)
                ->get('https://discord.com/api/v10/users/@me');

            return $response->successful()
                ? HealthResult::ok()
                : HealthResult::down("HTTP {$response->status()}");
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
```

The other five follow the same pattern — a `reachable()` helper keeps them DRY. Add a small trait `app/Modules/Preflight/Checks/ProbesHttp.php`:
```php
<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

trait ProbesHttp
{
    /**
     * `skipped` if $configuredUrl is blank; `ok` if the URL responds at all
     * (any HTTP status = reachable) within the timeout; else `down`. A bare
     * reachability probe — real auth/wire correctness is the acceptance
     * checklist's job, not preflight's.
     */
    private function probe(?string $configuredUrl): HealthResult
    {
        if (blank($configuredUrl)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            Http::timeout(3)->get($configuredUrl);

            return HealthResult::ok();
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
```
Then:
- `MumbleSidecarCheck` (key `mumble`): `skipped` unless `in_array('mumble', config('services.voice.providers'))`; else `$this->probe(config('services.mumble.rest_url'))`.
- `TeamSpeakSidecarCheck` (key `teamspeak`): `skipped` unless `in_array('teamspeak', config('services.voice.providers'))`; else `probe(config('services.teamspeak.rest_url'))`.
- `PelicanCheck` (key `pelican`): `probe(config('services.pelican.panel_url'))`.
- `MusicAssistantCheck` (key `music_assistant`): `skipped` if `blank(config('services.music_assistant.token'))`; else `probe(config('services.music_assistant.base_url'))`.
- `DiscordGatewaySidecarCheck` (key `discord_gateway`): `probe(config('services.discord.gateway_health_url'))`.

> Note: `Http::timeout(3)->get()` throwing on a **fake** 5xx requires the test to use `Http::fake([... => Http::response('', 500)])` and the check to treat non-2xx as `down`. For the bare `probe()` helper a non-2xx still means "reachable" → `ok`; the Pelican test above therefore asserts `down` only on a connection exception. Adjust the Pelican test to fake a `ConnectionException` (`Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'))`) so "unreachable → down" is what's asserted, and keep a separate 2xx case for `ok`.

`config/services.php` — in the `discord` block add:
```php
        'gateway_health_url' => env('DISCORD_GATEWAY_HEALTH_URL'),
```
`.env.example` — add `DISCORD_GATEWAY_HEALTH_URL=` near the other `DISCORD_*` keys.

`lang/de/preflight.php` — add labels: `'discord_api' => 'Discord-API', 'discord_gateway' => 'Discord-Gateway-Bot', 'mumble' => 'Mumble', 'teamspeak' => 'TeamSpeak', 'pelican' => 'Pelican (Gameserver)', 'music_assistant' => 'Music Assistant'`.

Then append the six external check classes to the `preflight.checks` tag array in `app/Providers/AppServiceProvider.php`: `DiscordApiCheck`, `DiscordGatewaySidecarCheck`, `MumbleSidecarCheck`, `TeamSpeakSidecarCheck`, `PelicanCheck`, `MusicAssistantCheck`.

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=ExternalChecks`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight config/services.php .env.example lang/de/preflight.php tests/Feature/Preflight/ExternalChecksTest.php
git commit -m "feat(preflight): external system checks (discord, voice sidecars, pelican, music assistant)"
```

---

### Task 5: `lanomat:preflight` command (ampel + exit code)

**Files:**
- Create: `Console/PreflightCommand.php`
- Test: `tests/Feature/Preflight/PreflightCommandTest.php`

**Interfaces:**
- Consumes: `RunPreflight`.
- Produces: `lanomat:preflight` — a status table; exit 1 if any `down`, else 0.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/PreflightCommandTest.php`:
```php
<?php

use App\Modules\Preflight\Actions\RunPreflight;

it('exits 0 when nothing is down', function () {
    $this->mock(RunPreflight::class)->shouldReceive('handle')->andReturn([
        ['key' => 'database', 'label' => 'Datenbank', 'status' => 'ok', 'message' => ''],
        ['key' => 'pelican', 'label' => 'Pelican', 'status' => 'skipped', 'message' => 'Nicht konfiguriert.'],
    ]);

    $this->artisan('lanomat:preflight')->assertExitCode(0);
});

it('exits 1 when a check is down', function () {
    $this->mock(RunPreflight::class)->shouldReceive('handle')->andReturn([
        ['key' => 'redis', 'label' => 'Redis', 'status' => 'down', 'message' => 'refused'],
    ]);

    $this->artisan('lanomat:preflight')->assertExitCode(1);
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=PreflightCommand`)

- [ ] **Step 3: Implement the command**

`app/Modules/Preflight/Console/PreflightCommand.php`:
```php
<?php

namespace App\Modules\Preflight\Console;

use App\Modules\Preflight\Actions\RunPreflight;
use Illuminate\Console\Command;

class PreflightCommand extends Command
{
    protected $signature = 'lanomat:preflight';
    protected $description = 'Probe every internal + external system and report a traffic-light status.';

    private const GLYPH = ['ok' => '<fg=green>●</> OK', 'warn' => '<fg=yellow>●</> WARN', 'down' => '<fg=red>●</> DOWN', 'skipped' => '<fg=gray>○</> SKIP'];

    public function handle(RunPreflight $run): int
    {
        $results = $run->handle();

        $this->table(
            ['System', 'Status', 'Info'],
            array_map(fn (array $r): array => [
                $r['label'],
                self::GLYPH[$r['status']] ?? $r['status'],
                $r['message'],
            ], $results),
        );

        $down = array_filter($results, fn (array $r): bool => $r['status'] === 'down');

        if ($down !== []) {
            $this->error(count($down).' system(s) down.');

            return self::FAILURE;
        }

        $this->info('Preflight ok.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=PreflightCommand`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight/Console/PreflightCommand.php tests/Feature/Preflight/PreflightCommandTest.php
git commit -m "feat(preflight): lanomat:preflight ampel command with exit code"
```

---

### Task 6: Filament dashboard status widget

**Files:**
- Create: `app/Modules/Preflight/Filament/Widgets/PreflightStatusWidget.php`, `resources/views/filament/widgets/preflight-status.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (register the widget)
- Test: `tests/Feature/Preflight/PreflightWidgetTest.php`

**Interfaces:**
- Consumes: `RunPreflight` (cached 15 s under `preflight.results`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/PreflightWidgetTest.php`:
```php
<?php

use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Filament\Widgets\PreflightStatusWidget;
use Illuminate\Support\Facades\Cache;

it('exposes cached preflight results to the view', function () {
    Cache::flush();
    $this->mock(RunPreflight::class)->shouldReceive('handle')->once()->andReturn([
        ['key' => 'database', 'label' => 'Datenbank', 'status' => 'ok', 'message' => ''],
    ]);

    $widget = new PreflightStatusWidget;

    expect($widget->results())->toHaveCount(1)
        ->and($widget->results()[0]['status'])->toBe('ok')
        // second call is served from cache (mock expects exactly once)
        ->and($widget->results())->toHaveCount(1);
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=PreflightWidget`)

- [ ] **Step 3: Implement the widget + view + registration**

`app/Modules/Preflight/Filament/Widgets/PreflightStatusWidget.php`:
```php
<?php

namespace App\Modules\Preflight\Filament\Widgets;

use App\Modules\Preflight\Actions\RunPreflight;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class PreflightStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.preflight-status';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function results(): array
    {
        return Cache::remember('preflight.results', now()->addSeconds(15), fn () => app(RunPreflight::class)->handle());
    }

    public function refresh(): void
    {
        Cache::forget('preflight.results');
    }
}
```

`resources/views/filament/widgets/preflight-status.blade.php` — a Signalpult-compliant panel: a heading, a "Neu prüfen" button (`wire:click="refresh"`), and a list of `results()` rows with a status dot (semantic colours: ok = success/green, warn = the amber signal, down = destructive/red, skipped = muted) and the message in mono. (Use the panel's existing Tailwind tokens; no raw hex.)

`app/Providers/Filament/AdminPanelProvider.php` — add to the `->widgets([...])` array:
```php
                \App\Modules\Preflight\Filament\Widgets\PreflightStatusWidget::class,
```

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=PreflightWidget`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight/Filament resources/views/filament/widgets/preflight-status.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Preflight/PreflightWidgetTest.php
git commit -m "feat(preflight): admin dashboard status widget"
```

---

### Task 7: Scheduled orga bell (`lanomat:health-watch`)

**Files:**
- Create: `Console/HealthWatchCommand.php`, `Notifications/SystemUnhealthy.php`
- Modify: `routes/console.php`, `lang/de/preflight.php` (+ `lang/de/notifications.php` if that's where notification copy lives)
- Test: `tests/Feature/Preflight/HealthWatchTest.php`

**Interfaces:**
- Consumes: `RunPreflight`, the Notifications bell pattern (`Notification::send($orgas, new SystemUnhealthy(...))`, `toDatabase` → `['category'=>'system','title','body']`), orga scoping `User::whereIn('role', [Role::Orga, Role::Admin])`.
- Produces: `lanomat:health-watch` — edge-triggered (state cached in `preflight.watch_state`); bells on any `down` or `failed_jobs > 0`; `skipped` never bells.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Preflight/HealthWatchTest.php`:
```php
<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Notifications\SystemUnhealthy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('bells orga once on a healthy->unhealthy transition, not again while still down', function () {
    Cache::flush();
    Notification::fake();
    $orga = User::factory()->create(['role' => Role::Orga]);
    $this->mock(RunPreflight::class)->shouldReceive('handle')->andReturn([
        ['key' => 'redis', 'label' => 'Redis', 'status' => 'down', 'message' => 'refused'],
    ]);

    $this->artisan('lanomat:health-watch')->assertOk();
    Notification::assertSentTo($orga, SystemUnhealthy::class);

    Notification::fake(); // reset
    $this->artisan('lanomat:health-watch')->assertOk();
    Notification::assertNothingSent(); // edge-triggered: still down, no repeat
});

it('does not bell when only skipped/ok', function () {
    Cache::flush();
    Notification::fake();
    User::factory()->create(['role' => Role::Orga]);
    $this->mock(RunPreflight::class)->shouldReceive('handle')->andReturn([
        ['key' => 'pelican', 'label' => 'Pelican', 'status' => 'skipped', 'message' => ''],
        ['key' => 'database', 'label' => 'Datenbank', 'status' => 'ok', 'message' => ''],
    ]);

    $this->artisan('lanomat:health-watch')->assertOk();
    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run — expect FAIL** (`./vendor/bin/pest --filter=HealthWatch`)

- [ ] **Step 3: Implement the notification + command + schedule**

`app/Modules/Preflight/Notifications/SystemUnhealthy.php`:
```php
<?php

namespace App\Modules\Preflight\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemUnhealthy extends Notification
{
    use Queueable;

    /** @param list<string> $downLabels */
    public function __construct(public readonly array $downLabels) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array { return ['database']; }

    /** @return array<string, string> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'system',
            'title' => __('preflight.bell.title'),
            'body' => __('preflight.bell.body', ['systems' => implode(', ', $this->downLabels)]),
        ];
    }
}
```

`app/Modules/Preflight/Console/HealthWatchCommand.php`:
```php
<?php

namespace App\Modules\Preflight\Console;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Notifications\SystemUnhealthy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class HealthWatchCommand extends Command
{
    protected $signature = 'lanomat:health-watch';
    protected $description = 'Bell the orga when a system is down or jobs have failed (edge-triggered).';

    public function handle(RunPreflight $run): int
    {
        $results = $run->handle();

        $downLabels = array_values(array_map(
            fn (array $r): string => $r['label'],
            array_filter($results, fn (array $r): bool => $r['status'] === 'down' || ($r['key'] === 'failed_jobs' && $r['status'] === 'warn')),
        ));

        $unhealthy = $downLabels !== [];
        $wasUnhealthy = (bool) Cache::get('preflight.watch_state', false);
        Cache::put('preflight.watch_state', $unhealthy, now()->addDay());

        if ($unhealthy && ! $wasUnhealthy) {
            $orgas = User::query()->whereIn('role', [Role::Orga, Role::Admin])->get();
            Notification::send($orgas, new SystemUnhealthy($downLabels));
        }

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add:
```php
Schedule::command('lanomat:health-watch')->everyFiveMinutes();
```

`lang/de/preflight.php` — add:
```php
    'bell' => [
        'title' => 'Systemwarnung',
        'body' => 'Diese Systeme brauchen Aufmerksamkeit: :systems.',
    ],
```

- [ ] **Step 4: Run — expect PASS** (`./vendor/bin/pest --filter=HealthWatch`)

- [ ] **Step 5: composer check + commit**

```bash
git add app/Modules/Preflight routes/console.php lang/de/preflight.php tests/Feature/Preflight/HealthWatchTest.php
git commit -m "feat(preflight): scheduled edge-triggered orga bell on down/failed jobs"
```

---

### Task 8: Docs

> **Finalization note:** docs-only. Do NOT merge/tag/close — the controller finalizes after the whole-branch review.

**Files:**
- Modify: `docs/pre-lan-acceptance-checklist.md`, `docs/prod-test.md`

- [ ] **Step 1: Update the acceptance checklist**

In `docs/pre-lan-acceptance-checklist.md` §8, replace the "Preflight ampel (1.2)" *to-build* bullet with a *built* note: run `php artisan lanomat:preflight` (ampel + non-zero exit), the admin dashboard tile, and the scheduled `lanomat:health-watch` orga bell; heartbeat via `lanomat:heartbeat`.

- [ ] **Step 2: Add a prod-test note**

In `docs/prod-test.md`, add a short "LAN-morning readiness" step: `php artisan lanomat:preflight` should be all green (external Mode-A systems `SKIP` until deployed); check the admin dashboard tile.

- [ ] **Step 3: Commit**

```bash
git add docs/pre-lan-acceptance-checklist.md docs/prod-test.md
git commit -m "docs(preflight): document the preflight ampel in the acceptance checklist and prod-test"
```

---

## Self-Review

**1. Spec coverage:** §4 core → Task 1. §5 internal checks → Tasks 2 (db/redis/storage/failed_jobs/reverb) + 3 (scheduler/queue_worker). §5 external checks → Task 4. §6.1 CLI → Task 5. §6.2 widget → Task 6. §6.3 bell → Task 7. §7 heartbeat → Task 3. §8 config (`gateway_health_url`) → Task 4. §9 testing → each task. §10 docs → Task 8. §11 decisions (failed_jobs=warn; skipped never bells; cadences) → Tasks 2/7/3. No gaps.

**2. Placeholder scan:** No TBD/TODO. The sibling checks in Tasks 2/4 are described with their exact key/config/label rather than repeating a near-identical full class body — each is a complete, unambiguous spec (the shared `ProbesHttp` trait + the one full `DiscordApiCheck`/`DatabaseCheck` example give the exact shape). The blade view is described by its required elements + the semantic-token rule rather than pasted markup (it is presentational and bound by `docs/design.md`).

**3. Type consistency:** `HealthResult::{ok,warn,down,skipped}` and `HealthStatus` values (`ok|warn|down|skipped`) are used identically across all checks, the command, the widget, and `RunPreflight`'s return shape `{key,label,status,message}` (string status). Cache keys (`preflight.scheduler_tick`, `preflight.queue_tick`, `preflight.results`, `preflight.watch_state`) are consistent between writers (Task 3/6/7) and readers (Tasks 3/6/7). `SystemUnhealthy(array $downLabels)` matches its test. Command signatures (`lanomat:preflight|heartbeat|health-watch`) match the scheduler entries + tests.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-22-preflight-ampel.md`. Two execution options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks.
2. **Inline Execution** — execute tasks in this session with checkpoints.

Which approach?
