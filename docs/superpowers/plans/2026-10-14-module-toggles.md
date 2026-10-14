# Module toggles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a deployment switch optional modules on/off via `config/modules.php` (`MODULES_PROFILE=core|full` + `MODULE_<KEY>` overrides), so a fresh install boots with only the core modules.

**Architecture:** A `Module` backed enum carries static metadata (core flag, hard `requires`); a `Modules` singleton resolves enabled state from config and validates it at boot. Toggles gate **entry points only** (routes, broadcast channels, Filament resources, scheduler entries, listeners, policies, Preflight probes, frontend nav) — migrations, models and container bindings stay unconditional, so a disabled module can never cause a class-not-found or missing-table error. Core→optional runtime calls get explicit guards.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5, Inertia v2 + Vue 3, Laravel Wayfinder, Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-14-module-toggles-design.md`

## Global Constraints

- Default profile is `core`; `MODULES_PROFILE=full` must behave **identically to today** — the existing suite runs under `full` (set in `phpunit.xml`) and must stay green after every task.
- Core modules (cannot be disabled): Events, Registration, Seating, Teams, Games, Tournaments, Identity, Notifications, Preflight. Discord is **optional**; Discord *login* (Socialite/Fortify in `AppServiceProvider`/Identity) stays always on.
- A disabled module's HTTP routes are **not registered** → 404 (never 403).
- No `mixed` returns in own code, enums over magic strings, PHPStan level 8, Pint (Laravel preset).
- Vue: `<script setup lang="ts">`, no `<style>` blocks; hiding nav must not add visual elements (`docs/design.md`).
- UI copy German (`lang/de/`), code/comments/commits/docs English. Conventional Commits, each commit ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Frontend gate is **all four**: `npm run types:check`, `lint:check`, `format:check`, `build`. Backend gate: `composer check` (pint, phpstan, pest with `-d memory_limit=1G`).
- Workflow per task/PR: code review → fix → commit → push → PR → merge (user's standing rule). No `--parallel` Pest.
- **Deviations from the spec (intentional, simplifications found while reading the code; Task 8 amends the spec):**
  1. Container **bindings stay unconditional** (they are lazy and `Identity` resolves `VoiceProviders` regardless); only listeners, policies/gates, scheduler, probes, routes, channels, Filament are gated.
  2. Artisan **commands stay registered** (`withCommands` runs before config exists); their *scheduler entries* are gated. Running a disabled module's command by hand is harmless.
  3. Static metadata (`core`, `requires`) lives in the `Module` enum, not in `config/modules.php`; the config carries only `profile` + `overrides`.
- **Wayfinder trap:** the frontend imports generated route helpers (`@/routes/jukebox`…). Wayfinder generates them from *registered* routes, and it runs in dev (Vite plugin), CI and the Docker build (`cp .env.example .env` → `core`). Therefore `Modules::routable()` returns `true` for every module while `php artisan wayfinder:generate` runs. Never gate route *generation*, only *serving*.

## Review Focus

1. Core profile + Wayfinder: the frontend must still build and type-check when `.env` says `core` (generation sees all routes) — Task 1 test + Task 8 CI smoke.
2. An optional module enabled **alone** (core + that module + its `requires`) must boot, serve `/`, and load `/admin` without exceptions — Task 8 isolation matrix over all 20 optional modules.
3. `MODULE_<KEY>=false` for a core module, a bad `MODULES_PROFILE` value, and an enabled module whose `requires` is off must fail at boot with **every** problem listed — Task 1.
4. Config caching: `php artisan config:cache` with `MODULES_PROFILE=core` must yield the same module set (no `env()` outside config) — Task 1.
5. Disabled-module pages reached by old links/bookmarks return 404, and no core page 500s because a core→optional lookup returned nothing — Tasks 6 and 8.

---

## File map

| File | Responsibility |
|---|---|
| `app/Support/Modules/Module.php` (new) | Enum of the 29 modules + `isCore()`, `requires()`, `key()` |
| `app/Support/Modules/Modules.php` (new) | Resolve + validate enabled set; `enabled()`, `routable()`, `enabledModules()` |
| `app/Support/Modules/InvalidModuleConfiguration.php` (new) | Exception listing all violations |
| `app/Providers/ModulesServiceProvider.php` (new) | Singleton binding; registered **first** |
| `config/modules.php` (new) | `profile` + per-module `overrides` from env |
| `app/Console/Commands/ModulesCommand.php` (new) | `lanomat:modules [--json]` introspection (operator tool + test oracle) |
| `app/Providers/Filament/AdminPanelProvider.php` | Resource discovery loop over enabled modules |
| `routes/web.php`, `routes/channels.php` | Per-module gated route groups |
| `routes/console.php` | Per-module gated scheduler entries |
| `app/Providers/AppServiceProvider.php` | Gated policies/gates/listeners/Preflight tags |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share `modules` prop |
| `resources/js/composables/useModules.ts` (new) | `has(key)` helper |
| `resources/js/components/AppSidebar.vue`, `layouts/PublicShell.vue`, `pages/Event/Show.vue`, `pages/Profile/Show.vue` | Hide links of disabled modules |
| `tests/Feature/Modules/*` (new), `tests/Architecture/*` | Tests + ratchet #2 |
| `phpunit.xml`, `.env.testing`, `.env.example`, `.github/workflows/ci.yml` | Profile pinning, docs, CI smoke |

---

### Task 1: `Module` enum, `Modules` service, config, provider, introspection command

**Files:**
- Create: `app/Support/Modules/Module.php`, `app/Support/Modules/Modules.php`, `app/Support/Modules/InvalidModuleConfiguration.php`, `app/Providers/ModulesServiceProvider.php`, `config/modules.php`, `app/Console/Commands/ModulesCommand.php`
- Modify: `bootstrap/providers.php`, `phpunit.xml`, `.env.testing`
- Test: `tests/Unit/Modules/ModulesTest.php`, `tests/Feature/Modules/ModulesCommandTest.php`, `tests/Feature/Modules/ModulesProcess.php` (helper)

**Interfaces:**
- Produces: `App\Support\Modules\Module` (cases = module directory names, `->value` snake_case key, `isCore(): bool`, `requires(): list<Module>`, `key(): string`); `App\Support\Modules\Modules` with `__construct(string $profile, array $overrides = [], bool $exposeAllRoutes = false)`, `enabled(Module): bool`, `routable(Module): bool`, `enabledModules(): list<Module>`; container singleton `Modules::class`; command `lanomat:modules --json` printing `{profile, enabled[], routes[], filament_resources[], scheduled[], preflight[]}`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Modules/ModulesTest.php` (plain Pest, no framework needed — add `'Unit/Modules'` to the `->in(...)` of the first `pest()->extend(TestCase::class)` call in `tests/Pest.php` only if a test needs the container; these do not):

```php
<?php

use App\Support\Modules\InvalidModuleConfiguration;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

it('enables only core modules in the core profile', function () {
    $modules = new Modules('core');

    expect($modules->enabled(Module::Tournaments))->toBeTrue()
        ->and($modules->enabled(Module::Jukebox))->toBeFalse()
        ->and($modules->enabled(Module::Discord))->toBeFalse();
});

it('enables everything in the full profile', function () {
    $modules = new Modules('full');

    expect(count($modules->enabledModules()))->toBe(count(Module::cases()));
});

it('lets an override enable an optional module', function () {
    $modules = new Modules('core', ['jukebox' => true]);

    expect($modules->enabled(Module::Jukebox))->toBeTrue();
});

it('lets an override disable an optional module in the full profile', function () {
    $modules = new Modules('full', ['voice' => false]);

    expect($modules->enabled(Module::Voice))->toBeFalse();
});

it('rejects disabling a core module, an unknown profile and a missing dependency, listing every problem', function () {
    try {
        new Modules('bogus', ['events' => false, 'lancache' => true]);
        $this->fail('expected InvalidModuleConfiguration');
    } catch (InvalidModuleConfiguration $e) {
        expect($e->problems)->toContain('Unknown MODULES_PROFILE "bogus" (expected core or full).')
            ->toContain('Core module "events" cannot be disabled.')
            ->toContain('Module "lancache" requires "hosts", which is disabled.');
    }
});

it('exposes every module to route generation when asked, without enabling it', function () {
    $modules = new Modules('core', exposeAllRoutes: true);

    expect($modules->routable(Module::Jukebox))->toBeTrue()
        ->and($modules->enabled(Module::Jukebox))->toBeFalse();
});

it('keeps requires acyclic and within known modules', function () {
    foreach (Module::cases() as $module) {
        foreach ($module->requires() as $required) {
            expect($required->requires())->not->toContain($module);
        }
    }
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest -d memory_limit=1G tests/Unit/Modules`
Expected: FAIL (`Class "App\Support\Modules\Modules" not found`).

- [ ] **Step 3: Implement enum, exception, service**

`app/Support/Modules/Module.php`:

```php
<?php

namespace App\Support\Modules;

/**
 * One case per directory in app/Modules. `->name` is the directory name,
 * `->value` the snake_case key used in MODULE_<KEY> env overrides.
 */
