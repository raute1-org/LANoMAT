# LANoMAT v2 — M1 Events & Identity: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Orga legt ein `Event` an und führt es durch den Lifecycle `draft → announced → registration → live → finished → archived` (Filament-Panel unter `/admin/events` mit Status-Action-Buttons). Teilnehmer sehen die öffentliche Event-Seite, ein Archiv vergangener Events und pflegen ihr Profil. Der „aktuelles Event"-Kontext ist als Inertia-Shared-Prop `currentEvent` in der gesamten Teilnehmer-UI verfügbar.

**Architecture:** Zweites und drittes Modul im modularen Monolithen: `app/Modules/Events/` (Event, EventStatus, TransitionEventStatus, CurrentEvent, EventResource) und Erweiterung von `app/Modules/Identity/` um Profil (`UpdateProfile`). Der Event-Lifecycle liegt als reine Domain-Logik in einer erlaubten Übergangs-Map; die Filament-Buttons und (später in M2) die Registrierung sind nur Aufrufer der `TransitionEventStatus`-Action. Teilnehmer-UI via Inertia v2 / Vue 3 / Tailwind v4 / shadcn-vue; UI-Texte Deutsch über `lang/de/*.php` und als Props durchgereicht (keine hartkodierten Strings in Komponenten).

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5, Inertia v2, Vue 3, Tailwind v4, shadcn-vue, Pest 4, Larastan Level 8, Pint, PostgreSQL 16.

## Global Constraints (aus der Roadmap, für jeden Task dieser Phase)

