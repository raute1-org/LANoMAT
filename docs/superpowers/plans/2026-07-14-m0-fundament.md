# LANoMAT v3 — M0 Fundament: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Neues Repo `lanomat` mit Laravel 13, Discord-OAuth-Login, Rollen (admin/orga/participant), Filament-v5-Panel unter `/admin` (nur orga/admin) und grüner CI.

**Architecture:** Laravel-13-Monolith mit Vue-Starter-Kit (Inertia v2 + Vue 3 + Tailwind 4 + shadcn-vue). Identity als erstes Modul unter `app/Modules/Identity/`. Auth ausschließlich über Discord (Socialite), Session-basiert; Filament nutzt dieselbe Session.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5, laravel/socialite, Pest 4, Larastan, Pint, PostgreSQL 16, Redis 7, GitHub Actions.

## Global Constraints

- Code/Kommentare/Commits/Doku Englisch; Conventional Commits.
- Pint (Laravel-Preset) + Larastan Level 8 müssen nach jedem Task grün sein.
- Vue: `<script setup lang="ts">`, keine `<style>`-Blöcke, nur Tailwind + shadcn-vue.
- Alle Versionen beim Setup verifizieren (Stand Juli 2026: Laravel 13.x, Filament 5.x, Inertia 2.x); wenn ein Befehl/Paketname abweicht, der offiziellen Doku folgen und den Plan-Kommentar im Commit vermerken.
- Tests: Pest; Feature-Tests nutzen `RefreshDatabase` gegen die Compose-Postgres (`.env.testing` → eigene DB `lanomat_test`).

---

### Task 1: Repo + Laravel-13-Skeleton (Roadmap 0.1)

**Files:**
- Create: gesamtes Laravel-Skeleton im neuen Verzeichnis `lanomat/`

**Interfaces:**
- Produces: lauffähige Laravel-App mit Inertia v2/Vue 3/Tailwind 4/Pest; Basis für alle Folge-Tasks.

- [ ] **Step 1: Neues Repo anlegen**

```bash
mkdir lanomat && cd lanomat && git init -b main
```

- [ ] **Step 2: Laravel 13 mit Vue-Starter-Kit scaffolden**

```bash
composer global require laravel/installer
laravel new . --using=laravel/vue-starter-kit --pest --no-interaction
```

Falls der Installer das Flag anders nennt (Doku prüfen!): `laravel new .` interaktiv ausführen und **Vue**-Starter-Kit + **Pest** wählen.

- [ ] **Step 3: Verifizieren**

Run: `php artisan --version && php -v`
Expected: `Laravel Framework 13.x`, `PHP 8.4.x`

Run: `npm install && npm run build`
Expected: Vite-Build ohne Fehler

Run: `./vendor/bin/pest`
Expected: mitgelieferte Starter-Kit-Tests PASS

- [ ] **Step 4: Auth-Scaffolding des Starter-Kits ausdünnen**

Das Starter-Kit bringt E-Mail/Passwort-Registrierung mit. Wir behalten nur die Session-Infrastruktur: Routen/Seiten für `register`, `forgot-password`, `reset-password`, `verify-email`, `confirm-password` aus `routes/auth.php` und `resources/js/pages/auth/` entfernen; `login`-Seite bleibt vorerst (wird in Task 5 durch Discord-Button ersetzt). Zugehörige Tests entfernen.

Run: `./vendor/bin/pest`
Expected: PASS (nur noch verbleibende Tests)

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: scaffold Laravel 13 app with Vue starter kit (Inertia, Tailwind, Pest)"
```

---

### Task 2: Dev-Infrastruktur — Compose + Env (Roadmap 0.2)

**Files:**
- Create: `compose.yml`, `.env.testing`
- Modify: `.env.example`, `.env`

**Interfaces:**
- Produces: `docker compose up -d` liefert Postgres (`lanomat`/`lanomat_test`) und Redis; App läuft via `composer run dev`.

- [ ] **Step 1: compose.yml schreiben**

```yaml
name: lanomat

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: lanomat
      POSTGRES_PASSWORD: lanomat
      POSTGRES_DB: lanomat
    ports: ['5432:5432']
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./docker/postgres/init-test-db.sql:/docker-entrypoint-initdb.d/init-test-db.sql
    healthcheck:
      test: ['CMD-SHELL', 'pg_isready -U lanomat']
      interval: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    ports: ['6379:6379']