enum Module: string
{
    case Casting = 'casting';
    case Catering = 'catering';
    case CustomServers = 'custom_servers';
    case Discord = 'discord';
    case Events = 'events';
    case Files = 'files';
    case Friends = 'friends';
    case Gallery = 'gallery';
    case GameServers = 'game_servers';
    case Games = 'games';
    case Hosts = 'hosts';
    case Identity = 'identity';
    case Infoscreen = 'infoscreen';
    case Jukebox = 'jukebox';
    case Lancache = 'lancache';
    case Lfg = 'lfg';
    case News = 'news';
    case Notifications = 'notifications';
    case Preflight = 'preflight';
    case Presence = 'presence';
    case Recap = 'recap';
    case Registration = 'registration';
    case Schedule = 'schedule';
    case Seating = 'seating';
    case Stats = 'stats';
    case Teams = 'teams';
    case Tournaments = 'tournaments';
    case Voice = 'voice';
    case Voting = 'voting';

    public function key(): string
    {
        return $this->value;
    }

    public function isCore(): bool
    {
        return match ($this) {
            self::Events, self::Registration, self::Seating, self::Teams, self::Games,
            self::Tournaments, self::Identity, self::Notifications, self::Preflight => true,
            default => false,
        };
    }

    /**
     * Hard dependencies: modules whose own pages/actions call into these
     * (derived from tests/Architecture/module-dependencies.php, runtime use
     * only). Everything else is reached through events/guards and tolerates
     * the other module being off.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::CustomServers, self::Lancache => [self::Hosts],
            self::Recap => [self::Gallery, self::Jukebox, self::Voting, self::Presence],
            self::News => [self::Gallery],
            self::Gallery => [self::Files],
            self::Stats => [self::Voting],
            self::Friends => [self::Presence],
            default => [],
        };
    }
}
```


`app/Support/Modules/InvalidModuleConfiguration.php`:

```php
<?php

namespace App\Support\Modules;

use RuntimeException;

final class InvalidModuleConfiguration extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("Invalid module configuration:\n - ".implode("\n - ", $problems));
    }
}
```

`app/Support/Modules/Modules.php`:

```php
<?php

namespace App\Support\Modules;

final class Modules
{
    /** @var array<string, true> */
    private array $enabled = [];

    /**
     * @param  array<string, bool>  $overrides  keyed by Module::key()
     * @param  bool  $exposeAllRoutes  true only while `wayfinder:generate` runs, see routable()
     */
    public function __construct(
        string $profile,
        array $overrides = [],
        private readonly bool $exposeAllRoutes = false,
    ) {
        $problems = [];

        if (! in_array($profile, ['core', 'full'], true)) {
            $problems[] = "Unknown MODULES_PROFILE \"{$profile}\" (expected core or full).";
        }

        foreach (Module::cases() as $module) {
            $on = $profile === 'full' || $module->isCore();

            if (array_key_exists($module->key(), $overrides)) {
                $wanted = $overrides[$module->key()];

                if ($module->isCore() && $wanted === false) {
                    $problems[] = "Core module \"{$module->key()}\" cannot be disabled.";
                } else {
                    $on = $wanted;
                }
            }

            if ($on) {
                $this->enabled[$module->key()] = true;
            }
        }

        foreach (Module::cases() as $module) {
            if (! $this->enabled($module)) {
                continue;
            }

            foreach ($module->requires() as $required) {
                if (! $this->enabled($required)) {
                    $problems[] = "Module \"{$module->key()}\" requires \"{$required->key()}\", which is disabled.";
                }
            }
        }

        if ($problems !== []) {
            throw new InvalidModuleConfiguration($problems);
        }
    }

    public function enabled(Module $module): bool
    {
        return isset($this->enabled[$module->key()]);
    }

    /**
     * Whether the module's routes are registered. Identical to enabled(),
     * except while Wayfinder generates the TypeScript route helpers: the
     * frontend bundle imports helpers for every module and must compile under
     * every profile; hiding happens at runtime via the `modules` Inertia prop.
     */
    public function routable(Module $module): bool
    {
        return $this->exposeAllRoutes || $this->enabled($module);
    }

    /** @return list<Module> */
    public function enabledModules(): array
    {
        return array_values(array_filter(Module::cases(), fn (Module $m) => $this->enabled($m)));
    }
}
```

- [ ] **Step 4: Run unit tests**

Run: `./vendor/bin/pest -d memory_limit=1G tests/Unit/Modules`
Expected: PASS (7 tests).

- [ ] **Step 5: Config, provider, registration, test env**

`config/modules.php`:

```php
<?php