- Code/Kommentare/Commits/Doku **Englisch**; UI-Texte **Deutsch** über `lang/de/*.php`, in Vue-Komponenten ausschließlich als Props/Übersetzungsschlüssel — keine hartkodierten deutschen Strings in `.vue`-Dateien.
- Conventional Commits (`feat(events): …`, `feat(identity): …`); häufige, kleine Commits (ein Commit pro Task).
- PHP: Pint (Laravel-Preset), Larastan Level 8, keine `mixed`-Rückgaben in eigenem Code, Enums statt Magic-Strings.
- Vue: `<script setup lang="ts">`, **keine** `<style>`-Blöcke, nur Tailwind + shadcn-vue.
- Jede Autorisierung über Policies / Gates; nie Client-gelieferte User-IDs verwenden (Profil-Update immer `$request->user()`).
- Modul-Grenzen: `app/Modules/Events/{Models,Actions,Policies,Filament}`; kein Modul greift in die Tabellen eines anderen. Tests gespiegelt unter `tests/{Feature,Unit}/Events/` bzw. `tests/{Feature,Unit}/Identity/`.
- Qualitäts-Gate **nach jedem Task**: `composer check` (pint --test, phpstan level 8, pest) grün; bei Frontend-Tasks zusätzlich `npm run lint && npm run build`.
- Uploads (kein M1-Thema, aber Regel): Laravel Storage, nie Base64. `profile_color` ist ein Hex-String, kein Upload.
- **2026-Best-Practices-Regel:** Vor dem Verwenden einer Framework-API (Filament-Resource-Discovery/Actions, Inertia-Shared-Props, Enum-Casts, `Str::slug()`) die aktuelle offizielle Doku über **laravel-boost**/**context7**-MCP oder WebFetch verifizieren. Bei Abweichung der Doku folgen und die Abweichung im Commit-Message-Body vermerken. Konkrete Doku-Seiten sind in den betreffenden Steps genannt.
- **First-party-only:** Für den Event-Slug wird **kein** Drittanbieter-Paket verwendet (Entscheidung des Controllers, gemäß CLAUDE.md „Prefer first-party"). Slug-Generierung ausschließlich über `Illuminate\Support\Str::slug()` in einem typisierten `booted()`/`static::creating()`-Model-Hook, Eindeutigkeit über eine Zähler-Schleife (`slug`, `slug-2`, `slug-3`, …) gegen die `events`-Tabelle. Der Slug ist nach Erstellung unveränderlich (wird bei Namensänderung nicht neu generiert).

## Voraussetzungen aus M0 (dürfen als vorhanden angenommen werden)

- App im Repo-Root; Branch `main`; `composer check`-Script vorhanden und grün (Tag `m0`).
- `App\Models\User` mit `discord_id`, `role` (Cast `App\Enums\Role`), `avatar_url`; `User::isAdmin()`, `User::isOrga()` (true auch für Admin); `UserFactory` mit `->admin()`/`->orga()`-States.
- Discord-OAuth-Login (Socialite) aktiv; Fortify-Session; E-Mail/Passwort-Flows entfernt.
- Filament-v5-Panel-ID `admin` unter `/admin`; `AdminPanelProvider` vorhanden; Zugriff = `isOrga()`; kein Filament-eigenes Login (Guests → `route('login')`).
- Postgres 16 via Compose (Host-Port 5434); Test-DB `lanomat_test` via `.env.testing`; Feature-Tests nutzen `RefreshDatabase`.
- Inertia v2 + Vue 3 + Tailwind 4 + shadcn-vue; `app/Http/Middleware/HandleInertiaRequests.php` existiert (vom Starter-Kit).
- Middleware-Alias `role` (`role:orga`, `role:admin`) und globaler `Gate::before` für Admin vorhanden.

---

### Task 1: Event-Model + Migration + Factory (Roadmap 1.1)

**Files:**
- Create: `database/migrations/<ts>_create_events_table.php`, `app/Modules/Events/Models/Event.php`, `database/factories/EventFactory.php`
- Test: `tests/Unit/Events/EventModelTest.php`

**Interfaces:**
- Produces: `App\Modules\Events\Models\Event` mit Feldern `name, slug (unique), status (cast EventStatus, siehe Task 2), location, starts_at (datetime), ends_at (datetime), max_participants (int, nullable), settings (array-cast jsonb)`. Auto-Slug aus `name` via `Str::slug()` + Kollisions-Suffix (first-party, kein Drittanbieter-Paket), unveränderlich nach Erstellung. `Event::factory()` mit State-Methoden pro Status. Task 2–6 bauen darauf auf.

> **Reihenfolge-Hinweis:** Die Spalte `status` referenziert das `EventStatus`-Enum aus Task 2. Um TDD sauber zu halten, legt dieser Task das Enum als *minimalen* Platzhalter mit allen sechs Cases an; die Übergangs-Map und die Action folgen in Task 2. Alternativ Task 1 und 2 als ein zusammenhängendes Arbeitspaket behandeln.

- [ ] **Step 1: EventStatus-Enum (minimal, nur Cases) anlegen**

Create `app/Modules/Events/Enums/EventStatus.php`:

```php
<?php

namespace App\Modules\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Announced = 'announced';
    case Registration = 'registration';
    case Live = 'live';
    case Finished = 'finished';
    case Archived = 'archived';
}
```

- [ ] **Step 2: Failing Test für Model-Basics (Slug + Casts)**

Create `tests/Unit/Events/EventModelTest.php`:

```php
<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

it('generates a slug from the name', function () {
    $event = Event::factory()->create(['name' => 'Winter LAN 2027']);

    expect($event->slug)->toBe('winter-lan-2027');
});

it('appends a counter suffix when the slug already exists', function () {
    $a = Event::factory()->create(['name' => 'Winter LAN 2027']);
    $b = Event::factory()->create(['name' => 'Winter LAN 2027']);
    $c = Event::factory()->create(['name' => 'Winter LAN 2027']);

    expect($a->slug)->toBe('winter-lan-2027')
        ->and($b->slug)->toBe('winter-lan-2027-2')
        ->and($c->slug)->toBe('winter-lan-2027-3');
});

it('keeps the slug unchanged when the name is updated later', function () {
    $event = Event::factory()->create(['name' => 'Winter LAN 2027']);

    $event->update(['name' => 'Renamed LAN']);

    expect($event->fresh())
        ->slug->toBe('winter-lan-2027')
        ->name->toBe('Renamed LAN');
});

it('casts status to the EventStatus enum and settings to an array', function () {
    $event = Event::factory()->create([
        'status' => EventStatus::Registration,
        'settings' => ['tickets' => ['standard']],
    ]);

    expect($event->fresh()->status)->toBe(EventStatus::Registration)
        ->and($event->fresh()->settings)->toBe(['tickets' => ['standard']]);
});

it('casts starts_at and ends_at to Carbon instances', function () {
    $event = Event::factory()->create();

    expect($event->starts_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($event->ends_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Unit/Events/EventModelTest.php`
Expected: FAIL — `Class "App\Modules\Events\Models\Event" not found`

- [ ] **Step 4: Migration**

Create migration `create_events_table` (`php artisan make:migration create_events_table`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('draft');
            $table->string('location')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
```

- [ ] **Step 5: Model mit Auto-Slug (first-party, kein Drittanbieter-Paket)**

Verify first (2026): `Illuminate\Support\Str::slug()`-Signatur über laravel-boost/context7 („laravel/framework" Str-Helper) bestätigen — keine neue Dependency nötig, das Helper ist Teil des Frameworks.

> **Entscheidung des Controllers:** Kein `spatie/laravel-sluggable`, keine sonstige Drittanbieter-Slug-Library (CLAUDE.md „Prefer first-party"). Stattdessen: `Str::slug($name)` in einem typisierten `booted()`/`static::creating()`-Hook, Eindeutigkeit durch eine Zähler-Schleife (`slug`, `slug-2`, `slug-3`, …), die gegen die `events`-Tabelle prüft. Der Slug wird **nur bei der Erstellung** gesetzt und bei einer späteren Namensänderung **nicht** neu generiert (Slug ist nach Erstellung unveränderlich).

Create `app/Modules/Events/Models/Event.php`:

```php
<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'location',
        'starts_at',
        'ends_at',
        'max_participants',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_participants' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event): void {
            if (blank($event->slug)) {
                $event->slug = self::uniqueSlugFrom($event->name);
            }
        });
    }

    private static function uniqueSlugFrom(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
```

> **Hinweis:** Der `creating`-Hook generiert den Slug nur, wenn `slug` nicht bereits gesetzt ist — die Factory darf `slug` daher nicht hart setzen. Der Slug bleibt danach für die Lebensdauer des Datensatzes unverändert; ein Rename des `name`-Felds regeneriert ihn **nicht** (kein Hook auf `updating`). Auto-Increment-`id` bleibt Standard (Migration nutzt `$table->id()`).

- [ ] **Step 6: Factory**

Create `database/factories/EventFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 month', '+3 months');

        return [
            'name' => fake()->unique()->words(2, true).' LAN '.fake()->year(),
            // slug intentionally omitted: generated by the Event::booted() creating-hook
            'status' => EventStatus::Draft,
            'location' => fake()->city(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+2 days'),
            'max_participants' => fake()->numberBetween(50, 200),
            'settings' => [],
        ];
    }

    public function status(EventStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function draft(): static
    {
        return $this->status(EventStatus::Draft);
    }

    public function announced(): static
    {
        return $this->status(EventStatus::Announced);
    }

    public function registration(): static
    {
        return $this->status(EventStatus::Registration);
    }

    public function live(): static
    {
        return $this->status(EventStatus::Live);
    }

    public function finished(): static
    {
        return $this->status(EventStatus::Finished);
    }

    public function archived(): static
    {
        return $this->status(EventStatus::Archived);
    }
}
```

- [ ] **Step 7: Grün + Gate**

Run: `php artisan migrate && ./vendor/bin/pest tests/Unit/Events/EventModelTest.php`
Expected: PASS

Run: `composer check`
Expected: pint PASS, phpstan 0 errors, pest PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(events): add Event model, migration, factory with auto-slug"
```

---

### Task 2: EventStatus-Übergangs-Map + TransitionEventStatus-Action (Roadmap 1.2)

**Files:**
- Modify: `app/Modules/Events/Enums/EventStatus.php` (Übergangs-Map + Helfer)
- Create: `app/Modules/Events/Actions/TransitionEventStatus.php`, `app/Modules/Events/Events/EventStatusChanged.php`
- Test: `tests/Unit/Events/EventStatusTransitionTest.php`

**Interfaces:**
- Consumes: `Event`, `EventStatus` aus Task 1.
- Produces:
  - `EventStatus::allowedTransitions(): array<int, EventStatus>` (Nachfolger des aktuellen Status),
  - `EventStatus::canTransitionTo(EventStatus $to): bool`,
  - `TransitionEventStatus::handle(Event $event, EventStatus $to): Event` — persistiert bei erlaubtem Übergang und feuert `EventStatusChanged`; wirft `DomainException` bei ungültigem Übergang.
  - Event-Klasse `App\Modules\Events\Events\EventStatusChanged` (payload: `Event $event`, `EventStatus $from`, `EventStatus $to`). Wird in M2 (Discord-Announcements) konsumiert — hier nur gefeuert, kein Listener.

> **Übergangs-Map — aus dem Design abgeleitet.** Das Design-Dokument (Abschnitt 6, „Kern-Entität: Event") definiert den Lifecycle ausschließlich als linearen Vorwärtspfad `draft → announced → registration → live → finished → archived`. Es nennt **keine** Rückwärts-/Rollback-Kanten (z. B. `registration → announced`). YAGNI: Wir implementieren daher **genau den linearen Vorwärtspfad**, kein Überspringen, keine Rückwärtskante. `archived` ist terminal. Sollte sich später ein Rollback-Bedarf zeigen, wird die Map (und ein Test dafür) erweitert und die Roadmap als lebendes Dokument nachgezogen.

Vollständige erlaubte Kanten:

| von | erlaubte Ziele |
|---|---|
| `draft` | `announced` |
| `announced` | `registration` |
| `registration` | `live` |
| `live` | `finished` |
| `finished` | `archived` |
| `archived` | — (terminal) |

- [ ] **Step 1: Failing Test — alle Kanten (erlaubt + verboten)**

Create `tests/Unit/Events/EventStatusTransitionTest.php`:

```php
<?php

use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Facades\Event as EventFacade;

/** The only edges the design permits (linear forward path). */
function allowedEdges(): array
{
    return [
        [EventStatus::Draft, EventStatus::Announced],
        [EventStatus::Announced, EventStatus::Registration],
        [EventStatus::Registration, EventStatus::Live],
        [EventStatus::Live, EventStatus::Finished],
        [EventStatus::Finished, EventStatus::Archived],
    ];
}

/** Every (from, to) pair that is NOT an allowed edge and not a no-op. */
function forbiddenEdges(): array
{
    $allowed = array_map(fn ($e) => $e[0]->value.'->'.$e[1]->value, allowedEdges());
    $pairs = [];
    foreach (EventStatus::cases() as $from) {
        foreach (EventStatus::cases() as $to) {
            if ($from === $to) {
                continue;
            }
            if (in_array($from->value.'->'.$to->value, $allowed, true)) {
                continue;
            }
            $pairs[] = [$from, $to];
        }
    }

    return $pairs;
}