volumes:
  pgdata:
```

- [ ] **Step 2: Test-DB-Init anlegen**

Create `docker/postgres/init-test-db.sql`:

```sql
CREATE DATABASE lanomat_test OWNER lanomat;
```

- [ ] **Step 3: Env-Dateien**

`.env` und `.env.example`: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=lanomat`, `DB_USERNAME=lanomat`, `DB_PASSWORD=lanomat`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST=127.0.0.1`.

Create `.env.testing` (wie `.env`, aber `DB_DATABASE=lanomat_test`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`).

- [ ] **Step 4: Verifizieren**

Run: `docker compose up -d --wait && php artisan migrate:fresh && ./vendor/bin/pest`
Expected: Migrationen laufen gegen Postgres, Tests PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add docker compose dev stack (postgres, redis) and env templates"
```

---

### Task 3: Qualitäts-Tooling + CI (Roadmap 0.3)

**Files:**
- Create: `phpstan.neon`, `.github/workflows/ci.yml`
- Modify: `composer.json` (scripts), `pint.json` (falls nicht vorhanden)

**Interfaces:**
- Produces: `composer check` (pint --test, phpstan, pest) und CI-Workflow, den jede spätere Phase grün halten muss.

- [ ] **Step 1: Larastan installieren**

```bash
composer require --dev larastan/larastan
```

- [ ] **Step 2: phpstan.neon**

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    level: 8
    paths:
        - app
```

- [ ] **Step 3: composer.json Scripts ergänzen**

```json
"scripts": {
    "check": [
        "./vendor/bin/pint --test",
        "./vendor/bin/phpstan analyse --memory-limit=1G",
        "./vendor/bin/pest"
    ]
}
```

- [ ] **Step 4: CI-Workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI
on:
  push: { branches: [main] }
  pull_request:

jobs:
  php:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16-alpine
        env: { POSTGRES_USER: lanomat, POSTGRES_PASSWORD: lanomat, POSTGRES_DB: lanomat_test }
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U lanomat" --health-interval 5s --health-retries 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: 'pgsql, redis, sodium' }
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.testing .env && php artisan key:generate
      - run: ./vendor/bin/pint --test
      - run: ./vendor/bin/phpstan analyse --memory-limit=1G
      - run: php artisan migrate --force && ./vendor/bin/pest

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: npm }
      - run: npm ci
      - run: npm run lint
      - run: npm run build
```

- [ ] **Step 5: Lokal verifizieren**

Run: `composer check`
Expected: pint PASS, phpstan 0 errors, pest PASS

- [ ] **Step 6: Commit + Push (Repo auf GitHub anlegen)**

```bash
gh repo create lanomat --private --source=. --push
git add -A && git commit -m "ci: add pint, larastan level 8 and github actions pipeline"
git push
```

Expected: CI-Lauf auf GitHub grün.

---

### Task 4: User-Modell — discord_id, role, avatar_url (Roadmap 0.4, 0.6)

**Files:**
- Create: `app/Enums/Role.php`, `database/migrations/<ts>_add_identity_fields_to_users_table.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Test: `tests/Unit/Identity/RoleTest.php`

**Interfaces:**
- Produces: `App\Enums\Role` (`Admin|Orga|Participant`, backed string `admin|orga|participant`), `User::$discord_id`, `User::$role` (cast auf `Role`), `User::isAdmin(): bool`, `User::isOrga(): bool` (true auch für Admin). Task 5–8 verlassen sich auf exakt diese Namen.

- [ ] **Step 1: Failing Test für Role-Enum + User-Helpers**

Create `tests/Unit/Identity/RoleTest.php`:

```php
<?php

use App\Enums\Role;
use App\Models\User;

it('grants orga capabilities to admins', function () {
    $admin = User::factory()->make(['role' => Role::Admin]);
    $orga = User::factory()->make(['role' => Role::Orga]);
    $participant = User::factory()->make(['role' => Role::Participant]);

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->isOrga())->toBeTrue()
        ->and($orga->isAdmin())->toBeFalse()
        ->and($orga->isOrga())->toBeTrue()
        ->and($participant->isOrga())->toBeFalse();
});
```

- [ ] **Step 2: Test läuft rot**

Run: `./vendor/bin/pest tests/Unit/Identity/RoleTest.php`
Expected: FAIL — `Class "App\Enums\Role" not found`

- [ ] **Step 3: Enum, Migration, Model**

Create `app/Enums/Role.php`:

```php
<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Orga = 'orga';
    case Participant = 'participant';
}
```

Create migration `add_identity_fields_to_users_table`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('discord_id')->unique()->nullable();
            $table->string('role')->default('participant');
            $table->string('avatar_url')->nullable();
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['discord_id', 'role', 'avatar_url']);
        });
    }
};
```

Modify `app/Models/User.php` — in `$fillable` aufnehmen: `discord_id`, `role`, `avatar_url`; Cast + Helpers:

```php
use App\Enums\Role;

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => Role::class,
    ];
}

public function isAdmin(): bool
{
    return $this->role === Role::Admin;
}

public function isOrga(): bool
{
    return in_array($this->role, [Role::Admin, Role::Orga], true);
}
```

Modify `database/factories/UserFactory.php` — im `definition()`-Array ergänzen:

```php
'discord_id' => (string) fake()->unique()->numerify('9########'),
'role' => Role::Participant,
'avatar_url' => null,
```

plus State-Methoden:

```php
public function admin(): static
{
    return $this->state(['role' => Role::Admin]);
}

public function orga(): static
{
    return $this->state(['role' => Role::Orga]);
}
```

- [ ] **Step 4: Test grün**

Run: `php artisan migrate && ./vendor/bin/pest tests/Unit/Identity/RoleTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(identity): add discord_id, role enum and avatar_url to users"
```

---

### Task 5: Discord-OAuth via Socialite (Roadmap 0.5)

**Files:**
- Create: `app/Modules/Identity/Http/DiscordAuthController.php`, `app/Modules/Identity/Actions/UpsertUserFromDiscord.php`
- Modify: `config/services.php`, `routes/auth.php`, `composer.json` (PSR-4 bleibt `App\` — `app/Modules` liegt darunter), `resources/js/pages/auth/Login.vue`, `.env.example`
- Test: `tests/Feature/Identity/DiscordLoginTest.php`

**Interfaces:**
- Consumes: `User` + `Role` aus Task 4.
- Produces: Routen `GET /auth/discord/redirect` (name: `login.discord`) und `GET /auth/discord/callback`; `UpsertUserFromDiscord::handle(string $discordId, string $username, ?string $avatarUrl, ?string $email): User`.

- [ ] **Step 1: Socialite installieren + Config**

```bash
composer require laravel/socialite
```

`config/services.php` ergänzen:

```php
'discord' => [
    'client_id' => env('DISCORD_CLIENT_ID'),
    'client_secret' => env('DISCORD_CLIENT_SECRET'),
    'redirect' => env('DISCORD_REDIRECT_URI', env('APP_URL').'/auth/discord/callback'),
],
```

`.env.example`: `DISCORD_CLIENT_ID=`, `DISCORD_CLIENT_SECRET=`, `DISCORD_REDIRECT_URI=http://localhost:8000/auth/discord/callback`, `DISCORD_BOT_TOKEN=` (Bot-Token schon jetzt dokumentieren, genutzt ab M2).

**Hinweis:** Socialite core hat keinen Discord-Provider — `composer require socialiteproviders/discord` und den Event-Listener laut SocialiteProviders-Doku in `AppServiceProvider` registrieren:

```php
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;

Event::listen(function (SocialiteWasCalled $event) {
    $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
});
```

- [ ] **Step 2: Failing Feature-Test**

Create `tests/Feature/Identity/DiscordLoginTest.php`:

```php
<?php

use App\Enums\Role;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeDiscordUser(string $id = '123456789', string $name = 'TestUser'): SocialiteUser
{
    $user = new SocialiteUser();
    $user->map([
        'id' => $id,
        'nickname' => $name,
        'name' => $name,
        'email' => 'test@example.com',
        'avatar' => 'https://cdn.discordapp.com/avatars/123/abc.png',
    ]);

    return $user;
}

it('redirects to discord', function () {
    $response = $this->get('/auth/discord/redirect');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('discord.com');
})->skip(fn () => empty(config('services.discord.client_id')), 'needs client id in env');

it('creates a participant user on first discord login', function () {
    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser());

    $this->get('/auth/discord/callback')->assertRedirect('/');

    $user = User::where('discord_id', '123456789')->firstOrFail();
    expect($user->role)->toBe(Role::Participant)
        ->and($user->name)->toBe('TestUser')
        ->and($user->avatar_url)->toContain('cdn.discordapp.com');
    $this->assertAuthenticatedAs($user);
});

it('reuses the existing user and keeps their role on relogin', function () {
    $existing = User::factory()->admin()->create(['discord_id' => '123456789']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeDiscordUser(name: 'RenamedUser'));

    $this->get('/auth/discord/callback');

    expect(User::count())->toBe(1)
        ->and($existing->refresh()->role)->toBe(Role::Admin)
        ->and($existing->name)->toBe('RenamedUser');
});
```

- [ ] **Step 3: Test läuft rot**

Run: `./vendor/bin/pest tests/Feature/Identity/DiscordLoginTest.php`
Expected: FAIL — Route not found (404)

- [ ] **Step 4: Action + Controller + Routen**

Create `app/Modules/Identity/Actions/UpsertUserFromDiscord.php`:

```php
<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;

class UpsertUserFromDiscord
{
    public function handle(string $discordId, string $username, ?string $avatarUrl, ?string $email): User
    {
        return User::updateOrCreate(
            ['discord_id' => $discordId],
            ['name' => $username, 'avatar_url' => $avatarUrl, 'email' => $email],
        );
    }
}
```

Create `app/Modules/Identity/Http/DiscordAuthController.php`:

```php
<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Actions\UpsertUserFromDiscord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class DiscordAuthController
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('discord')->redirect();
    }

    public function callback(UpsertUserFromDiscord $upsert): RedirectResponse
    {
        $discordUser = Socialite::driver('discord')->user();

        $user = $upsert->handle(
            discordId: (string) $discordUser->getId(),
            username: $discordUser->getNickname() ?? $discordUser->getName() ?? 'Unknown',
            avatarUrl: $discordUser->getAvatar(),
            email: $discordUser->getEmail(),
        );

        Auth::login($user, remember: true);

        return redirect('/');
    }
}
```

`routes/auth.php` (Guest-Gruppe) ergänzen:

```php
use App\Modules\Identity\Http\DiscordAuthController;

Route::get('auth/discord/redirect', [DiscordAuthController::class, 'redirect'])->name('login.discord');
Route::get('auth/discord/callback', [DiscordAuthController::class, 'callback']);
```

- [ ] **Step 5: Test grün**

Run: `./vendor/bin/pest tests/Feature/Identity/DiscordLoginTest.php`
Expected: PASS (Redirect-Test ggf. SKIPPED ohne Client-ID)

- [ ] **Step 6: Login-Seite auf Discord-Button reduzieren**