use App\Support\Modules\Module;

/*
| Deploy-time module toggles. MODULES_PROFILE: `core` (default — only the core
| modules) or `full` (everything, = pre-toggle behaviour). Individual modules
| can be forced with MODULE_<KEY>=true|false (e.g. MODULE_JUKEBOX=true,
| MODULE_CUSTOM_SERVERS=false). See docs/superpowers/specs/2026-10-14-module-toggles-design.md.
*/
return [
    'profile' => env('MODULES_PROFILE', 'core'),

    'overrides' => collect(Module::cases())
        ->mapWithKeys(fn (Module $m) => [$m->key() => env('MODULE_'.strtoupper($m->key()))])
        ->filter(fn ($value) => $value !== null)
        ->map(fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN))
        ->all(),
];
```

`app/Providers/ModulesServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Support\Modules\Modules;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Modules::class, function (Application $app): Modules {
            /** @var list<string> $argv */
            $argv = $_SERVER['argv'] ?? [];

            return new Modules(
                (string) config('modules.profile'),
                (array) config('modules.overrides'),
                exposeAllRoutes: $app->runningInConsole() && in_array('wayfinder:generate', $argv, true),
            );
        });
    }
}
```

`bootstrap/providers.php`: add `App\Providers\ModulesServiceProvider::class` as the **first** entry (the Filament panel provider and routes need it).

`phpunit.xml`: inside `<php>` add `<env name="MODULES_PROFILE" value="full"/>`. `.env.testing`: add `MODULES_PROFILE=full`.

- [ ] **Step 6: Failing feature tests for the introspection command and wayfinder/config-cache traps**

`tests/Feature/Modules/ModulesProcess.php` (helper, required via `require_once` in tests that need it):

```php
<?php

use Symfony\Component\Process\Process;