it('allows every forward edge and persists the new status', function (EventStatus $from, EventStatus $to) {
    EventFacade::fake([EventStatusChanged::class]);
    $event = Event::factory()->status($from)->create();

    $result = app(TransitionEventStatus::class)->handle($event, $to);

    expect($result->status)->toBe($to)
        ->and($event->fresh()->status)->toBe($to);

    EventFacade::assertDispatched(EventStatusChanged::class, fn ($e) => $e->from === $from && $e->to === $to);
})->with(allowedEdges());

it('rejects every non-allowed transition with a DomainException', function (EventStatus $from, EventStatus $to) {
    $event = Event::factory()->status($from)->create();

    expect(fn () => app(TransitionEventStatus::class)->handle($event, $to))
        ->toThrow(DomainException::class);

    expect($event->fresh()->status)->toBe($from);
})->with(forbiddenEdges());

it('rejects a transition to the same status', function () {
    $event = Event::factory()->live()->create();

    expect(fn () => app(TransitionEventStatus::class)->handle($event, EventStatus::Live))
        ->toThrow(DomainException::class);
});

it('exposes allowed transitions per status', function () {
    expect(EventStatus::Draft->allowedTransitions())->toBe([EventStatus::Announced])
        ->and(EventStatus::Archived->allowedTransitions())->toBe([])
        ->and(EventStatus::Registration->canTransitionTo(EventStatus::Live))->toBeTrue()
        ->and(EventStatus::Registration->canTransitionTo(EventStatus::Draft))->toBeFalse();
});
```

- [ ] **Step 2: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Unit/Events/EventStatusTransitionTest.php`
Expected: FAIL — `Call to undefined method …allowedTransitions()` bzw. Action-Klasse fehlt.

- [ ] **Step 3: Übergangs-Map ins Enum**

Modify `app/Modules/Events/Enums/EventStatus.php` — Methoden ergänzen:

```php
    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Announced],
            self::Announced => [self::Registration],
            self::Registration => [self::Live],
            self::Live => [self::Finished],
            self::Finished => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return __('events.status.'.$this->value);
    }
```

> `label()` liest den deutschen Text aus `lang/de/events.php` (Task 5). Es gibt keinen `mixed`-Rückgabetyp.

- [ ] **Step 4: Domain-Event + Action**

Create `app/Modules/Events/Events/EventStatusChanged.php`:

```php
<?php

namespace App\Modules\Events\Events;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Events\Dispatchable;

class EventStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Event $event,
        public readonly EventStatus $from,
        public readonly EventStatus $to,
    ) {}
}
```

Create `app/Modules/Events/Actions/TransitionEventStatus.php`:

```php
<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use DomainException;

class TransitionEventStatus
{
    public function handle(Event $event, EventStatus $to): Event
    {
        $from = $event->status;

        if (! $from->canTransitionTo($to)) {
            throw new DomainException(
                "Illegal event status transition from {$from->value} to {$to->value}."
            );
        }

        $event->status = $to;
        $event->save();

        EventStatusChanged::dispatch($event, $from, $to);

        return $event;
    }
}
```

- [ ] **Step 5: Grün + Gate**

Run: `./vendor/bin/pest tests/Unit/Events/EventStatusTransitionTest.php`
Expected: PASS (alle erlaubten Kanten grün, alle verbotenen werfen, Self-Transition wirft)

Run: `composer check`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(events): status transition map, TransitionEventStatus action and EventStatusChanged event"
```

---

### Task 3: Filament EventResource + Status-Action-Buttons (Roadmap 1.3)

**Files:**
- Create: `app/Modules/Events/Filament/Resources/EventResource.php` (+ generierte Page-Klassen im selben Namespace)
- Create: `app/Modules/Events/Policies/EventPolicy.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (Resource-Discovery aus Modulpfad), `app/Providers/AppServiceProvider.php` (Policy-Registrierung, falls nicht auto-discovered), `lang/de/events.php`
- Test: `tests/Feature/Events/EventResourceTest.php`

**Interfaces:**
- Consumes: `Event`, `EventStatus`, `TransitionEventStatus`, `EventPolicy`.
- Produces: Filament-Resource unter `/admin/events` (List/Create/Edit) mit deutschen Labels; pro erlaubtem Folge-Status ein Action-Button auf der Edit-Page, der `TransitionEventStatus::handle` aufruft. `/admin/events` nur für `isOrga()` (via M0-Panel-Access + Policy).