`resources/js/pages/auth/Login.vue`: Formular durch einen shadcn-vue-`Button` ersetzen (Link auf `/auth/discord/redirect`, Discord-Logo-SVG inline, Text „Mit Discord anmelden"). E-Mail/Passwort-Felder entfernen.

Run: `npm run lint && npm run build`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(identity): discord oauth login via socialite"
```

---

### Task 6: Autorisierung — Gate::before + EnsureRole-Middleware (Roadmap 0.6)

**Files:**
- Create: `app/Http/Middleware/EnsureRole.php`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`
- Test: `tests/Feature/Identity/AuthorizationTest.php`

**Interfaces:**
- Produces: Middleware-Alias `role` (`->middleware('role:orga')` akzeptiert orga+admin, `role:admin` nur admin); global `Gate::before` lässt Admins alles.

- [ ] **Step 1: Failing Test**

Create `tests/Feature/Identity/AuthorizationTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/orga-only', fn () => 'ok')->middleware(['web', 'auth', 'role:orga']);
});

it('blocks participants from orga routes', function () {
    $this->actingAs(User::factory()->create())
        ->get('/_test/orga-only')
        ->assertForbidden();
});

it('allows orga and admin on orga routes', function (string $factory) {
    $this->actingAs(User::factory()->{$factory}()->create())
        ->get('/_test/orga-only')
        ->assertOk();
})->with(['orga', 'admin']);
```

- [ ] **Step 2: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Identity/AuthorizationTest.php`
Expected: FAIL — `Target class [role] does not exist`

- [ ] **Step 3: Middleware + Registrierung**

Create `app/Http/Middleware/EnsureRole.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        $allowed = match (Role::from($role)) {
            Role::Admin => $user?->isAdmin() ?? false,
            Role::Orga => $user?->isOrga() ?? false,
            Role::Participant => $user !== null,
        };

        abort_unless($allowed, 403);

        return $next($request);
    }
}
```

`bootstrap/app.php` im `withMiddleware`-Block:

```php
$middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
```

`app/Providers/AppServiceProvider.php` in `boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::before(fn ($user) => $user->isAdmin() ? true : null);
```

- [ ] **Step 4: Grün + Commit**

Run: `./vendor/bin/pest tests/Feature/Identity/AuthorizationTest.php`
Expected: PASS

```bash
git add -A && git commit -m "feat(identity): role middleware and admin gate"
```

---

### Task 7: Filament v5 Panel (Roadmap 0.7)

**Files:**
- Create: `app/Providers/Filament/AdminPanelProvider.php` (generiert)
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Identity/AdminPanelAccessTest.php`

**Interfaces:**
- Produces: Panel-ID `admin` unter `/admin`; `User implements FilamentUser` mit `canAccessPanel()` = `isOrga()`. Alle künftigen Filament-Resources docken hier an.

- [ ] **Step 1: Installieren**

```bash
composer require filament/filament
php artisan filament:install --panels
```

(Panel-ID `admin`, Pfad `/admin` — Defaults bestätigen.)

- [ ] **Step 2: Failing Test**

Create `tests/Feature/Identity/AdminPanelAccessTest.php`:

```php
<?php

use App\Models\User;

it('blocks participants from the admin panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('allows orga and admin into the admin panel', function (string $factory) {
    $this->actingAs(User::factory()->{$factory}()->create())
        ->get('/admin')
        ->assertOk();
})->with(['orga', 'admin']);

it('sends guests to the app login, not a filament login', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Identity/AdminPanelAccessTest.php`
Expected: FAIL — participants bekommen 200 bzw. Redirect auf Filament-Login

- [ ] **Step 4: Zugriff einschränken**

`app/Models/User.php`:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isOrga();
    }
}
```

`AdminPanelProvider`: `->login()` entfernen (kein Filament-eigenes Login; Guests laufen über die normale `auth`-Middleware auf `route('login')`); Panel-Middleware prüfen, dass `Authenticate::class` die App-Login-Route nutzt.

- [ ] **Step 5: Grün + statische Analyse + Commit**

Run: `./vendor/bin/pest tests/Feature/Identity/AdminPanelAccessTest.php && composer check`
Expected: PASS

```bash
git add -A && git commit -m "feat(admin): filament panel restricted to orga and admin roles"
```

---

### Task 8: lanomat:install-Command (Roadmap 0.8)

**Files:**
- Create: `app/Console/Commands/InstallCommand.php`
- Test: `tests/Feature/InstallCommandTest.php`

**Interfaces:**
- Produces: `php artisan lanomat:install --admin-discord-id=<id> [--admin-name=<name>]` — migriert und legt Admin an (idempotent: bestehender User wird zum Admin befördert).

- [ ] **Step 1: Failing Test**

Create `tests/Feature/InstallCommandTest.php`:

```php
<?php