/**
 * Boot-time registration (routes, Filament, scheduler, listeners) cannot be
 * re-run inside one PHP process, so profile tests spawn `php artisan` with the
 * profile in the environment.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function modulesReport(string $profile, array $env = []): array
{
    $process = new Process(
        [PHP_BINARY, 'artisan', 'lanomat:modules', '--json'],
        base_path(),
        ['MODULES_PROFILE' => $profile, 'APP_ENV' => 'testing', ...$env],
    );
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}
```

`tests/Feature/Modules/ModulesCommandTest.php`:

```php
<?php

require_once __DIR__.'/ModulesProcess.php';

it('reports the full profile with every module enabled', function () {
    $report = modulesReport('full');

    expect($report['profile'])->toBe('full')
        ->and($report['enabled'])->toContain('jukebox', 'voice', 'discord');
});

it('reports the core profile with only core modules', function () {
    $report = modulesReport('core');

    expect($report['enabled'])->toEqualCanonicalizing([
        'events', 'registration', 'seating', 'teams', 'games',
        'tournaments', 'identity', 'notifications', 'preflight',
    ]);
});

it('fails to boot with a clear message on an invalid configuration', function () {
    $process = new Symfony\Component\Process\Process(
        [PHP_BINARY, 'artisan', 'lanomat:modules', '--json'],
        base_path(),
        ['MODULES_PROFILE' => 'core', 'MODULE_EVENTS' => 'false', 'APP_ENV' => 'testing'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Core module "events" cannot be disabled.');
});

it('resolves the same modules from a cached config', function () {
    $env = ['MODULES_PROFILE' => 'core', 'MODULE_JUKEBOX' => 'true', 'APP_ENV' => 'testing'];
    $cache = new Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $env);
    $cache->mustRun();

    try {
        // No MODULE_* in the environment this time: only the cached config may supply them.
        $report = modulesReport('full', ['MODULES_PROFILE' => 'full']);
        expect($report['enabled'])->toContain('jukebox')
            ->and($report['profile'])->toBe('core'); // cached value wins over env
    } finally {
        (new Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'config:clear'], base_path()))->mustRun();
    }
});

it('keeps all routes visible to wayfinder under the core profile', function () {
    $process = new Symfony\Component\Process\Process(
        [PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form'],
        base_path(),
        ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing'],
    );
    $process->mustRun();

    expect(file_exists(resource_path('js/routes/jukebox/index.ts')))->toBeTrue();
});
```

Note on the config-cache test: `modulesReport` passes `MODULES_PROFILE` in the child's env, but with a cached config `env()` is not read, so `profile` must come back `core` — that is the assertion.

- [ ] **Step 7: Implement `ModulesCommand`**

`app/Console/Commands/ModulesCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

class ModulesCommand extends Command
{
    protected $signature = 'lanomat:modules {--json : Machine-readable output}';

    protected $description = 'Show the active module profile and what each module registered';

    public function handle(Modules $modules, Schedule $schedule): int
    {
        $report = [
            'profile' => (string) config('modules.profile'),
            'enabled' => array_map(fn (Module $m) => $m->key(), $modules->enabledModules()),
            'routes' => collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->values()->all(),
            'filament_resources' => array_values(Filament::getPanel('admin')->getResources()),
            'scheduled' => collect($schedule->events())
                ->map(fn ($e) => $e instanceof CallbackEvent ? (string) $e->description : (string) $e->command)
                ->values()->all(),
            'preflight' => collect(app()->tagged('preflight.checks'))
                ->map(fn (HealthCheck $c) => $c->key())->values()->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Profile: '.$report['profile']);
        $this->line('Enabled: '.implode(', ', $report['enabled']));
        $this->line('Disabled: '.implode(', ', array_map(
            fn (Module $m) => $m->key(),
            array_filter(Module::cases(), fn (Module $m) => ! $modules->enabled($m)),
        )));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Run, fix, gate**

Run: `./vendor/bin/pest -d memory_limit=1G tests/Unit/Modules tests/Feature/Modules` → PASS. Then `./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress`. The architecture ratchet will flag the new `App\Support`→`App\Modules\Preflight` reference? It only scans `app/Modules`, so no. Run the full architecture suite to be sure: `./vendor/bin/pest -d memory_limit=1G --testsuite=Architecture`.

- [ ] **Step 9: Commit**

```bash
git add app/Support app/Providers/ModulesServiceProvider.php config/modules.php app/Console/Commands/ModulesCommand.php bootstrap/providers.php phpunit.xml .env.testing tests
git commit -m "feat(modules): module enum, Modules service, config profiles and lanomat:modules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Share the `modules` Inertia prop and `useModules`

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/global.d.ts`
- Create: `resources/js/composables/useModules.ts`
- Test: `tests/Feature/Modules/InertiaModulesPropTest.php`

**Interfaces:**
- Consumes: `Modules::enabledModules()`, `Module::key()`
- Produces: shared prop `modules: string[]` (enabled keys); TS `useModules(): { has(key: ModuleKey): boolean }`; type `ModuleKey` union of the 29 keys.

- [ ] **Step 1: Failing test**

```php
<?php

it('shares the enabled module keys with every inertia page', function () {
    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('modules', fn ($modules) => collect($modules)->contains('tournaments')
            && collect($modules)->contains('jukebox'))); // suite runs under MODULES_PROFILE=full
});
```

Run: `./vendor/bin/pest -d memory_limit=1G tests/Feature/Modules/InertiaModulesPropTest.php` → FAIL (prop missing).

- [ ] **Step 2: Implement**

In `HandleInertiaRequests::share()` add after `'name' => config('app.name'),`:

```php
'modules' => fn () => array_map(
    fn (Module $m) => $m->key(),
    app(Modules::class)->enabledModules(),
),
```
with `use App\Support\Modules\Module; use App\Support\Modules\Modules;`.

`resources/js/types/global.d.ts`: next to `currentEvent: EventSummary | null;` add `modules: ModuleKey[];` and declare, in `resources/js/types/index.ts`, `export type ModuleKey = 'casting' | 'catering' | 'custom_servers' | 'discord' | 'events' | 'files' | 'friends' | 'gallery' | 'game_servers' | 'games' | 'hosts' | 'identity' | 'infoscreen' | 'jukebox' | 'lancache' | 'lfg' | 'news' | 'notifications' | 'preflight' | 'presence' | 'recap' | 'registration' | 'schedule' | 'seating' | 'stats' | 'teams' | 'tournaments' | 'voice' | 'voting';` (import it in `global.d.ts` the same way `EventSummary` is imported).

`resources/js/composables/useModules.ts`:

```ts
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ModuleKey } from '@/types';

export function useModules() {
    const page = usePage();
    const enabled = computed<ModuleKey[]>(() => page.props.modules ?? []);

    return {
        has: (key: ModuleKey): boolean => enabled.value.includes(key),
    };
}
```

- [ ] **Step 3: Run test + four frontend gates**

`./vendor/bin/pest -d memory_limit=1G tests/Feature/Modules/InertiaModulesPropTest.php` → PASS; `npm run types:check && npm run lint:check && npm run format:check && npm run build` → all green.

- [ ] **Step 4: Commit** — `feat(modules): share enabled modules with the frontend` (+ attribution).

---

### Task 3: Filament discovery loop

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/Modules/FilamentModulesTest.php`

**Interfaces:**
- Consumes: `Modules::enabledModules()`, `Module::$name`
- Produces: Filament panel `admin` whose resources come only from enabled modules.

- [ ] **Step 1: Failing test** (uses the `modulesReport` helper)

```php
<?php

require_once __DIR__.'/ModulesProcess.php';

it('registers only core module resources under the core profile', function () {
    $resources = modulesReport('core')['filament_resources'];

    expect(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Teams\\')))->toBeTrue()
        ->and(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Voice\\')))->toBeFalse()
        ->and(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Hosts\\')))->toBeFalse();
});

it('registers every module resource under the full profile', function () {
    $resources = modulesReport('full')['filament_resources'];

    expect(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Voice\\')))->toBeTrue();
});
```

Run → the first test FAILS (Voice resource present).

- [ ] **Step 2: Implement.** In `panel()`: assign the head of the chain to `$panel` (everything up to and including the first `->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')`), delete the 17 hand-written `->discoverResources(in: app_path('Modules/<X>/Filament/Resources') …)` calls, and insert:

```php
$modules = app(Modules::class);

foreach ($modules->enabledModules() as $module) {
    $dir = app_path("Modules/{$module->name}/Filament/Resources");

    if (is_dir($dir)) {
        $panel->discoverResources(in: $dir, for: "App\\Modules\\{$module->name}\\Filament\\Resources");
    }
}

return $panel
    ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
    ->pages([Dashboard::class, ManageDisputes::class])
    // … existing discoverWidgets / widgets / middleware / authMiddleware chain unchanged
```

(`Panel` methods mutate and return `$this`, so the loop and the continued chain act on the same instance.) Add `use App\Support\Modules\Modules;`.

- [ ] **Step 3: Verify.** `./vendor/bin/pest -d memory_limit=1G tests/Feature/Modules/FilamentModulesTest.php` PASS; run `./vendor/bin/pest -d memory_limit=1G tests/Feature/Events tests/Feature/Voice tests/Feature/Hosts` (resources still reachable under `full`) PASS; pint + phpstan.

- [ ] **Step 4: Commit** — `feat(modules): discover Filament resources only for enabled modules`.

---

### Task 4: Routes and broadcast channels per module

**Files:**
- Modify: `routes/web.php`, `routes/channels.php`
- Test: `tests/Feature/Modules/RoutesModulesTest.php`

**Interfaces:**
- Consumes: `Modules::routable(Module)`
- Produces: route groups gated per module. Route **names are unchanged** (Wayfinder + tests depend on them).

- [ ] **Step 1: Failing test**

```php
<?php

require_once __DIR__.'/ModulesProcess.php';

it('registers no optional-module route under the core profile', function () {
    $routes = modulesReport('core')['routes'];

    $optionalPrefixes = ['jukebox.', 'gallery.', 'voice.', 'friends.', 'schedule.', 'catering.', 'polls.',
        'lfg.', 'servers.', 'files.', 'presence.', 'recap.', 'screen.', 'overlay.', 'discord.', 'telemetry.', 'stats.'];

    foreach ($optionalPrefixes as $prefix) {
        expect(collect($routes)->contains(fn ($n) => str_starts_with($n, $prefix)))->toBeFalse("$prefix* should be absent");
    }

    foreach (['home', 'events.show', 'teams.index', 'tournaments.show', 'events.seating', 'events.register', 'orga.checkin', 'login.discord', 'notifications.read'] as $name) {
        expect($routes)->toContain($name);
    }
});

it('registers the same routes under full as before the toggles', function () {
    $routes = modulesReport('full')['routes'];

    expect($routes)->toContain('jukebox.index', 'voice.setup', 'discord.interactions', 'telemetry.cs2', 'friends.index');
});
```

Run → first FAILS.

- [ ] **Step 2: Restructure `routes/web.php`.** At the top after the imports add:

```php
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

$modules = app(Modules::class);
```

Then move **each existing route line unchanged** (with its comment) into the block of its owning module. Resulting layout and ownership (route name → module):

```php
// ---- core (always) ----
// home, events.index, events.show, profile.show, teams.index/show, events.seating,
// tournaments.index/show, dev.login, login.discord (guest group), auth/discord/callback,
// and the existing `auth` group parts: dashboard, events.register(.store/.destroy),
// notifications.read, events.seating.claim/release, tournaments.enroll/checkin,
// matches.report/confirm/dispute/go, teams.store/edit/update/join/respond/leave,
// and the `auth + role:helper` part: orga.checkin(.store)
// ---- optional, one block each ----
if ($modules->routable(Module::Stats)) { Route::get('/stats/leaderboard', …)->name('stats.leaderboard'); }
if ($modules->routable(Module::Schedule)) { schedule.index, schedule.ics; auth: schedule.favorite, schedule.unfavorite }
if ($modules->routable(Module::Catering)) { catering.show; auth: catering.items.store/destroy }
if ($modules->routable(Module::Voting)) { polls.index, polls.show; auth: polls.vote }
if ($modules->routable(Module::Lfg)) { lfg.index; auth: lfg.store, lfg.destroy }
if ($modules->routable(Module::GameServers)) { servers.index; telemetry.cs2 (the api/telemetry/cs2/{serverLink} POST) }
if ($modules->routable(Module::Files)) { files.index; auth: files.store/download/destroy }
if ($modules->routable(Module::Presence)) { presence.show }
if ($modules->routable(Module::Recap)) { recap.show }
if ($modules->routable(Module::Infoscreen)) { screen.show; auth: events.ping-orga (throttle:5,1); auth+role:helper: screen.control, screen.control.trigger.food-ready, screen.control.trigger.checkin-open, screen.control.tombola.draw, screen.control.set-status, screen.control.show (keep the registration ORDER of set-status before {scene}) }
if ($modules->routable(Module::Gallery)) { gallery.photos.public.show/thumb; auth: gallery.photos.show/thumb, gallery.index/store/destroy/zip }
if ($modules->routable(Module::Jukebox)) { jukebox.index; auth: jukebox.search/add/skip/vote/skip-vote/remove }
if ($modules->routable(Module::Casting)) { overlay.bracket, overlay.scoreboard }
if ($modules->routable(Module::Voice)) { auth: voice.setup, voice.installers.download }
if ($modules->routable(Module::Friends)) { auth: friends.index/request/respond/cancel/remove/block/unblock }
if ($modules->routable(Module::Discord)) { discord.voice; discord.interactions (+discord.signature); discord.gateway.ingress (+discord.gateway) }
```

Each optional block contains its own `Route::middleware(['auth'])->group(…)` / `['auth', 'role:helper']` sub-groups where it has authenticated routes. Keep `require __DIR__.'/settings.php';` last. Delete the now-empty original groups. Verify the move is lossless with a **route-name diff**: before editing run `php artisan route:list --json | jq -r '.[].name' | sort > /tmp/before.txt` (under `MODULES_PROFILE=full`), after editing run the same and `diff` — must be empty; also diff `route:list --json | jq -r '.[] | "\(.method) \(.uri) \(.middleware|join(","))"' | sort` the same way to catch middleware changes.

- [ ] **Step 3: `routes/channels.php`.** The only module-specific entries are comments plus `App.Models.User.{id}` (core). Add nothing gated except wrapping nothing — leave the file, but append a comment `// Public event/tournament/discord-voice channels carry no auth callback; nothing to gate.` (the spec's channel gating is a no-op because no module registers a callback). Record this in Task 8's spec amendment.

- [ ] **Step 4: Verify.** New tests PASS; `./vendor/bin/pest -d memory_limit=1G` (full suite, runs under `full`) stays green — expect the same 1586+ count. Also re-run the wayfinder test from Task 1.

- [ ] **Step 5: Commit** — `feat(modules): gate routes per module (404 when disabled)`.

---

### Task 5: Gate listeners, policies/gates, Preflight probes and scheduler

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`, `routes/console.php`
- Test: `tests/Feature/Modules/RegistrationGatingTest.php`

**Interfaces:**
- Consumes: `Modules::enabled()`
- Produces: `lanomat:modules --json` `scheduled` and `preflight` reflect the profile.

- [ ] **Step 1: Failing tests**

```php
<?php

require_once __DIR__.'/ModulesProcess.php';

it('schedules only core commands under the core profile', function () {
    $scheduled = implode("\n", modulesReport('core')['scheduled']);

    expect($scheduled)->toContain('lanomat:tournament-tick')->toContain('lanomat:heartbeat')
        ->not->toContain('lanomat:jukebox-tick')
        ->not->toContain('lanomat:sweep-discord-outbox')
        ->not->toContain('lanomat:send-schedule-reminders');
});

it('tags only core Preflight probes under the core profile', function () {
    $keys = modulesReport('core')['preflight'];

    expect($keys)->toContain('database', 'redis')
        ->not->toContain('discord_api', 'mumble', 'teamspeak', 'pelican', 'music_assistant');
});

it('keeps all probes and schedule entries under full', function () {
    $report = modulesReport('full');

    expect(implode("\n", $report['scheduled']))->toContain('lanomat:jukebox-tick');
    expect(count($report['preflight']))->toBe(13);
});

it('registers no optional listener under the core profile', function () {
    // MatchReady keeps its core listener (NotifyRosterOnMatchReady) — assert the optional ones via event:list instead.
    $list = new Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'event:list', '--json'], base_path(), ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing']);
    $list->mustRun();

    expect($list->getOutput())->toContain('NotifyRosterOnMatchReady')
        ->not->toContain('CreateMatchChannelOnReady')
        ->not->toContain('ProvisionMatchVoiceOnReady');
});
```

Probe keys: confirm the actual `key()` strings by `grep -n "return '" app/Modules/Preflight/Checks/*.php` and use those in the first assertion list.

- [ ] **Step 2: Implement `AppServiceProvider`.** Inject `Modules` in the two methods via `app(Modules::class)`. Add a small private helper `private function on(Module $m): bool { return app(Modules::class)->enabled($m); }`.

Preflight tags (in `register()`), replacing the single `tag([...])`:

```php
$modules = app(Modules::class);

$this->app->tag([
    DatabaseCheck::class, RedisCheck::class, StorageWritableCheck::class,
    FailedJobsCheck::class, ReverbCheck::class, SchedulerHeartbeatCheck::class, QueueWorkerCheck::class,
    ...($modules->enabled(Module::Discord) ? [DiscordApiCheck::class, DiscordGatewaySidecarCheck::class] : []),
    ...($modules->enabled(Module::Voice) ? [MumbleSidecarCheck::class, TeamSpeakSidecarCheck::class] : []),
    ...($modules->enabled(Module::GameServers) ? [PelicanCheck::class] : []),
    ...($modules->enabled(Module::Jukebox) ? [MusicAssistantCheck::class] : []),
], 'preflight.checks');
```

`configureAuthorization()`: keep core policies as today (`Event`, `EventRegistration`, `Seat`, `Team`, `Game`, `Tournament`/`TournamentEntry`/`GameMatch`/`MatchReport`, `LinkedAccount`, `claim-seat` gate); wrap the others:

```php
if ($this->on(Module::Schedule)) { Gate::policy(ScheduleItem::class, ScheduleItemPolicy::class); }
if ($this->on(Module::Catering)) { Gate::policy(FoodOrder::class, FoodOrderPolicy::class); Gate::policy(FoodOrderItem::class, FoodOrderItemPolicy::class); }
if ($this->on(Module::Voting)) { Gate::policy(Poll::class, PollPolicy::class); }
if ($this->on(Module::Lfg)) { Gate::policy(LfgPost::class, LfgPostPolicy::class); }
if ($this->on(Module::Files)) { Gate::policy(SharedFile::class, SharedFilePolicy::class); }
if ($this->on(Module::Gallery)) { Gate::policy(EventPhoto::class, EventPhotoPolicy::class); }
if ($this->on(Module::Infoscreen)) { Gate::policy(InfoscreenScene::class, InfoscreenScenePolicy::class); Gate::policy(TombolaPrize::class, TombolaPrizePolicy::class); }
if ($this->on(Module::GameServers)) { Gate::policy(ServerLink::class, ServerLinkPolicy::class); }
if ($this->on(Module::Hosts)) { Gate::policy(RemoteHost::class, RemoteHostPolicy::class); }
if ($this->on(Module::CustomServers)) { Gate::policy(CustomServer::class, CustomServerPolicy::class); }
if ($this->on(Module::Voice)) { Gate::policy(VoiceClientInstaller::class, VoiceClientInstallerPolicy::class); }
if ($this->on(Module::Friends)) { Gate::policy(Friendship::class, FriendshipPolicy::class); }
if ($this->on(Module::News)) { Gate::policy(NewsPost::class, NewsPostPolicy::class); }
if ($this->on(Module::Jukebox)) { Gate::define('jukebox.participate', …); Gate::define('jukebox.moderate', …); }
```

`configureEventListeners()` — owner of each listener (the module whose class it is):
- core, always: `ReleaseSeatOnCancellation`, `NotifyRosterOnMatchReady`, `SyncScheduleOnTournamentSaved`**→Schedule**, see below.
- Discord: `AnnounceRegistrationOpen`, `CreateMatchChannelOnReady`, `AnnounceAndCleanupOnCompleted`, the four `LogGatewayEvent` registrations.
- Infoscreen: `BroadcastWinnerMoment`, `GongOnMatchLive`, `BroadcastScoreboardOnScoreUpdated`.
- Voice: `ProvisionVoiceOnStart`, `ProvisionMatchVoiceOnReady`, `CleanupVoiceOnCompleted`, `ProvisionServerVoiceOnReady`.
- GameServers: `ProvisionMatchServerOnReady`, `CleanupServersOnCompleted`, `UpdateMatchSurfacesOnServerReady`, `EnterWarmupOnServerReady`.
- Schedule: `SyncScheduleOnTournamentSaved`, `AlarmScheduleItemChanged`.
- Lfg: `AnnounceLfgPost`.
- Presence: the four `BroadcastPresenceOnTournamentActivity` registrations.

Write each group as `if ($this->on(Module::X)) { Event::listen(...); … }`, keeping the exact `Event::listen` lines. Also bind `ScheduleParticipantResolver` stays unconditional (Deviation 1).

- [ ] **Step 3: Implement `routes/console.php`.** Add `use App\Support\Modules\Module; use App\Support\Modules\Modules;` and `$modules = app(Modules::class);`, then:

```php
if ($modules->enabled(Module::Discord)) {
    Schedule::command('lanomat:send-reminders')->everyFiveMinutes();
    Schedule::command('lanomat:sweep-discord-outbox')->everyFiveMinutes();
}
Schedule::command('lanomat:tournament-tick')->everyMinute();
if ($modules->enabled(Module::Lfg)) { Schedule::command('lanomat:prune-lfg')->everyFiveMinutes(); }
if ($modules->enabled(Module::Schedule)) { Schedule::command('lanomat:send-schedule-reminders')->everyMinute(); }
if ($modules->enabled(Module::Jukebox)) {
    // (keep the existing polling comment)
    Schedule::command('lanomat:jukebox-tick')->everyMinute();
}
Schedule::command('lanomat:heartbeat')->everyMinute();
Schedule::command('lanomat:health-watch')->everyFiveMinutes();
Schedule::job(new RefreshExpiringTokensJob)->hourly();
```

- [ ] **Step 4: Verify.** New tests PASS; full `./vendor/bin/pest -d memory_limit=1G` green under `full`; pint; phpstan.

- [ ] **Step 5: Commit** — `feat(modules): gate listeners, policies, Preflight probes and scheduler per module`.

---

### Task 6: Guards for core → optional runtime calls

**Files:**
- Modify: `app/Modules/Events/Http/EventPageController.php`, `app/Modules/Tournaments/Http/TournamentPageController.php`, `app/Modules/Tournaments/Support/BracketMatchProjection.php`, `app/Modules/Tournaments/Notifications/MatchReadyBell.php`, `app/Modules/Registration/Notifications/CheckinOpened.php`, `app/Modules/Identity/Http/ProfileController.php`
- Test: `tests/Feature/Modules/CoreGuardsTest.php`, `tests/Architecture/core-optional-baseline.php`, extend `tests/Architecture/ModuleDependenciesTest.php`

**Interfaces:**
- Consumes: `Modules::enabled()`; tests swap the singleton with `app()->instance(Modules::class, new Modules('core'))`.
- Produces: pages/notifications that work with the module off; ratchet #2.

Runtime-relevant call sites (everything else in the baseline is docblock-only, pure value objects, or tolerates empty data): news + poll teaser on the event pages, voice links on the tournament page, Discord channel on two notifications, `ServerLink` in the bracket projection, friend state on profiles.

- [ ] **Step 1: Failing tests** (`tests/Feature/Modules/CoreGuardsTest.php`; each swaps in a core `Modules` for the request and asserts no exception + absence):

```php
<?php

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Notifications\CheckinOpened;
use App\Modules\Tournaments\Notifications\MatchReadyBell;
use App\Support\Modules\Modules;

beforeEach(fn () => app()->instance(Modules::class, new Modules('core')));

it('renders the home page without the news block when News is off', function () {
    $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->missing('news'));
});

it('omits the poll teaser from event data when Voting is off', function () {
    $event = Event::factory()->published()->create();

    $this->get(route('events.show', $event))->assertOk()
        ->assertInertia(fn ($p) => $p->where('hype.activePoll', null));
});

it('drops the Discord channel from notifications when Discord is off', function () {
    $user = App\Models\User::factory()->create();
    // Constructor args: copy from the existing notification tests (tests/Feature/Tournaments, tests/Feature/Registration).
    expect((new CheckinOpened(Event::factory()->create()))->via($user))->toBe(['database']);
});

it('shows a profile without friend state when Friends is off', function () {
    $viewer = App\Models\User::factory()->create();
    $target = App\Models\User::factory()->create();

    $this->actingAs($viewer)->get(route('profile.show', $target))->assertOk()
        ->assertInertia(fn ($p) => $p->where('relationship', null));
});
```

Before writing the `hype` and constructor assertions, read the existing tests for these controllers/notifications (`grep -rn "activePoll\|MatchReadyBell(\|CheckinOpened(" tests | head`) and use their exact factory states and constructor arguments and the real prop path — adjust the expected shape in the test, not the guard. Add equivalent cases for `TournamentPageController` (`myMatchVoiceLinks` is `[]` and `voiceLabels` absent when Voice is off) and `BracketMatchProjection` (server DTO null when GameServers is off).

Run → FAIL.

- [ ] **Step 2: Implement guards** (each is a one-condition change; no refactor):

`EventPageController`:
- `news` prop: `'news' => ! app(Modules::class)->enabled(Module::News) ? [] : app(NewsQuery::class)->published(3)->…` — and for the test `->missing('news')` choose consistently: use `Inertia::optional`-free approach: build `$props = [...]` then `if (modules->enabled(News)) { $props['news'] = … }`. Match whichever the test asserts.
- poll teaser: `$poll = ! app(Modules::class)->enabled(Module::Voting) ? null : Poll::query()…->first();`

`TournamentPageController`: in `voiceLinksFor()` first line: `if (! app(Modules::class)->enabled(Module::Voice)) { return []; }`; in the props array wrap `'voiceLabels' => trans('voice.join')` as `app(Modules::class)->enabled(Module::Voice) ? trans('voice.join') : []`.

`BracketMatchProjection::serverDto`: first line `if (! app(Modules::class)->enabled(Module::GameServers)) { return null; }`.

`MatchReadyBell::via` and `CheckinOpened::via`:
```php
return app(Modules::class)->enabled(Module::Discord) ? ['database', DiscordChannel::class] : ['database'];
```

`ProfileController::relationship`: first line `if (! app(Modules::class)->enabled(Module::Friends)) { return null; }`.

Add the needed `use App\Support\Modules\Module; use App\Support\Modules\Modules;` imports. These new `App\Support` references are outside the `app/Modules` ratchet's concern.

- [ ] **Step 3: Ratchet #2.** Generate the current core→optional edges into `tests/Architecture/core-optional-baseline.php`:

```bash
php -r '$g=require "tests/Architecture/module-dependencies.php"; $core=["Events","Registration","Seating","Teams","Games","Tournaments","Identity","Notifications","Preflight"]; $o=[]; foreach($core as $c){ $o[$c]=array_values(array_diff($g[$c],$core)); } file_put_contents("tests/Architecture/core-optional-baseline.php","<?php\n\nreturn ".var_export($o,true).";\n");' && ./vendor/bin/pint tests/Architecture
```

Add to `ModuleDependenciesTest.php` a second test `it('adds no new core -> optional dependencies', …)` that loads the graph via `$moduleDependencyGraph()`, computes the same core→optional map, and `expect($added)->toBe([])` against the baseline (fail on additions only; removals are fine here and just shrink the file on the next dump — also fail on removals to keep the ratchet tight, mirroring ratchet #1 and the `LANOMAT_ARCH_DUMP` skip behaviour: extend the dump block to rewrite this baseline too).

- [ ] **Step 4: Verify.** `./vendor/bin/pest -d memory_limit=1G tests/Feature/Modules tests/Architecture` PASS; full suite PASS under `full`; pint; phpstan.

- [ ] **Step 5: Commit** — `feat(modules): guard core→optional runtime calls and ratchet them`.

---

### Task 7: Frontend nav gating

**Files:**
- Modify: `resources/js/components/AppSidebar.vue`, `resources/js/layouts/PublicShell.vue`, `resources/js/pages/Event/Show.vue`, `resources/js/pages/Profile/Show.vue`

**Interfaces:**
- Consumes: `useModules().has(key)` (Task 2)

Rule: a link/section whose target route belongs to a disabled module is not rendered. No new visual elements, no empty-state messages (the item simply is absent).

- [ ] **Step 1: `AppSidebar.vue`.** Import `useModules` and add `const { has } = useModules();`. Replace the two item lists with conditional construction:

```ts
const generalItems = computed<NavItem[]>(() => [
    { title: 'Aktuelle LAN', href: homeHref(currentEvent.value), icon: PartyPopper },
    { title: 'Events', href: eventsIndex(), icon: CalendarDays },
    { title: 'Teams', href: teamsIndex(), icon: Users },
    ...(has('stats') ? [{ title: 'Bestenliste', href: statsLeaderboard(), icon: Trophy }] : []),
    ...(has('friends') ? [{ title: 'Freunde', href: friendsIndex(), icon: UserPlus }] : []),
    ...(has('voice') ? [{ title: 'Voice einrichten', href: voiceSetup(), icon: Mic }] : []),
]);
```

and in `eventItems`: `Zeitplan` only if `has('schedule')`, `Präsenz` if `has('presence')`, `Jukebox` if `has('jukebox')`, `Galerie` if `has('gallery')` (Übersicht, Turniere, Sitzplan, Check-in stay). Build the array with the same spread pattern.

- [ ] **Step 2: `Event/Show.vue`.** Wrap the three link blocks (`presenceShow`, `jukeboxIndex`, `galleryIndex`, lines ~177–191) in `v-if="has('presence')"`, `v-if="has('jukebox')"`, `v-if="has('gallery')"`; the poll teaser (`pollShow`) in `v-if="has('voting') && …existing condition"`; the news block in `v-if="has('news') && …existing condition"`. Add `const { has } = useModules();`.

- [ ] **Step 3: `PublicShell.vue` and `Profile/Show.vue`.** In `PublicShell.vue` gate the `jukeboxIndex`, `presenceShow`, `recapShow` links by `has('jukebox')`, `has('presence')`, `has('recap')` respectively. In `Profile/Show.vue` the friend/block controls already hide when `relationship` is `null` (Task 6) — verify by reading the template; if any friend control renders unconditionally for a logged-in viewer, wrap it in `v-if="has('friends')"`.

- [ ] **Step 4: Verify.** All four gates: `npm run types:check && npm run lint:check && npm run format:check && npm run build`. Then run the app locally with `MODULES_PROFILE=core php artisan serve` + `npm run dev`, load `/` and an event page and confirm the nav shows only Aktuelle LAN/Events/Teams + Übersicht/Turniere/Sitzplan; repeat with `MODULES_PROFILE=full` and confirm the nav is as before (use `frontend-design` skill only if any markup beyond the `v-if`s seems needed — it should not).

- [ ] **Step 5: Commit** — `feat(modules): hide navigation and links of disabled modules`.

---

### Task 8: Core-profile coverage, isolation matrix, CI smoke, docs, spec amendment

**Files:**
- Create: `tests/Feature/Modules/IsolationMatrixTest.php`, `tests/Feature/Modules/CoreProfileSmokeTest.php`
- Modify: `.github/workflows/ci.yml`, `.env.example`, `README.md`, `CLAUDE.md`, `docs/architecture.md`, `docs/three-server-setup.md`, `docs/superpowers/specs/2026-10-14-module-toggles-design.md`, `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`

- [ ] **Step 1: Isolation matrix test** — for every optional module, core + that module + its `requires` closure must boot and serve `/` and `/admin/login`:

```php
<?php

use App\Support\Modules\Module;
use Symfony\Component\Process\Process;

function requiresClosure(Module $m): array
{
    $out = [$m];
    foreach ($m->requires() as $r) {
        $out = [...$out, ...requiresClosure($r)];
    }

    return $out;
}

dataset('optional modules', fn () => array_map(
    fn (Module $m) => [$m],
    array_values(array_filter(Module::cases(), fn (Module $m) => ! $m->isCore())),
));

it('boots with one optional module on top of core', function (Module $module) {
    $env = ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing'];
    foreach (requiresClosure($module) as $m) {
        $env['MODULE_'.strtoupper($m->key())] = 'true';
    }

    foreach ([['lanomat:modules', '--json'], ['route:list', '--json'], ['schedule:list'], ['event:list']] as $args) {
        $p = new Process([PHP_BINARY, 'artisan', ...$args], base_path(), $env);
        $p->run();
        expect($p->isSuccessful())->toBeTrue("{$module->key()}: artisan ".implode(' ', $args).' failed: '.$p->getErrorOutput());
    }

    $probe = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute',
        '$k = app(Illuminate\Contracts\Http\Kernel::class); foreach (["/", "/admin/login", "/events"] as $u) { $r = $k->handle(Illuminate\Http\Request::create($u)); if ($r->getStatusCode() >= 500) { echo "FAIL $u ".$r->getStatusCode(); } }'],
        base_path(), $env);
    $probe->run();
    expect($probe->getOutput())->not->toContain('FAIL');
})->with('optional modules');
```

Run: `./vendor/bin/pest -d memory_limit=1G tests/Feature/Modules/IsolationMatrixTest.php`. Any failure reveals a missing guard or a missing `requires` entry: fix it in `Module::requires()` (preferred when the module genuinely calls the other) or add a §5-style guard, add a test for it, and re-run. This is the safety net for the optional→optional edges the baseline shows (Infoscreen/Discord/Presence reference many modules).

- [ ] **Step 2: Core smoke test** (`CoreProfileSmokeTest.php`): runs `php artisan lanomat:preflight --json`-equivalent under `core` and asserts no probe result is `down` because of a disabled module — check the command's real options first (`php artisan lanomat:preflight --help`); assert the probe key list equals `['database','redis','storage',…]` from Task 5's core set and that the process exits with a code your environment explains (DB/Redis are up in CI).

- [ ] **Step 3: CI smoke job.** In `.github/workflows/ci.yml` after the existing `./vendor/bin/pest` step add:

```yaml
      - name: Core-profile smoke (modules)
        run: ./vendor/bin/pest -d memory_limit=1G tests/Feature/Modules tests/Architecture
        env:
          MODULES_PROFILE: core
```

Do **not** run the whole suite under `core` (it assumes `full`); only the module tests, which spawn their own profiles. Also add `MODULES_PROFILE: full` to the existing `./vendor/bin/pest` step's `env:` to make the pinning explicit.

- [ ] **Step 4: `.env.example` and docs.** `.env.example`: add near the top

```
# Module toggles. `core` (default for fresh installs): events, registration,
# seating, teams, games, tournaments, identity, notifications, preflight.
# Existing installs that want everything: MODULES_PROFILE=full.
# Override single modules with MODULE_<KEY>=true|false, e.g. MODULE_JUKEBOX=true.
MODULES_PROFILE=core
```

Set `MODULES_PROFILE=full` in the repo's local dev `.env` (untracked; mention it in the PR description, do not commit). README `## Project state`: a short paragraph + the profile table. CLAUDE.md: add to *Architecture rules* "Optional modules sit behind `config/modules.php` toggles; an enabled module reaches an optional module only via an event, a contract with a null implementation, or a `Modules::enabled()` guard — the architecture ratchet enforces it." `docs/architecture.md`: module list marks core vs optional. `docs/three-server-setup.md` §2: note which compose services are skippable per profile (`discord-gateway` without Discord, voice sidecars without Voice, etc.).

- [ ] **Step 5: Amend the spec** with the three deviations from this plan's Global Constraints (bindings unconditional; commands stay registered, scheduler gated; static metadata in the enum; channels needed no gating) and the Wayfinder `routable()` rule, so spec and code agree. Update the roadmap's insights section with one line on toggles + the Wayfinder trap.

- [ ] **Step 6: Final verification.** `composer check`; the four frontend gates; `MODULES_PROFILE=core php artisan lanomat:modules` prints the 9 core modules; `docker compose --profile prod config -q` still valid.

- [ ] **Step 7: Commit, review, PR, merge** — `feat(modules): isolation matrix, core smoke job and docs` then the standard workflow (code review → fix → push → PR → merge).

---

## Self-review

**Spec coverage:** profiles/overrides/validation (Task 1) · enum metadata and dependency table (Task 1, re-checked by Task 8 matrix) · routes (4) · channels (4, no-op documented) · Filament (3) · scheduler/commands/listeners/policies/Preflight (5; commands deliberately stay registered) · Inertia prop + frontend gating (2, 7) · §5 guards table (6, plus baseline ratchet #2) · tests per profile, `phpunit.xml` pinning, CI smoke (1, 8) · `.env.example`/README/CLAUDE.md/docs (8) · config-cache risk (1) · Discord optional with login retained (Global Constraints, Task 5/6).
**Gaps closed beyond the spec:** Wayfinder generation trap (`routable()`), spec deviations written down and amended in Task 8.
**Type consistency:** `Module::key()`/`->name`, `Modules::enabled|routable|enabledModules`, `modulesReport()` helper, JSON keys `profile|enabled|routes|filament_resources|scheduled|preflight` are used identically in Tasks 1, 3, 4, 5, 8.
**Known soft spots to watch in execution:** exact Preflight probe `key()` strings (Task 5 step 1 tells the implementer to read them); the `hype.activePoll` / `news` prop shapes and notification constructor args (Task 6 step 1 tells the implementer to read existing tests first); the isolation matrix may legitimately surface extra `requires` entries or guards — that is its purpose.