> **Verify first (2026):** Filament-v5-Resource-Discovery aus einem Nicht-Standard-Pfad und die Actions-API können sich vom Gedächtnis unterscheiden. Vor dem Bauen prüfen (context7/laravel-boost „filament/filament v5"): (a) Panel-Provider-Methode `->discoverResources(in:, for:)` — Signatur bestätigt (Stand 07/2026: zwei benannte Parameter `in` = Pfad, `for` = Namespace); (b) Resource-Struktur in v5 (Schema-basiertes `form(Schema $schema)`/`table(Table $table)`, `getPages()`); (c) Action-API-Namespace (`Filament\Actions\Action` für Header-/Record-Actions in v5). Der unten stehende Code folgt dem v5-Stand; bei Abweichung der Doku folgen und im Commit vermerken.

- [ ] **Step 1: Resource-Discovery aus dem Modulpfad registrieren**

`app/Providers/Filament/AdminPanelProvider.php` im `panel(...)`-Aufbau ergänzen (zusätzlich zur bestehenden `discoverResources(in: app_path('Filament/Resources'), …)`-Zeile aus M0, die bleibt):

```php
->discoverResources(
    in: app_path('Modules/Events/Filament/Resources'),
    for: 'App\\Modules\\Events\\Filament\\Resources',
)
```

> Diese Zeile ist die verbindliche Vorlage für **alle** Modul-Resources der Folgephasen (jeweils eigener `discoverResources`-Aufruf pro Modul). Alternativ ein Muster mit einem Glob über `app/Modules/*/Filament/Resources` einführen — für M1 genügt der explizite Aufruf.

- [ ] **Step 2: Policy (orga/admin dürfen alles; Participant nichts)**

Create `app/Modules/Events/Policies/EventPolicy.php`:

```php
<?php

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->isOrga();
    }
}
```

Policy registrieren: In Laravel 13 werden Policies für Models im Standard-Pfad automatisch aufgelöst; da `Event` unter `app/Modules/...` liegt, den Mapping-Eintrag in `AppServiceProvider::boot()` explizit setzen:

```php
use App\Modules\Events\Models\Event;
use App\Modules\Events\Policies\EventPolicy;
use Illuminate\Support\Facades\Gate;

Gate::policy(Event::class, EventPolicy::class);
```

- [ ] **Step 3: Failing Feature-Test**

Create `tests/Feature/Events/EventResourceTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

it('lists events for orga in the admin panel', function () {
    Event::factory()->create(['name' => 'Testlan 2026']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/events')
        ->assertOk()
        ->assertSee('Testlan 2026');
});

it('forbids participants from the events resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/events')
        ->assertForbidden();
});
```

> Der Button-Klick-Flow (Transition via Filament-Action) wird über die Action-Unit-Tests aus Task 2 abgedeckt; ein zusätzlicher Livewire-Component-Test der Edit-Page ist optional. Falls gewünscht: `Livewire::test(EditEvent::class, ['record' => $event->getKey()])->callAction('announce')` und Status-Assertion — API in der Filament-v5-Testing-Doku verifizieren.

- [ ] **Step 4: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Events/EventResourceTest.php`
Expected: FAIL — Route `/admin/events` nicht gefunden (Resource fehlt).

- [ ] **Step 5: Resource generieren + anpassen**

Resource generieren (Pfad/Namespace explizit setzen, damit sie im Modul landet — Generator-Optionen in der Filament-v5-Doku prüfen):

```bash
php artisan make:filament-resource Event --generate
```

Danach die generierten Dateien nach `app/Modules/Events/Filament/Resources/` verschieben und Namespaces auf `App\Modules\Events\Filament\Resources` korrigieren (inkl. der Page-Klassen unter `.../EventResource/Pages/`).

Resource-Grundgerüst (v5-API gegen Doku abgleichen — Schema/Table-Namespaces):

```php
<?php

namespace App\Modules\Events\Filament\Resources;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Filament\Resources\EventResource\Pages;
use App\Modules\Events\Models\Event;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    public static function getModelLabel(): string
    {
        return __('events.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('events.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('events.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('location')
                ->label(__('events.fields.location'))
                ->maxLength(255),
            DateTimePicker::make('starts_at')->label(__('events.fields.starts_at')),
            DateTimePicker::make('ends_at')->label(__('events.fields.ends_at')),
            TextInput::make('max_participants')
                ->label(__('events.fields.max_participants'))
                ->numeric(),
            // status is changed only via the transition action buttons, so it is
            // shown read-only here (no free editing that could bypass the map).
            Select::make('status')
                ->label(__('events.fields.status'))
                ->options(fn () => collect(EventStatus::cases())
                    ->mapWithKeys(fn (EventStatus $s) => [$s->value => $s->label()])
                    ->all())
                ->disabled()
                ->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('events.fields.name'))->searchable(),
                TextColumn::make('status')
                    ->label(__('events.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (EventStatus $state) => $state->label()),
                TextColumn::make('starts_at')->label(__('events.fields.starts_at'))->dateTime(),
                TextColumn::make('location')->label(__('events.fields.location')),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 6: Status-Action-Buttons auf der Edit-Page**

`app/Modules/Events/Filament/Resources/EventResource/Pages/EditEvent.php` — `getHeaderActions()` implementieren: pro erlaubtem Folge-Status ein Button, der `TransitionEventStatus` aufruft. (Filament-v5-Actions-API in der Doku bestätigen: `Filament\Actions\Action`, `->action(Closure)`, `->requiresConfirmation()`, Notifications via `Filament\Notifications\Notification`.)

```php
<?php

namespace App\Modules\Events\Filament\Resources\EventResource\Pages;

use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Filament\Resources\EventResource;
use App\Modules\Events\Models\Event;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Event $event */
        $event = $this->getRecord();

        return collect($event->status->allowedTransitions())
            ->map(fn (EventStatus $to) => Action::make('transition_'.$to->value)
                ->label(__('events.transition.'.$to->value))
                ->requiresConfirmation()
                ->action(function () use ($event, $to) {
                    app(TransitionEventStatus::class)->handle($event, $to);

                    Notification::make()
                        ->title(__('events.transition.done', ['status' => $to->label()]))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }))
            ->all();
    }
}
```

- [ ] **Step 7: Deutsche Labels (Basis; wird in Task 5 ergänzt)**

Create/Modify `lang/de/events.php`:

```php
<?php

return [
    'resource' => [
        'label' => 'Event',
        'plural_label' => 'Events',
    ],
    'fields' => [
        'name' => 'Name',
        'location' => 'Ort',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'max_participants' => 'Max. Teilnehmer',
        'status' => 'Status',
    ],
    'status' => [
        'draft' => 'Entwurf',
        'announced' => 'Angekündigt',
        'registration' => 'Anmeldung offen',
        'live' => 'Live',
        'finished' => 'Beendet',
        'archived' => 'Archiviert',
    ],
    'transition' => [
        'announced' => 'Ankündigen',
        'registration' => 'Anmeldung öffnen',
        'live' => 'Event starten',
        'finished' => 'Event beenden',
        'archived' => 'Archivieren',
        'done' => 'Status geändert auf: :status',
    ],
];
```

- [ ] **Step 8: Grün + Gate**

Run: `./vendor/bin/pest tests/Feature/Events/EventResourceTest.php`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat(events): filament EventResource with status transition action buttons"
```

---

### Task 4: CurrentEvent-Resolver + Inertia-Shared-Prop (Roadmap 1.4)

**Files:**
- Create: `app/Modules/Events/Support/CurrentEvent.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Unit/Events/CurrentEventTest.php`

**Interfaces:**
- Consumes: `Event`, `EventStatus`.
- Produces:
  - `CurrentEvent::get(): ?Event` — jüngstes Event mit Status ∈ {`announced`, `registration`, `live`}, priorisiert nach „am nächsten am Geschehen": `live` vor `registration` vor `announced`, innerhalb desselben Status das mit dem frühesten künftigen bzw. jüngsten `starts_at`. Genaue Auswahlregel siehe Test.
  - Inertia-Shared-Prop `currentEvent` (serialisiertes DTO oder `null`) in **jeder** Teilnehmer-Seite.

> **Auswahl-Priorität (verbindlich, im Test fixiert):** Ein Event ist „aktuell", wenn sein Status in {announced, registration, live} liegt. Bei mehreren Kandidaten gewinnt die höhere Status-Stufe (live > registration > announced); bei Gleichstand das Event mit dem **spätesten** `starts_at` (das zuletzt angelegte/anstehende gewinnt). `draft`, `finished`, `archived` sind nie „aktuell".

- [ ] **Step 1: Failing Test — Auswahl-Priorität**

Create `tests/Unit/Events/CurrentEventTest.php`:

```php
<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;

it('returns null when no event is in a public status', function () {
    Event::factory()->draft()->create();
    Event::factory()->finished()->create();
    Event::factory()->archived()->create();

    expect(app(CurrentEvent::class)->get())->toBeNull();
});

it('prefers a live event over registration and announced', function () {
    $announced = Event::factory()->announced()->create(['starts_at' => now()->addDays(30)]);
    $registration = Event::factory()->registration()->create(['starts_at' => now()->addDays(20)]);
    $live = Event::factory()->live()->create(['starts_at' => now()->subDay()]);

    expect(app(CurrentEvent::class)->get()->is($live))->toBeTrue();
});

it('prefers registration over announced when no live event exists', function () {
    Event::factory()->announced()->create();
    $registration = Event::factory()->registration()->create();

    expect(app(CurrentEvent::class)->get()->is($registration))->toBeTrue();
});

it('breaks ties within a status by latest starts_at', function () {
    $earlier = Event::factory()->registration()->create(['starts_at' => now()->addDays(10)]);
    $later = Event::factory()->registration()->create(['starts_at' => now()->addDays(40)]);

    expect(app(CurrentEvent::class)->get()->is($later))->toBeTrue();
});
```

- [ ] **Step 2: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Unit/Events/CurrentEventTest.php`
Expected: FAIL — `Class "App\Modules\Events\Support\CurrentEvent" not found`

- [ ] **Step 3: Resolver**

Create `app/Modules/Events/Support/CurrentEvent.php`:

```php
<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

class CurrentEvent
{
    /**
     * @var array<int, EventStatus>
     */
    private const PUBLIC_STATUSES = [
        EventStatus::Announced,
        EventStatus::Registration,
        EventStatus::Live,
    ];

    public function get(): ?Event
    {
        // Rank statuses so that live > registration > announced.
        $rank = [
            EventStatus::Live->value => 3,
            EventStatus::Registration->value => 2,
            EventStatus::Announced->value => 1,
        ];

        return Event::query()
            ->whereIn('status', array_map(fn (EventStatus $s) => $s->value, self::PUBLIC_STATUSES))
            ->get()
            ->sortByDesc(fn (Event $e) => [$rank[$e->status->value], $e->starts_at?->getTimestamp() ?? 0])
            ->first();
    }
}
```

> Die Sortierung über eine geladene Collection hält die Priorität lesbar und modul-lokal. Bei sehr vielen Events später als reine SQL-Query mit `ORDER BY CASE status … , starts_at DESC` optimieren — für M1 nicht nötig (YAGNI).

- [ ] **Step 4: Als Inertia-Shared-Prop bereitstellen**

> **Verify first (2026):** Inertia-v2-Shared-Props-Doku (`inertiajs.com/docs/v2/data-props/shared-data`) — Werte in Closures wrappen, damit sie nur bei Bedarf berechnet werden.

`app/Http/Middleware/HandleInertiaRequests.php` — im `share()`-Rückgabe-Array ergänzen:

```php
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;

// ... innerhalb von share():
'currentEvent' => fn () => ($event = app(CurrentEvent::class)->get()) === null
    ? null
    : [
        'name' => $event->name,
        'slug' => $event->slug,
        'status' => $event->status->value,
        'startsAt' => $event->starts_at?->toIso8601String(),
        'endsAt' => $event->ends_at?->toIso8601String(),
        'location' => $event->location,
    ],
```

> Bewusst ein schlankes DTO statt des ganzen Models (keine internen Felder wie `settings` an den Client). Der `status`-String erlaubt der Vue-Seite statusabhängige CTAs (Task 5).

- [ ] **Step 5: Grün + Gate + Frontend-Build**

Run: `./vendor/bin/pest tests/Unit/Events/CurrentEventTest.php`
Expected: PASS

Run: `composer check && npm run build`
Expected: PASS (Build stellt sicher, dass das geänderte Prop-Shape TS-seitig nicht bricht — Typ wird in Task 5 ergänzt)

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(events): CurrentEvent resolver shared as inertia currentEvent prop"
```

---

### Task 5: Öffentliche Event-Seite + Archiv (Roadmap 1.5)

**Files:**
- Create: `app/Modules/Events/Http/EventPageController.php`, `resources/js/pages/Event/Show.vue`, `resources/js/pages/Event/Index.vue`
- Create/Modify: `resources/js/types/index.d.ts` (Typ `EventSummary` / `CurrentEvent`), `resources/js/composables/useEventLabels.ts` (optional Helper)
- Modify: `routes/web.php`, `lang/de/events.php`
- Test: `tests/Feature/Events/EventPageTest.php`

**Interfaces:**
- Consumes: `Event`, `EventStatus`, `CurrentEvent`.
- Produces (öffentliche, auth-freie Routen):
  - `GET /` (name `home`) → rendert `Event/Show` mit dem CurrentEvent, oder `Event/Index` (Archiv) wenn keins aktiv,
  - `GET /events/{event:slug}` (name `events.show`) → `Event/Show`,
  - `GET /events` (name `events.index`) → `Event/Index` (Liste `finished`/`archived`, absteigend).
- UI-Copy ausschließlich über `lang/de/events.php`, an die Seite als `labels`-Prop übergeben (Muster unten).

> **UI-Copy-Muster (verbindlich für alle Vue-Seiten dieser Phase):** Keine deutschen Strings in `.vue`. Der Controller lädt die benötigten Übersetzungen aus `lang/de/events.php` via `__('events.*')` / `trans('events.page')` und reicht sie als `labels`-Objekt-Prop an die Inertia-Seite. Statusabhängige Texte kommen über denselben Weg (`status`-Map). So bleiben die Komponenten sprachneutral und testbar.

- [ ] **Step 1: Übersetzungen für die öffentliche Seite ergänzen**

`lang/de/events.php` um einen `page`-Block erweitern:

```php
    'page' => [
        'title' => 'LAN-Party',
        'no_current_event' => 'Aktuell ist keine LAN angekündigt.',
        'when' => 'Wann',
        'where' => 'Wo',
        'archive_title' => 'Vergangene LANs',
        'archive_empty' => 'Noch keine vergangenen Events.',
        'to_archive' => 'Zum Archiv',
        'cta' => [
            'announced' => 'Bald geht die Anmeldung los',
            'registration' => 'Jetzt anmelden',
            'live' => 'Event läuft',
        ],
    ],
```

- [ ] **Step 2: Failing Feature-Test**

Create `tests/Feature/Events/EventPageTest.php`:

```php
<?php

use App\Modules\Events\Models\Event;
use Inertia\Testing\AssertableInertia;

it('shows the current event on the home page', function () {
    $event = Event::factory()->registration()->create(['name' => 'Testlan 2026']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Show')
            ->where('event.name', 'Testlan 2026')
            ->where('event.status', 'registration')
        );
});

it('shows the archive on the home page when no event is public', function () {
    Event::factory()->archived()->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Event/Index'));
});

it('renders a single event by slug', function () {
    $event = Event::factory()->finished()->create(['name' => 'Old LAN']);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Show')
            ->where('event.name', 'Old LAN')
        );
});

it('lists past events in the archive descending', function () {
    Event::factory()->finished()->create(['name' => 'A', 'starts_at' => now()->subYear()]);
    Event::factory()->archived()->create(['name' => 'B', 'starts_at' => now()->subMonth()]);
    Event::factory()->draft()->create(['name' => 'Hidden']);

    $this->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Index')
            ->has('events', 2)
            ->where('events.0.name', 'B') // most recent first
        );
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Events/EventPageTest.php`
Expected: FAIL — Route `/` rendert noch die Starter-Kit-Welcome-Seite, nicht `Event/Show`.

- [ ] **Step 4: Controller**

Create `app/Modules/Events/Http/EventPageController.php`:

```php
<?php

namespace App\Modules\Events\Http;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use Inertia\Inertia;
use Inertia\Response;

class EventPageController
{
    public function home(CurrentEvent $current): Response
    {
        $event = $current->get();

        return $event === null
            ? $this->archive()
            : $this->renderShow($event);
    }

    public function show(Event $event): Response
    {
        return $this->renderShow($event);
    }

    public function archive(): Response
    {
        $events = Event::query()
            ->whereIn('status', [EventStatus::Finished->value, EventStatus::Archived->value])
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Event $e) => $this->summary($e))
            ->all();

        return Inertia::render('Event/Index', [
            'events' => $events,
            'labels' => trans('events.page'),
        ]);
    }

    private function renderShow(Event $event): Response
    {
        return Inertia::render('Event/Show', [
            'event' => $this->summary($event),
            'labels' => trans('events.page'),
            'statusLabels' => trans('events.status'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Event $event): array
    {
        return [
            'name' => $event->name,
            'slug' => $event->slug,
            'status' => $event->status->value,
            'startsAt' => $event->starts_at?->toIso8601String(),
            'endsAt' => $event->ends_at?->toIso8601String(),
            'location' => $event->location,
        ];
    }
}
```

- [ ] **Step 5: Routen**

`routes/web.php` — die bestehende Welcome-Route ersetzen/ergänzen:

```php
use App\Modules\Events\Http\EventPageController;

Route::get('/', [EventPageController::class, 'home'])->name('home');
Route::get('/events', [EventPageController::class, 'archive'])->name('events.index');
Route::get('/events/{event:slug}', [EventPageController::class, 'show'])->name('events.show');
```

> Alle drei Routen sind auth-frei (öffentlich lesbar). `{event:slug}` nutzt implizites Route-Model-Binding auf die `slug`-Spalte (in `Event::getRouteKeyName()` gesetzt).

- [ ] **Step 6: TS-Typ für Event-Zusammenfassung**

`resources/js/types/index.d.ts` — Typ ergänzen (Namen an die Starter-Kit-Konvention anpassen):

```ts
export interface EventSummary {
    name: string;
    slug: string;
    status: 'draft' | 'announced' | 'registration' | 'live' | 'finished' | 'archived';
    startsAt: string | null;
    endsAt: string | null;
    location: string | null;
}
```

Falls das Starter-Kit ein globales `PageProps`-Interface hat: `currentEvent?: EventSummary | null` dort ergänzen.

- [ ] **Step 7: Vue-Seite `Event/Show.vue`**

Create `resources/js/pages/Event/Show.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { EventSummary } from '@/types';

const props = defineProps<{
    event: EventSummary;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
}>();

const dateRange = computed(() => {
    if (!props.event.startsAt) return '';
    const opts: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' };
    const start = new Date(props.event.startsAt).toLocaleString('de-DE', opts);
    const end = props.event.endsAt
        ? new Date(props.event.endsAt).toLocaleString('de-DE', opts)
        : null;
    return end ? `${start} – ${end}` : start;
});

const cta = computed<string | null>(() => {
    const map = props.labels as Record<string, unknown>;
    const ctas = (map.cta ?? {}) as Record<string, string>;
    return ctas[props.event.status] ?? null;
});
</script>

<template>
    <Head :title="event.name" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <p class="text-sm font-medium uppercase tracking-wide text-muted-foreground">
            {{ statusLabels[event.status] }}
        </p>
        <h1 class="mt-2 text-4xl font-bold tracking-tight">{{ event.name }}</h1>

        <dl class="mt-8 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-muted-foreground">{{ labels.when }}</dt>
                <dd class="text-lg">{{ dateRange }}</dd>
            </div>
            <div v-if="event.location">
                <dt class="text-sm text-muted-foreground">{{ labels.where }}</dt>
                <dd class="text-lg">{{ event.location }}</dd>
            </div>
        </dl>

        <div class="mt-10 flex flex-wrap gap-3">
            <Button v-if="cta" size="lg">{{ cta }}</Button>
            <Button as-child variant="outline">
                <Link :href="route('events.index')">{{ labels.to_archive }}</Link>
            </Button>
        </div>
    </main>
</template>
```

> Der „Jetzt anmelden"-CTA ist in M1 bewusst ein reiner Button ohne Ziel — die Anmelde-Route entsteht erst in M2 (YAGNI, kein Vorgriff). Der Button demonstriert die statusabhängige Anzeige; die Verdrahtung folgt in M2 Task 2.3.

- [ ] **Step 8: Vue-Seite `Event/Index.vue` (Archiv)**

Create `resources/js/pages/Event/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { EventSummary } from '@/types';

defineProps<{
    events: EventSummary[];
    labels: Record<string, string>;
}>();

function year(iso: string | null): string {
    return iso ? new Date(iso).getFullYear().toString() : '';
}
</script>

<template>
    <Head :title="labels.archive_title" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">{{ labels.archive_title }}</h1>

        <p v-if="events.length === 0" class="mt-6 text-muted-foreground">
            {{ labels.archive_empty }}
        </p>

        <ul v-else class="mt-8 divide-y divide-border">
            <li v-for="event in events" :key="event.slug" class="py-4">
                <Link
                    :href="route('events.show', { event: event.slug })"
                    class="flex items-baseline justify-between hover:underline"
                >
                    <span class="text-lg font-medium">{{ event.name }}</span>
                    <span class="text-sm text-muted-foreground">{{ year(event.startsAt) }}</span>
                </Link>
            </li>
        </ul>
    </main>
</template>
```

- [ ] **Step 9: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Feature/Events/EventPageTest.php`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add -A && git commit -m "feat(events): public event page, archive list and german ui copy via props"
```

---

### Task 6: Profil — Migration, UpdateProfile-Action, Zufalls-profile_color, Edit-Seite (Roadmap 1.6)

> **Pflicht-Zusätze aus dem M0-Whole-Branch-Review (2026-07-14):**
> 1. **Feld-Ownership klären und umsetzen:** `UpsertUserFromDiscord` überschreibt bei jedem Login `name`/`email`/`avatar_url`. Sobald User ihr Profil editieren können, gilt: Discord-owned = `avatar_url` (+ `discord_id`), user-owned = `name` (nach erstem Set), `email`, `bio`, `steam_url`, `profile_color`. Upsert-Action entsprechend anpassen (nur beim Erstellen befüllen bzw. nur Discord-owned Felder aktualisieren) + Tests, dass ein editierter Name den nächsten Login überlebt.
> 2. **E-Mail-Kollision abfangen:** `users.email` ist unique+nullable; zwei Discord-Accounts mit derselben E-Mail → aktuell 500 im Callback. Beim Upsert Kollision behandeln (E-Mail dann null lassen + Hinweis) + Test.
> 3. **Security-Sackgasse schließen:** Settings-Security-Seite (`RequirePassword`) ist für passwortlose Discord-User unerreichbar — Navigation/Route für User ohne Passwort ausblenden (kleiner Step in diesem Task oder Task 8).
> 4. `role` ist seit dem M0-Fix NICHT mehr fillable — neue fillable-Einträge hier (bio, steam_url, profile_color) sind unkritisch, `role` nie wieder aufnehmen.

**Files:**
- Create: `database/migrations/<ts>_add_profile_fields_to_users_table.php`, `app/Modules/Identity/Actions/UpdateProfile.php`, `app/Modules/Identity/Http/ProfileController.php`, `app/Modules/Identity/Http/Requests/UpdateProfileRequest.php`, `resources/js/pages/Profile/Edit.vue`
- Modify: `app/Models/User.php` (fillable + `creating`-Hook für `profile_color`), `database/factories/UserFactory.php`, `routes/web.php`, `lang/de/profile.php`
- Test: `tests/Feature/Identity/UpdateProfileTest.php`, `tests/Unit/Identity/ProfileColorTest.php`

**Interfaces:**
- Consumes: `User`.
- Produces:
  - Migration: `users.bio (text, nullable)`, `users.steam_url (string, nullable)`, `users.profile_color (string(7))`.
  - `UpdateProfile::handle(User $user, array $data): User` — Whitelist `name, bio, steam_url, profile_color`.
  - Random `profile_color` **im App-Code** bei User-Erstellung (kein DB-Trigger wie v1) via Model-`creating`-Hook.
  - Routen `GET /profile` (name `profile.edit`), `PATCH /profile` (name `profile.update`), beide `auth`.

- [ ] **Step 1: Failing Unit-Test — Zufalls-profile_color bei Erstellung**

Create `tests/Unit/Identity/ProfileColorTest.php`:

```php
<?php

use App\Models\User;

it('assigns a random hex profile_color on creation when none is given', function () {
    $user = User::factory()->create(['profile_color' => null]);

    expect($user->profile_color)->toMatch('/^#[0-9a-fA-F]{6}$/');
});

it('keeps an explicitly provided profile_color', function () {
    $user = User::factory()->create(['profile_color' => '#abcdef']);

    expect($user->profile_color)->toBe('#abcdef');
});

it('assigns different colors to different users (not a constant)', function () {
    $colors = collect(range(1, 20))
        ->map(fn () => User::factory()->create(['profile_color' => null])->profile_color)
        ->unique();

    // extremely unlikely to collide into a single value across 20 users
    expect($colors->count())->toBeGreaterThan(1);
});
```

- [ ] **Step 2: Failing Feature-Test — Profil-Update-Validierung**

Create `tests/Feature/Identity/UpdateProfileTest.php`:

```php
<?php

use App\Models\User;

it('requires authentication to edit the profile', function () {
    $this->get('/profile')->assertRedirect(route('login'));
});

it('updates own profile with valid data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'New Name',
            'bio' => 'Hello LAN',
            'steam_url' => 'https://steamcommunity.com/id/example',
            'profile_color' => '#112233',
        ])
        ->assertRedirect();

    expect($user->fresh())
        ->name->toBe('New Name')
        ->bio->toBe('Hello LAN')
        ->steam_url->toBe('https://steamcommunity.com/id/example')
        ->profile_color->toBe('#112233');
});

it('rejects an invalid profile_color', function () {
    $user = User::factory()->create(['profile_color' => '#000000']);

    $this->actingAs($user)
        ->patch('/profile', ['name' => 'X', 'profile_color' => 'red'])
        ->assertSessionHasErrors('profile_color');

    expect($user->fresh()->profile_color)->toBe('#000000');
});

it('rejects a non-steam steam_url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', ['name' => 'X', 'steam_url' => 'https://example.com/notsteam'])
        ->assertSessionHasErrors('steam_url');
});

it('does not allow changing the role via profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', ['name' => 'X', 'role' => 'admin']);

    expect($user->fresh()->isAdmin())->toBeFalse();
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Unit/Identity/ProfileColorTest.php tests/Feature/Identity/UpdateProfileTest.php`
Expected: FAIL — Spalten/Route/Action fehlen.

- [ ] **Step 4: Migration**

Create migration `add_profile_fields_to_users_table`:

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
            $table->text('bio')->nullable();
            $table->string('steam_url')->nullable();
            $table->string('profile_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'steam_url', 'profile_color']);
        });
    }
};
```

- [ ] **Step 5: User-Model — fillable + creating-Hook**

Modify `app/Models/User.php`:

- `$fillable` um `bio`, `steam_url`, `profile_color` ergänzen (`role` bleibt bewusst **nicht** massenzuweisbar über das Profil — wird nur programmatisch gesetzt).
- `booted()`-Hook für die Zufallsfarbe:

```php
protected static function booted(): void
{
    static::creating(function (User $user): void {
        if (blank($user->profile_color)) {
            $user->profile_color = sprintf('#%06X', random_int(0, 0xFFFFFF));
        }
    });
}
```

> Bewusst App-Code statt DB-Trigger (Lehre aus v1). `random_int` ist kryptografisch ausreichend und deterministisch testbar über die Regex-Assertion.

- [ ] **Step 6: Factory ergänzen**

`database/factories/UserFactory.php` `definition()` ergänzen:

```php
'bio' => null,
'steam_url' => null,
'profile_color' => null, // let the creating hook assign one
```

> `profile_color => null` in der Factory, damit der Hook greift; Tests, die eine feste Farbe brauchen, setzen sie explizit.

- [ ] **Step 7: FormRequest + Action + Controller**

Create `app/Modules/Identity/Http/Requests/UpdateProfileRequest.php`:

```php
<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'steam_url' => ['nullable', 'url', 'starts_with:https://steamcommunity.com/'],
            'profile_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
```

Create `app/Modules/Identity/Actions/UpdateProfile.php`:

```php
<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;

class UpdateProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): User
    {
        $user->fill(array_filter(
            [
                'name' => $data['name'] ?? null,
                'bio' => $data['bio'] ?? null,
                'steam_url' => $data['steam_url'] ?? null,
                'profile_color' => $data['profile_color'] ?? null,
            ],
            fn ($value, $key) => array_key_exists($key, $data),
            ARRAY_FILTER_USE_BOTH,
        ));

        $user->save();

        return $user;
    }
}
```

Create `app/Modules/Identity/Http/ProfileController.php`:

```php
<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Actions\UpdateProfile;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'bio' => $user->bio,
                'steamUrl' => $user->steam_url,
                'profileColor' => $user->profile_color,
            ],
            'labels' => trans('profile.form'),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated());

        return back();
    }
}
```

- [ ] **Step 8: Routen + Übersetzungen**

`routes/web.php` (auth-Gruppe):

```php
use App\Modules\Identity\Http\ProfileController;

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});
```

> Falls das Starter-Kit bereits `profile.edit`/`profile.update`-Routen (Settings) mitbringt: prüfen und diese durch die obigen ersetzen bzw. konsolidieren (keine doppelten Route-Namen).

Create `lang/de/profile.php`:

```php
<?php

return [
    'form' => [
        'title' => 'Profil bearbeiten',
        'name' => 'Anzeigename',
        'bio' => 'Über mich',
        'steam_url' => 'Steam-Profil',
        'profile_color' => 'Profilfarbe',
        'save' => 'Speichern',
        'saved' => 'Profil gespeichert.',
    ],
];
```

- [ ] **Step 9: Vue-Seite `Profile/Edit.vue`**

Create `resources/js/pages/Profile/Edit.vue`:

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    profile: {
        name: string;
        bio: string | null;
        steamUrl: string | null;
        profileColor: string | null;
    };
    labels: Record<string, string>;
}>();