use App\Enums\Role;
use App\Models\User;

it('creates an admin user from a discord id', function () {
    $this->artisan('lanomat:install', ['--admin-discord-id' => '42', '--admin-name' => 'Boss'])
        ->assertSuccessful();

    expect(User::where('discord_id', '42')->firstOrFail()->role)->toBe(Role::Admin);
});

it('promotes an existing user instead of duplicating', function () {
    User::factory()->create(['discord_id' => '42']);

    $this->artisan('lanomat:install', ['--admin-discord-id' => '42'])->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(User::firstOrFail()->role)->toBe(Role::Admin);
});
```

- [ ] **Step 2: Rot**

Run: `./vendor/bin/pest tests/Feature/InstallCommandTest.php`
Expected: FAIL — command not found

- [ ] **Step 3: Command**

Create `app/Console/Commands/InstallCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'lanomat:install {--admin-discord-id=} {--admin-name=Admin}';

    protected $description = 'Run migrations and create the initial admin user';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        $discordId = $this->option('admin-discord-id');

        if (is_string($discordId) && $discordId !== '') {
            $user = User::firstOrNew(['discord_id' => $discordId]);
            $user->name ??= (string) $this->option('admin-name');
            $user->role = Role::Admin;
            $user->save();

            $this->info("Admin ready: {$user->name} ({$discordId})");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Grün + Commit**

Run: `./vendor/bin/pest tests/Feature/InstallCommandTest.php`
Expected: PASS

```bash
git add -A && git commit -m "feat: lanomat:install command for migrations and initial admin"
```

---

### Task 9: Modul-Konvention + Repo-Doku (Roadmap 0.9)

**Files:**
- Create: `CLAUDE.md`, `docs/architecture.md`
- Modify: `README.md`

**Interfaces:**
- Produces: verbindliche Modul-Konvention für M1–M6; Onboarding-Doku.

- [ ] **Step 1: CLAUDE.md schreiben**

Inhalt (Englisch): Projektüberblick (eine Zeile), Verweis auf `docs/architecture.md`, Kommandos (`composer run dev`, `composer check`, `./vendor/bin/pest -- --filter=…`, `docker compose up -d`), Modul-Konvention (`app/Modules/<Name>/{Models,Actions,Policies,Filament,Jobs,Events,Contracts}`; Tests gespiegelt unter `tests/{Feature,Unit}/<Name>/`), Regeln: Policies für jede Autorisierung, externe Systeme nur über Contracts + Fakes, Conventional Commits, Pint/Larastan/Pest müssen grün sein, UI-Texte über `lang/de/`.

- [ ] **Step 2: docs/architecture.md**

Kurzfassung des Design-Dokuments (Modulliste + Phasenplan-Link auf die Roadmap im Alt-Repo bzw. Kopie derselben), Datenmodell-Skizze aus dem Design übernehmen.

- [ ] **Step 3: README.md**

Setup-Anleitung: Requirements (PHP 8.4, Node 22, Docker), `docker compose up -d`, `composer install && npm install`, `.env` konfigurieren (Discord-App-Anleitung mit Redirect-URI), `php artisan lanomat:install --admin-discord-id=…`, `composer run dev`.

- [ ] **Step 4: Abschluss-Verifikation M0**

Run: `composer check && npm run lint && npm run build`
Expected: alles PASS

Manuell (Discord-App vorausgesetzt): Login-Flow im Browser durchspielen → User in DB, `/admin` erst nach `lanomat:install`-Beförderung erreichbar.

- [ ] **Step 5: Commit + Tag**

```bash
git add -A && git commit -m "docs: module conventions, architecture overview and setup guide"
git tag m0 && git push --tags
```

---

## Abnahme-Checkliste M0

- [ ] CI grün (php + frontend Jobs)
- [ ] Discord-Login erzeugt Participant-User, Relogin dupliziert nicht, Rolle bleibt erhalten
- [ ] `/admin`: 403 für Participant, erreichbar für Orga/Admin, Guest → App-Login
- [ ] `lanomat:install` idempotent
- [ ] `composer check` lokal grün