const form = useForm({
    name: props.profile.name,
    bio: props.profile.bio ?? '',
    steam_url: props.profile.steamUrl ?? '',
    profile_color: props.profile.profileColor ?? '#000000',
});

function submit() {
    form.patch(route('profile.update'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-lg px-4 py-12">
        <h1 class="text-2xl font-bold tracking-tight">{{ labels.title }}</h1>

        <form class="mt-8 space-y-6" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="name">{{ labels.name }}</Label>
                <Input id="name" v-model="form.name" type="text" />
                <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
            </div>

            <div class="space-y-2">
                <Label for="bio">{{ labels.bio }}</Label>
                <textarea
                    id="bio"
                    v-model="form.bio"
                    rows="4"
                    class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <p v-if="form.errors.bio" class="text-sm text-destructive">{{ form.errors.bio }}</p>
            </div>

            <div class="space-y-2">
                <Label for="steam_url">{{ labels.steam_url }}</Label>
                <Input id="steam_url" v-model="form.steam_url" type="url" />
                <p v-if="form.errors.steam_url" class="text-sm text-destructive">
                    {{ form.errors.steam_url }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="profile_color">{{ labels.profile_color }}</Label>
                <Input id="profile_color" v-model="form.profile_color" type="color" class="h-10 w-16 p-1" />
                <p v-if="form.errors.profile_color" class="text-sm text-destructive">
                    {{ form.errors.profile_color }}
                </p>
            </div>

            <Button type="submit" :disabled="form.processing">{{ labels.save }}</Button>
        </form>
    </main>
</template>
```

> `type="color"` liefert immer einen 7-stelligen Hex-Wert und passt exakt zur `regex`-Validierung. Fehlermeldungen kommen von Laravel; sie werden im MVP nicht zusätzlich lokalisiert (Standard-Validierungssprache — Backlog, falls deutsche Validation-Messages gewünscht).

- [ ] **Step 10: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Unit/Identity/ProfileColorTest.php tests/Feature/Identity/UpdateProfileTest.php`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add -A && git commit -m "feat(identity): profile fields, UpdateProfile action, random profile_color and edit page"
```

---

### Task 7: Öffentliches Profil `Profile/Show.vue` (Roadmap 1.7)

**Files:**
- Create: `resources/js/pages/Profile/Show.vue`
- Modify: `app/Modules/Identity/Http/ProfileController.php` (`show`-Methode), `routes/web.php`, `lang/de/profile.php`
- Test: `tests/Feature/Identity/ProfileShowTest.php`

**Interfaces:**
- Consumes: `User`.
- Produces: Route `GET /users/{user}` (name `profile.show`), öffentlich lesbar; rendert `Profile/Show` mit den öffentlichen Feldern (`name, avatar_url, bio, steam_url, profile_color`). Keine E-Mail/`discord_id`/Rolle nach außen.

- [ ] **Step 1: Failing Feature-Test**

Create `tests/Feature/Identity/ProfileShowTest.php`:

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows a public profile without leaking private fields', function () {
    $user = User::factory()->create([
        'name' => 'Gamer',
        'bio' => 'GG',
        'email' => 'secret@example.com',
    ]);

    $this->get("/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Profile/Show')
            ->where('profile.name', 'Gamer')
            ->where('profile.bio', 'GG')
            ->missing('profile.email')
            ->missing('profile.discordId')
            ->missing('profile.role')
        );
});

it('returns 404 for an unknown user', function () {
    $this->get('/users/999999')->assertNotFound();
});
```

- [ ] **Step 2: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Identity/ProfileShowTest.php`
Expected: FAIL — Route fehlt.

- [ ] **Step 3: Controller-Methode + Route**

`ProfileController` ergänzen:

```php
public function show(\App\Models\User $user): Response
{
    return Inertia::render('Profile/Show', [
        'profile' => [
            'name' => $user->name,
            'avatarUrl' => $user->avatar_url,
            'bio' => $user->bio,
            'steamUrl' => $user->steam_url,
            'profileColor' => $user->profile_color,
        ],
        'labels' => trans('profile.public'),
    ]);
}
```

`routes/web.php` (öffentlich):

```php
Route::get('/users/{user}', [ProfileController::class, 'show'])->name('profile.show');
```

`lang/de/profile.php` um einen `public`-Block ergänzen:

```php
    'public' => [
        'bio' => 'Über mich',
        'steam' => 'Steam-Profil',
        'no_bio' => 'Keine Beschreibung hinterlegt.',
    ],
```

- [ ] **Step 4: Vue-Seite `Profile/Show.vue`**

Create `resources/js/pages/Profile/Show.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    profile: {
        name: string;
        avatarUrl: string | null;
        bio: string | null;
        steamUrl: string | null;
        profileColor: string | null;
    };
    labels: Record<string, string>;
}>();
</script>

<template>
    <Head :title="profile.name" />

    <main class="mx-auto max-w-2xl px-4 py-12">
        <div class="flex items-center gap-4">
            <div
                class="h-16 w-16 shrink-0 rounded-full bg-cover bg-center ring-2"
                :style="{
                    backgroundImage: profile.avatarUrl ? `url(${profile.avatarUrl})` : undefined,
                    '--tw-ring-color': profile.profileColor ?? undefined,
                }"
            />
            <h1 class="text-3xl font-bold tracking-tight">{{ profile.name }}</h1>
        </div>

        <section class="mt-8">
            <h2 class="text-sm text-muted-foreground">{{ labels.bio }}</h2>
            <p class="mt-1 whitespace-pre-line">{{ profile.bio || labels.no_bio }}</p>
        </section>

        <a
            v-if="profile.steamUrl"
            :href="profile.steamUrl"
            target="_blank"
            rel="noopener"
            class="mt-6 inline-block text-primary underline"
        >
            {{ labels.steam }}
        </a>
    </main>
</template>
```

- [ ] **Step 5: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Feature/Identity/ProfileShowTest.php`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(identity): public user profile page"
```

---

### Task 8: Phasenabschluss — Doku, Voll-Verifikation, Tag (Abschluss M1)

**Files:**
- Modify: `CLAUDE.md` (Current-state-Abschnitt), `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (Erkenntnisse, falls vorhanden)

- [ ] **Step 1: Roadmap als lebendes Dokument pflegen**

Falls in M1 Abweichungen/Erkenntnisse entstanden (z. B. gewählte Slug-Lösung, Filament-v5-Resource-Discovery-Detail, Inertia-Prop-Shape), diese im M1-Abschnitt der Roadmap kurz nachtragen, bevor M2 geplant wird.

- [ ] **Step 2: Manuelle Abnahme (durchklicken)**

Manuell (Discord-App + laufende Compose-DB vorausgesetzt):
- Als Orga im Panel ein Event „Testlan 2026" anlegen; über die Buttons `draft → announced → registration → live → finished → archived` durchklicken; verbotene Buttons erscheinen nicht (nur der jeweils erlaubte Folge-Status).
- Öffentliche Startseite zeigt das Event, sobald es `announced`/`registration`/`live` ist; nach `archived` erscheint es im Archiv unter `/events`.
- Profil unter `/profile` bearbeiten (Farbe, Bio, Steam-URL); öffentliches Profil unter `/users/{id}` zeigt die Werte, keine E-Mail.

- [ ] **Step 3: Voll-Verifikation**

Run: `composer check && npm run lint && npm run build`
Expected: alles PASS

- [ ] **Step 4: Commit + Tag**

```bash
git add -A && git commit -m "docs: update state after M1 (events lifecycle, profile)"
git tag m1 && git push --tags
```

---

## Abnahme-Checkliste M1

- [ ] CI grün (php + frontend Jobs), `composer check` lokal grün.
- [ ] Feature-/Unit-Tests: alle Lifecycle-Kanten (erlaubt + verboten + Self-Transition), `CurrentEvent`-Auswahlpriorität (live > registration > announced, Tie-Break `starts_at`), Profil-Update-Validierung (`profile_color`-Regex, `steam_url`-Domain, keine Rollenänderung), Zufalls-`profile_color` bei User-Erstellung.
- [ ] Filament `/admin/events`: CRUD für Orga/Admin, 403 für Participant; Status-Action-Buttons zeigen nur erlaubte Folge-Status und rufen `TransitionEventStatus`; deutsche Labels.
- [ ] Öffentliche Seiten: `/` zeigt CurrentEvent oder Archiv; `/events/{slug}`; `/events` (Archiv, absteigend). UI-Copy komplett aus `lang/de/*.php` als Props, keine hartkodierten Strings in `.vue`.
- [ ] `currentEvent` als Inertia-Shared-Prop in der Teilnehmer-UI verfügbar.
- [ ] Profil: `/profile` (auth), `/users/{id}` (öffentlich, ohne private Felder).
- [ ] Manuell: Event „Testlan 2026" von `draft` bis `archived` durchklickbar.
- [ ] Modul-Grenzen eingehalten (kein Zugriff über Modul-Tabellen hinweg); Actions-Pattern; jede Autorisierung über Policy/Gate.
- [ ] Git-Tag `m1` gesetzt.
