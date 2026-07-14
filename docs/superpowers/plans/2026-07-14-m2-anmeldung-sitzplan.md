# LANoMAT v2 — M2 Anmeldung, Sitzplan, Notifications, Discord-Basis: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teilnehmer melden sich zum aktuellen Event an (Ticket-Stufen aus `event.settings`), sehen „Meine Anmeldung" mit persönlichem QR-Code, wählen und wechseln einen Sitzplatz auf einem SVG-Raster; die Orga checkt Teilnehmer vor Ort per QR-Scan ein und verwaltet Anmeldungen im Filament-Panel. In-App-Benachrichtigungen (Glocke) und Discord-Announcements (Anmeldung offen, 24 h/1 h-Reminder) laufen dedupliziert über eine Outbox mit Scheduler-Command `lanomat:send-reminders`.

**Architecture:** Vier neue Module im modularen Monolithen, entsprechend der Repo-Struktur des Design-Dokuments (Abschnitt 11: `Identity/ Events/ Registration/ Seating/ Teams/ … Discord/ … Notifications/`):
- `app/Modules/Registration/` — `EventRegistration`-Model, Actions (`RegisterForEvent`, `CancelRegistration`, `CheckInRegistration`), `RegistrationPolicy`, Filament-RelationManager am Event, Inertia-Anmeldeseite.
- `app/Modules/Seating/` — `Seat` + `SeatAssignment`-Models, Actions (`ClaimSeat`, `ReleaseSeat`), Filament Seat-Editor (Bulk-Anlage), Teilnehmer-Sitzplan.
- `app/Modules/Notifications/` — `database`-Channel-Grundgerüst, Glocken-Dropdown, `notification_prefs`.
- `app/Modules/Discord/` — `DiscordClient`-Contract + `HttpDiscordClient` + `FakeDiscordClient`, `discord`-Notification-Channel, `discord_outbox`, `lanomat:send-reminders`-Command.

> **Modul-Entscheidung (verbindlich, mit Begründung):** Registration und Seating sind **eigenständige Top-Level-Module** (nicht Submodule von Events), weil das Design-Dokument sie in Abschnitt 11 explizit als getrennte Verzeichnisse führt und sie eigene Aggregatwurzeln (`EventRegistration`, `Seat`) mit eigenen Policies und Filament-Flächen haben. `Event` bleibt die organisatorische Aggregatwurzel (jede Registration/jeder Seat hängt via `event_id` an genau einem Event) — Modul-Kommunikation läuft über `event_id`-FKs und Laravel-Events, nie über direkte Tabellenzugriffe fremder Module.

**Tech Stack:** PHP 8.4, Laravel 13, Filament v5 (5.6.8), Inertia v2, Vue 3, Tailwind v4, shadcn-vue, Pest 4, Larastan Level 8, Pint, PostgreSQL 16, Redis, `bacon/bacon-qr-code`, `vue-qrcode-reader`.

## Global Constraints (aus der Roadmap, für jeden Task dieser Phase)

- Code/Kommentare/Commits/Doku **Englisch**; UI-Texte **Deutsch** über `lang/de/*.php`, in Vue-Komponenten ausschließlich als Props — keine hartkodierten deutschen Strings in `.vue`-Dateien. `APP_LOCALE=de`, Fallback `en`.
- Conventional Commits (`feat(registration): …`, `feat(seating): …`, `feat(notifications): …`, `feat(discord): …`); ein Commit pro Task.
- PHP: Pint (Laravel-Preset), Larastan Level 8, keine `mixed`-Rückgaben in eigenem Code, Enums statt Magic-Strings.
- Vue: `<script setup lang="ts">`, **keine** `<style>`-Blöcke, nur Tailwind + shadcn-vue.
- **Jede Autorisierung über Policies/Gates; nie Client-gelieferte User-IDs verwenden** — immer `$request->user()`. Privilegientragende Felder (`status`, `paid_at`, `checked_in_at`) sind **nie** client-settable (Lehre aus M0): nicht in `$fillable`/`#[Fillable]`, nur über Actions gesetzt.
- Modul-Grenzen: `app/Modules/<Name>/{Models,Actions,Policies,Filament,Http,Notifications,Contracts,Jobs,Events,Console}`; kein Modul greift in die Tabellen eines anderen. Tests gespiegelt unter `tests/{Feature,Unit}/<Name>/`.
- Qualitäts-Gate **nach jedem Task**: `composer check` (`pint --test`, `phpstan analyse` level 8, `pest`) grün; bei Frontend-Tasks zusätzlich `npm run lint`, `npm run build`. Tests laufen gegen echtes Postgres (`.env.testing`, Host-Port 5434, DB `lanomat_test`) — **niemals** `DB_*`-Env in `phpunit.xml` setzen (M0-Falle).
- Externe Systeme (Discord) **nur** über Contracts in `app/Modules/Discord/Contracts/` — Tests binden `FakeDiscordClient`, nie echte APIs.
- Uploads/Assets im Laravel-Storage, nie Base64. QR-Codes werden zur Anzeige on-the-fly als SVG gerendert (kein DB-Blob).
- **i18n-Gate (Erkenntnis M1, verbindlich):** Jeder Task, der `lang/de`-Keys hinzufügt, MUSS mindestens eine Feature-Test-Assertion auf ein **übersetztes Label** enthalten (`->where('labels.x', 'Übersetzter Text')`), nicht nur auf den Key. Die Phasen-Abnahme enthält einen Locale-Smoke-Check (`APP_LOCALE=de` → gerenderte Seite zeigt deutschen Text, keine rohen Keys).
- **2026-Best-Practices-Regel:** Vor dem Installieren eines Pakets oder dem Verwenden einer Framework-API die aktuelle offizielle Doku über **laravel-boost**/**context7**-MCP oder WebFetch verifizieren. Konkrete Doku-Seiten stehen in den betreffenden Steps. Bei Abweichung der Doku folgen und im Commit-Body vermerken. Prefer first-party.

## Voraussetzungen aus M0 + M1 (dürfen als vorhanden angenommen werden)

- App im Repo-Root; Branch `main`; `composer check` grün (Tags `m0`, `m1`). 116 Tests grün.
- `App\Models\User`: `#[Fillable(['name','email','password','discord_id','avatar_url','bio','steam_url','profile_color'])]` (Attribut, **kein** `$fillable`-Property); `role` (Cast `App\Enums\Role`) ist **nicht** fillable; `discord_id`, `avatar_url`, `bio`, `steam_url`, `profile_color`; `isAdmin()`, `isOrga()`; `UserFactory` mit `->admin()`/`->orga()`; `Notifiable`-Trait bereits eingebunden.
- `App\Modules\Events\Models\Event`: Felder `name, slug (unique, Route-Key), status (EventStatus), location, starts_at, ends_at, max_participants, settings (array)`; `EventStatus`-Enum mit `allowedTransitions()`, `canTransitionTo()`, `label()`; `TransitionEventStatus`-Action; `EventStatusChanged`-Event (payload `event/from/to`); `CurrentEvent::get(): ?Event`.
- Filament v5.6.8 Panel `admin` unter `/admin`; `canAccessPanel()` = `isOrga()`; Resource-Discovery via `discoverResources(in: app_path('Modules/Events/Filament/Resources'), for: 'App\\Modules\\Events\\Filament\\Resources')` im `AdminPanelProvider`. Resource-Struktur: `Resources/<Name>/{<Name>Resource, Schemas/<Name>Form, Tables/<Name>sTable, Pages/*}`. Header-/Record-Actions aus `Filament\Actions\*`, Notifications aus `Filament\Notifications\Notification`.
- Inertia-Shared-Prop `currentEvent` (Shape `name/slug/status/startsAt/endsAt/location`) in `HandleInertiaRequests::share()`.
- Frontend: Inertia v2 + Vue 3 + Tailwind 4 + shadcn-vue. Wayfinder-Route-Helper unter `@/routes` — **nach jeder Routen-Änderung neu generieren** (`php artisan wayfinder:generate` bzw. der im Repo etablierte Befehl; über laravel-boost verifizieren). Layout-Resolver in `resources/js/app.ts` strippt `AppLayout` für Seiten unter `Event/` und `Profile/` (Kommentar: öffentliche/layout-lose Seiten dort). **Teilnehmer-Seiten dieser Phase (Anmeldung, Sitzplan) liegen unter `Event/`** und erben damit bewusst kein AppLayout; sind sie auth-pflichtig, wird der Login-Redirect serverseitig via `auth`-Middleware erzwungen (kein Layout-Zwang).
- Test-DB via `.env.testing` (Postgres, `RefreshDatabase`). `route()`-Helper und Wayfinder verfügbar.

---

## Task-Übersicht

| Task | Roadmap | Modul | Ergebnis |
|---|---|---|---|
| 0 | Erkenntnis M1 | Events | `Event::isPubliclyVisible()` + Scope, Controller-Refactor, Filament-Slug-Spalte |
| 1 | 2.1 | Registration | `EventRegistration`-Model + Migration + Factory |
| 2 | 2.2 | Registration | `RegisterForEvent` / `CancelRegistration` Actions + Policy |
| 3 | 2.3 | Registration | Inertia-Anmeldeseite + „Meine Anmeldung" + QR-Anzeige; CTA-Verdrahtung |
| 4 | 2.4 | Registration | Filament Registrations-RelationManager (Suche, Paid-Toggle, CSV) |
| 5 | 2.5 | Registration | QR-Check-in (`CheckInRegistration` + Orga-Seite) |
| 6 | 2.6 | Seating | `seats`/`seat_assignments` + `ClaimSeat`/`ReleaseSeat` (DB-Unique-Race) |
| 7 | 2.7 | Seating | Filament Seat-Editor (Bulk-Raster, Einzel-Edit) |
| 8 | 2.8 | Seating | Teilnehmer-Sitzplan `Seating/Index.vue` (SVG-Raster) |
| 9 | 2.9 | Notifications | `database`-Channel-Grundgerüst + Glocken-Dropdown + `notification_prefs` |
| 10 | 2.10 | Discord | `DiscordClient`-Contract + `HttpDiscordClient` + `FakeDiscordClient` + config |
| 11 | 2.11 | Discord | `discord`-Notification-Channel + `discord_outbox` + `lanomat:send-reminders` |

---

### Task 0: Öffentliche Event-Sichtbarkeit als Domain-Helper (Erkenntnis M1)

**Files:**
- Modify: `app/Modules/Events/Models/Event.php` (Helper + Scope), `app/Modules/Events/Http/EventPageController.php` (Inline-Draft-Check → Helper), `app/Modules/Events/Filament/Resources/Events/Tables/EventsTable.php` (Slug/URL-Spalte), `lang/de/events.php`
- Test: `tests/Unit/Events/EventVisibilityTest.php`, ergänzt `tests/Feature/Events/EventPageTest.php`

**Interfaces:**
- Produces: `Event::isPubliclyVisible(): bool` (true für Status ∈ {announced, registration, live, finished, archived}; **false nur für draft**), Query-Scope `scopePubliclyVisible(Builder $q): Builder`. Konsumenten: `EventPageController::show()` (Task 0) und die Anmelde-CTA (Task 3).

> **Warum nicht in `EventPolicy`:** Deren `view()` bedeutet „darf ins Admin-Panel" (orga-only) und wird von Filament aufgerufen — eine Überladung mit öffentlicher Sichtbarkeit würde das Panel brechen (M1-Erkenntnis, verbindlich). Sichtbarkeit ist Domänen-Zustand des Events, kein User-Recht → Model-Helper.

- [ ] **Step 1: Failing Unit-Test**

Create `tests/Unit/Events/EventVisibilityTest.php`:

```php
<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

it('is not publicly visible while draft', function () {
    expect(Event::factory()->draft()->create()->isPubliclyVisible())->toBeFalse();
});

it('is publicly visible in every non-draft status', function (EventStatus $status) {
    expect(Event::factory()->status($status)->create()->isPubliclyVisible())->toBeTrue();
})->with([
    EventStatus::Announced,
    EventStatus::Registration,
    EventStatus::Live,
    EventStatus::Finished,
    EventStatus::Archived,
]);

it('scopes queries to publicly visible events', function () {
    Event::factory()->draft()->create();
    Event::factory()->registration()->create();
    Event::factory()->archived()->create();

    expect(Event::query()->publiclyVisible()->count())->toBe(2);
});
```

- [ ] **Step 2: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Unit/Events/EventVisibilityTest.php`
Expected: FAIL — `Call to undefined method …isPubliclyVisible()`.

- [ ] **Step 3: Helper + Scope ins Model**

Modify `app/Modules/Events/Models/Event.php` — ergänzen (Import `Illuminate\Database\Eloquent\Builder`):

```php
    public function isPubliclyVisible(): bool
    {
        return $this->status !== EventStatus::Draft;
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', EventStatus::Draft->value);
    }
```

- [ ] **Step 4: Controller-Refactor**

Modify `app/Modules/Events/Http/EventPageController.php` — den Inline-Draft-Check in `show()` auf den Helper umstellen (Import `NotFoundHttpException` bleibt):

```php
    public function show(Event $event): Response
    {
        if (! $event->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        return $this->renderShow($event);
    }
```

- [ ] **Step 5: Filament — Slug/öffentliche URL kopierbar**

Modify `app/Modules/Events/Filament/Resources/Events/Tables/EventsTable.php` — nach der `name`-Spalte eine kopierbare URL-Spalte ergänzen (Filament-v5-`TextColumn`-`copyable()`/`url()` über laravel-boost „filament/filament v5" Tables/Columns bestätigen):

```php
                TextColumn::make('slug')
                    ->label(__('events.fields.public_url'))
                    ->state(fn ($record) => route('events.show', ['event' => $record->slug]))
                    ->url(fn ($record) => route('events.show', ['event' => $record->slug]), shouldOpenInNewTab: true)
                    ->copyable()
                    ->copyMessage(__('events.fields.url_copied'))
                    ->toggleable(),
```

`lang/de/events.php` im `fields`-Block ergänzen:

```php
        'public_url' => 'Öffentlicher Link',
        'url_copied' => 'Link kopiert',
```

- [ ] **Step 6: Feature-Test — Slug-Spalte + i18n-Gate**

Ergänze `tests/Feature/Events/EventPageTest.php`:

```php
it('renders 404 for a draft event by slug (helper-driven)', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}")->assertNotFound();
});
```

Und ein Filament-Label-Test (i18n-Gate) in `tests/Feature/Events/EventResourceTest.php`:

```php
it('shows the german public-url column label', function () {
    Event::factory()->registration()->create();

    $this->actingAs(\App\Models\User::factory()->orga()->create())
        ->get('/admin/events')
        ->assertOk()
        ->assertSee('Öffentlicher Link');
});
```

- [ ] **Step 7: Grün + Gate**

Run: `./vendor/bin/pest tests/Unit/Events/EventVisibilityTest.php tests/Feature/Events`
Expected: PASS

Run: `composer check && npm run build`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(events): public visibility helper/scope, controller refactor and copyable slug column"
```

---

### Task 1: EventRegistration-Model + Migration + Factory (Roadmap 2.1)

**Files:**
- Create: `database/migrations/<ts>_create_event_registrations_table.php`, `app/Modules/Registration/Models/EventRegistration.php`, `app/Modules/Registration/Enums/RegistrationStatus.php`, `database/factories/EventRegistrationFactory.php`
- Test: `tests/Unit/Registration/EventRegistrationModelTest.php`

**Interfaces:**
- Produces: `App\Modules\Registration\Models\EventRegistration` mit `event_id, user_id, ticket_type, status (cast RegistrationStatus), paid_at (datetime, nullable), checked_in_at (datetime, nullable), qr_token (unique)`. UNIQUE(`event_id`,`user_id`). Relations `event()`, `user()`. `qr_token` wird beim Erstellen automatisch generiert (App-Code). `RegistrationStatus`-Enum (`Pending, Confirmed, Cancelled`).

> **Mass-Assignment-Disziplin (M0-Lehre):** `status`, `paid_at`, `checked_in_at`, `qr_token` sind **nicht** in `$fillable` — nur `event_id`, `user_id`, `ticket_type`. Statusfelder werden ausschließlich durch Actions (Task 2/5) und den `creating`-Hook gesetzt.

- [ ] **Step 1: RegistrationStatus-Enum**

Create `app/Modules/Registration/Enums/RegistrationStatus.php`:

```php
<?php

namespace App\Modules\Registration\Enums;

enum RegistrationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('registration.status.'.$this->value);
    }
}
```

- [ ] **Step 2: Failing Test**

Create `tests/Unit/Registration/EventRegistrationModelTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Database\QueryException;

it('generates a unique qr_token on creation', function () {
    $reg = EventRegistration::factory()->create();

    expect($reg->qr_token)->toBeString()->and(strlen($reg->qr_token))->toBeGreaterThanOrEqual(32);
});

it('casts status, paid_at and checked_in_at', function () {
    $reg = EventRegistration::factory()->create([
        'status' => RegistrationStatus::Confirmed,
    ]);

    expect($reg->fresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($reg->paid_at)->toBeNull()
        ->and($reg->checked_in_at)->toBeNull();
});

it('exposes event and user relations', function () {
    $reg = EventRegistration::factory()->create();

    expect($reg->event)->toBeInstanceOf(Event::class)
        ->and($reg->user)->toBeInstanceOf(User::class);
});

it('forbids a second registration of the same user for the same event', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    expect(fn () => EventRegistration::factory()->for($event)->for($user)->create())
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Unit/Registration/EventRegistrationModelTest.php`
Expected: FAIL — Model/Tabelle fehlen.

- [ ] **Step 4: Migration**

`php artisan make:migration create_event_registrations_table`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_type');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->string('qr_token')->unique();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
```

- [ ] **Step 5: Model**

Create `app/Modules/Registration/Models/EventRegistration.php`:

```php
<?php

namespace App\Modules\Registration\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use Database\Factories\EventRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property RegistrationStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $checked_in_at
 * @property string $qr_token
 */
class EventRegistration extends Model
{
    /** @use HasFactory<EventRegistrationFactory> */
    use HasFactory;

    // status/paid_at/checked_in_at/qr_token deliberately NOT fillable
    // (privilege/state fields — set only via actions or the creating hook).
    protected $fillable = [
        'event_id',
        'user_id',
        'ticket_type',
    ];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'paid_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventRegistration $registration): void {
            if (blank($registration->qr_token)) {
                $registration->qr_token = Str::random(40);
            }
        });
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): EventRegistrationFactory
    {
        return EventRegistrationFactory::new();
    }
}
```

- [ ] **Step 6: Factory**

Create `database/factories/EventRegistrationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRegistration>
 */
class EventRegistrationFactory extends Factory
{
    protected $model = EventRegistration::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'ticket_type' => 'standard',
            'status' => RegistrationStatus::Confirmed,
            // qr_token intentionally omitted: generated by the creating hook
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => RegistrationStatus::Pending]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => RegistrationStatus::Cancelled]);
    }

    public function paid(): static
    {
        return $this->state(['paid_at' => now()]);
    }

    public function checkedIn(): static
    {
        return $this->state(['checked_in_at' => now()]);
    }
}
```

- [ ] **Step 7: Grün + Gate**

Run: `php artisan migrate && ./vendor/bin/pest tests/Unit/Registration/EventRegistrationModelTest.php`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(registration): EventRegistration model, migration, factory with qr_token and status enum"
```

---

### Task 2: RegisterForEvent / CancelRegistration Actions + Policy (Roadmap 2.2)

**Files:**
- Create: `app/Modules/Registration/Actions/RegisterForEvent.php`, `app/Modules/Registration/Actions/CancelRegistration.php`, `app/Modules/Registration/Policies/RegistrationPolicy.php`, `app/Modules/Registration/Exceptions/RegistrationException.php`
- Modify: `app/Providers/AppServiceProvider.php` (`Gate::policy`), `lang/de/registration.php`
- Test: `tests/Feature/Registration/RegisterForEventTest.php`, `tests/Feature/Registration/CancelRegistrationTest.php`

**Interfaces:**
- Produces:
  - `RegisterForEvent::handle(Event $event, User $user, string $ticketType): EventRegistration` — Vorbedingungen: Event-Status = `registration`; `ticketType` ∈ `event.settings['tickets']` (Liste erlaubter Ticket-Typen); `max_participants` nicht überschritten (zählt nur nicht-cancelled Registrierungen); keine bestehende nicht-cancelled Anmeldung desselben Users. Verstöße werfen `RegistrationException` (Domain). Erfolg → Registration mit `status = Confirmed` (LAN-Realität: Bestätigung sofort, Bezahlung manuell durch Orga).
  - `CancelRegistration::handle(EventRegistration $registration): EventRegistration` — setzt `status = Cancelled` (kein Hard-Delete, gibt später Sitzplatz frei — Seat-Release folgt in Task 6/Listener; hier nur Statuswechsel). Idempotenz-Guard: erneutes Cancel ist ein No-op auf bereits Cancelled.
  - `RegistrationPolicy`: `create(User, Event)` (jeder eingeloggte User darf sich anmelden, wenn Event `registration`), `cancel(User, EventRegistration)` (nur eigene Registrierung ODER orga), `viewAny`/`update` orga-only (für Filament in Task 4).

> **Ticket-Quelle:** `event.settings['tickets']` ist eine Liste von Strings (z. B. `['early_bird', 'standard']`). Fehlt der Schlüssel oder ist leer, gilt `['standard']` als Default (damit ein frisch angelegtes Event ohne Ticket-Konfig anmeldbar bleibt).

- [ ] **Step 1: Exception**

Create `app/Modules/Registration/Exceptions/RegistrationException.php`:

```php
<?php

namespace App\Modules\Registration\Exceptions;

use DomainException;

class RegistrationException extends DomainException
{
    public static function eventNotOpen(): self
    {
        return new self('The event is not open for registration.');
    }

    public static function full(): self
    {
        return new self('The event has reached its participant limit.');
    }

    public static function invalidTicketType(string $type): self
    {
        return new self("Unknown ticket type: {$type}.");
    }

    public static function alreadyRegistered(): self
    {
        return new self('The user is already registered for this event.');
    }
}
```

- [ ] **Step 2: Failing Tests — RegisterForEvent (voll, doppelt, falscher Status, Ticket)**

Create `tests/Feature/Registration/RegisterForEventTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;

function register(Event $event, User $user, string $ticket = 'standard'): EventRegistration
{
    return app(RegisterForEvent::class)->handle($event, $user, $ticket);
}

it('registers a user for an open event as confirmed', function () {
    $event = Event::factory()->registration()->create([
        'settings' => ['tickets' => ['standard', 'early_bird']],
    ]);

    $reg = register($event, User::factory()->create(), 'early_bird');

    expect($reg->status)->toBe(RegistrationStatus::Confirmed)
        ->and($reg->ticket_type)->toBe('early_bird');
});

it('defaults tickets to [standard] when settings has none', function () {
    $event = Event::factory()->registration()->create(['settings' => []]);

    expect(register($event, User::factory()->create(), 'standard')->ticket_type)->toBe('standard');
});

it('rejects an unknown ticket type', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);

    expect(fn () => register($event, User::factory()->create(), 'vip'))
        ->toThrow(RegistrationException::class);
});

it('rejects registration when the event is not in registration status', function () {
    $event = Event::factory()->announced()->create();

    expect(fn () => register($event, User::factory()->create()))
        ->toThrow(RegistrationException::class);
});

it('rejects registration when the participant limit is reached', function () {
    $event = Event::factory()->registration()->create(['max_participants' => 1]);
    register($event, User::factory()->create());

    expect(fn () => register($event, User::factory()->create()))
        ->toThrow(RegistrationException::class);
});

it('does not count cancelled registrations toward the limit', function () {
    $event = Event::factory()->registration()->create(['max_participants' => 1]);
    $first = register($event, User::factory()->create());
    app(\App\Modules\Registration\Actions\CancelRegistration::class)->handle($first);

    expect(register($event, User::factory()->create())->status)->toBe(RegistrationStatus::Confirmed);
});

it('rejects a double registration of the same user', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    register($event, $user);

    expect(fn () => register($event, $user))->toThrow(RegistrationException::class);
});
```

Create `tests/Feature/Registration/CancelRegistrationTest.php`:

```php
<?php

use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;

it('cancels a registration', function () {
    $reg = EventRegistration::factory()->create();

    $result = app(CancelRegistration::class)->handle($reg);

    expect($result->status)->toBe(RegistrationStatus::Cancelled)
        ->and($reg->fresh()->status)->toBe(RegistrationStatus::Cancelled);
});

it('is idempotent on an already cancelled registration', function () {
    $reg = EventRegistration::factory()->cancelled()->create();

    expect(app(CancelRegistration::class)->handle($reg)->status)
        ->toBe(RegistrationStatus::Cancelled);
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Registration`
Expected: FAIL — Actions fehlen.

- [ ] **Step 4: Actions**

Create `app/Modules/Registration/Actions/RegisterForEvent.php`:

```php
<?php

namespace App\Modules\Registration\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Support\Facades\DB;

class RegisterForEvent
{
    public function handle(Event $event, User $user, string $ticketType): EventRegistration
    {
        if ($event->status !== EventStatus::Registration) {
            throw RegistrationException::eventNotOpen();
        }

        if (! in_array($ticketType, $this->allowedTickets($event), true)) {
            throw RegistrationException::invalidTicketType($ticketType);
        }

        return DB::transaction(function () use ($event, $user, $ticketType): EventRegistration {
            $active = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('status', '!=', RegistrationStatus::Cancelled->value)
                ->lockForUpdate();

            if ((clone $active)->where('user_id', $user->id)->exists()) {
                throw RegistrationException::alreadyRegistered();
            }

            if ($event->max_participants !== null && (clone $active)->count() >= $event->max_participants) {
                throw RegistrationException::full();
            }

            $registration = new EventRegistration([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'ticket_type' => $ticketType,
            ]);
            $registration->status = RegistrationStatus::Confirmed;
            $registration->save();

            return $registration;
        });
    }

    /**
     * @return array<int, string>
     */
    private function allowedTickets(Event $event): array
    {
        $tickets = $event->settings['tickets'] ?? [];

        return empty($tickets) ? ['standard'] : array_values($tickets);
    }
}
```

Create `app/Modules/Registration/Actions/CancelRegistration.php`:

```php
<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;

class CancelRegistration
{
    public function handle(EventRegistration $registration): EventRegistration
    {
        if ($registration->status === RegistrationStatus::Cancelled) {
            return $registration;
        }

        $registration->status = RegistrationStatus::Cancelled;
        $registration->save();

        return $registration;
    }
}
```

> **Concurrency-Hinweis:** Die `lockForUpdate()`-Zeile serialisiert konkurrierende Anmeldungen aufs selbe Event, damit das `max_participants`-Fenster nicht durch eine Race überlaufen wird. Die UNIQUE(`event_id`,`user_id`) fängt das Doppel-Anmelde-Rennen zusätzlich auf DB-Ebene (redundant, aber korrekt).

- [ ] **Step 5: Policy + Registrierung**

Create `app/Modules/Registration/Policies/RegistrationPolicy.php`:

```php
<?php

namespace App\Modules\Registration\Policies;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;

class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function create(User $user, Event $event): bool
    {
        return $event->status === EventStatus::Registration;
    }

    public function cancel(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga() || $registration->user_id === $user->id;
    }

    public function update(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga();
    }
}
```

In `app/Providers/AppServiceProvider.php::boot()` registrieren:

```php
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Policies\RegistrationPolicy;
use Illuminate\Support\Facades\Gate;

Gate::policy(EventRegistration::class, RegistrationPolicy::class);
```

- [ ] **Step 6: Deutsche Status-Labels (i18n)**

Create `lang/de/registration.php`:

```php
<?php

return [
    'status' => [
        'pending' => 'Ausstehend',
        'confirmed' => 'Bestätigt',
        'cancelled' => 'Storniert',
    ],
];
```

- [ ] **Step 7: Grün + Gate**

Run: `./vendor/bin/pest tests/Feature/Registration`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(registration): RegisterForEvent/CancelRegistration actions with policy and domain guards"
```

---

### Task 3: Inertia-Anmeldeseite + „Meine Anmeldung" + QR-Anzeige + CTA-Verdrahtung (Roadmap 2.3)

**Files:**
- Install: `bacon/bacon-qr-code`
- Create: `app/Modules/Registration/Http/RegistrationController.php`, `app/Modules/Registration/Http/Requests/RegisterRequest.php`, `app/Modules/Registration/Support/QrCode.php`, `resources/js/pages/Event/Register.vue`
- Modify: `routes/web.php`, `resources/js/pages/Event/Show.vue` (CTA-Verdrahtung), `app/Modules/Events/Http/EventPageController.php` (Registrierungs-Kontext an `Event/Show`), `lang/de/registration.php`, `resources/js/types/index.d.ts`
- Test: `tests/Feature/Registration/RegistrationPageTest.php`

**Interfaces:**
- Produces (auth-pflichtig):
  - `GET /events/{event:slug}/register` (name `events.register`) → `Event/Register` (Ticket-Auswahl **oder** „Meine Anmeldung" mit QR, wenn bereits angemeldet).
  - `POST /events/{event:slug}/register` (name `events.register.store`) → ruft `RegisterForEvent`, Redirect zurück mit Flash.
  - `DELETE /events/{event:slug}/register` (name `events.register.destroy`) → ruft `CancelRegistration` (Policy `cancel`), Redirect.
  - `App\Modules\Registration\Support\QrCode::svg(string $token): string` — rendert einen QR-SVG-String aus dem `qr_token` (on-the-fly, kein Blob).
- Der CTA-Button auf `Event/Show` verlinkt bei Status `registration` auf `events.register`; in allen anderen Status ist er **disabled** mit `aria-disabled="true"` und passendem Label (Erkenntnis M1: disabled/aria-Semantik mitliefern).

> **Verify first (2026):** Vor `composer require bacon/bacon-qr-code` die aktuelle stabile Version + API (`BaconQrCode\Writer` + `SvgImageBackEnd`/`ImagickImageBackEnd`) über context7 („bacon/bacon-qr-code") oder Packagist prüfen. **Prefer first-party** wurde geprüft: Laravel hat keinen eingebauten QR-Renderer; `simple-qrcode` (Wrapper) ist unmaintained → direkt `bacon/bacon-qr-code` (die etablierte Low-Level-Lib) als reine SVG-Ausgabe ohne Imagick-Zwang.

- [ ] **Step 1: Paket installieren (nach Doku-Check)**

```bash
composer require bacon/bacon-qr-code
```

- [ ] **Step 2: QR-Helper**

Create `app/Modules/Registration/Support/QrCode.php`:

```php
<?php

namespace App\Modules\Registration\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCode
{
    public function svg(string $token): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($token);
    }
}
```

> Kein Imagick-Backend → keine PHP-Extension-Abhängigkeit; die SVG geht als String an Inertia und wird per `v-html` in einen sanitisierten Container gerendert (reiner QR-SVG, keine User-Eingabe).

- [ ] **Step 3: Failing Feature-Test**

Create `tests/Feature/Registration/RegistrationPageTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Inertia\Testing\AssertableInertia;

it('requires auth to view the registration page', function () {
    $event = Event::factory()->registration()->create();

    $this->get("/events/{$event->slug}/register")->assertRedirect(route('login.discord'));
});

it('shows ticket options with german labels when not yet registered', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/register")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Register')
            ->where('registration', null)
            ->has('tickets', 1)
            ->where('labels.title', 'Zum Event anmelden')
        );
});

it('creates a registration on POST', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])
        ->assertRedirect();

    expect(EventRegistration::where('user_id', $user->id)->first()->status)
        ->toBe(RegistrationStatus::Confirmed);
});

it('shows my registration with a qr code when already registered', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->get("/events/{$event->slug}/register")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.ticketType', 'standard')
            ->has('registration.qrSvg')
        );
});

it('cancels my registration on DELETE', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->delete("/events/{$event->slug}/register")
        ->assertRedirect();

    expect(EventRegistration::where('user_id', $user->id)->first()->status)
        ->toBe(RegistrationStatus::Cancelled);
});

it('forbids cancelling someone else registration', function () {
    $event = Event::factory()->registration()->create();
    EventRegistration::factory()->for($event)->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->delete("/events/{$event->slug}/register")
        ->assertRedirect(); // no active registration for this user -> no-op redirect, nothing cancelled
});
```

- [ ] **Step 4: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Registration/RegistrationPageTest.php`
Expected: FAIL — Route/Controller fehlen.

- [ ] **Step 5: FormRequest + Controller**

Create `app/Modules/Registration/Http/Requests/RegisterRequest.php`:

```php
<?php

namespace App\Modules\Registration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'ticket_type' => ['required', 'string', 'max:64'],
        ];
    }
}
```

Create `app/Modules/Registration/Http/RegistrationController.php`:

```php
<?php

namespace App\Modules\Registration\Http;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Http\Requests\RegisterRequest;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController
{
    public function show(Request $request, Event $event, QrCode $qr): Response
    {
        $registration = $this->activeRegistration($event, $request->user()->id);

        return Inertia::render('Event/Register', [
            'event' => ['name' => $event->name, 'slug' => $event->slug, 'status' => $event->status->value],
            'tickets' => $this->tickets($event),
            'registration' => $registration === null ? null : [
                'ticketType' => $registration->ticket_type,
                'status' => $registration->status->value,
                'paid' => $registration->paid_at !== null,
                'checkedIn' => $registration->checked_in_at !== null,
                'qrSvg' => $qr->svg($registration->qr_token),
            ],
            'labels' => trans('registration.page'),
            'statusLabels' => trans('registration.status'),
        ]);
    }

    public function store(RegisterRequest $request, Event $event, RegisterForEvent $action): RedirectResponse
    {
        $this->authorize('create', [EventRegistration::class, $event]);

        $action->handle($event, $request->user(), $request->validated()['ticket_type']);

        return back();
    }

    public function destroy(Request $request, Event $event, CancelRegistration $action): RedirectResponse
    {
        $registration = $this->activeRegistration($event, $request->user()->id);

        if ($registration !== null) {
            $this->authorizeForUser($request->user(), 'cancel', $registration);
            $action->handle($registration);
        }

        return back();
    }

    private function activeRegistration(Event $event, int $userId): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function tickets(Event $event): array
    {
        $tickets = $event->settings['tickets'] ?? [];

        return empty($tickets) ? ['standard'] : array_values($tickets);
    }
}
```

> Der Controller nutzt `authorize()`/`authorizeForUser()` (Gate) — **nie** eine Client-User-ID; die User-ID kommt immer aus `$request->user()`. `store()` autorisiert über `RegistrationPolicy::create` (prüft Event-Status zusätzlich zur Action). `AuthorizesRequests`-Trait am Controller einbinden (bzw. Base-Controller nutzen — im Repo-Standard prüfen).

- [ ] **Step 6: Routen**

`routes/web.php` in der `auth`-Gruppe ergänzen:

```php
use App\Modules\Registration\Http\RegistrationController;

Route::middleware(['auth'])->group(function () {
    Route::get('/events/{event:slug}/register', [RegistrationController::class, 'show'])->name('events.register');
    Route::post('/events/{event:slug}/register', [RegistrationController::class, 'store'])->name('events.register.store');
    Route::delete('/events/{event:slug}/register', [RegistrationController::class, 'destroy'])->name('events.register.destroy');
});
```

> **Wayfinder:** Nach dieser Routen-Änderung die `@/routes`-Helper neu generieren (siehe Voraussetzungen). Die Vue-Seiten nutzen die generierten Helper statt `route()`-Strings, wo im Repo etabliert.

- [ ] **Step 7: Übersetzungen für die Anmeldeseite**

`lang/de/registration.php` um `page` ergänzen:

```php
    'page' => [
        'title' => 'Zum Event anmelden',
        'choose_ticket' => 'Ticket wählen',
        'register' => 'Jetzt anmelden',
        'my_registration' => 'Meine Anmeldung',
        'ticket' => 'Ticket',
        'qr_hint' => 'Zeige diesen Code beim Check-in vor Ort.',
        'paid' => 'Bezahlt',
        'unpaid' => 'Zahlung offen',
        'checked_in' => 'Eingecheckt',
        'cancel' => 'Anmeldung stornieren',
        'closed' => 'Die Anmeldung ist derzeit nicht geöffnet.',
    ],
```

- [ ] **Step 8: Vue-Seite `Event/Register.vue`**

Create `resources/js/pages/Event/Register.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    event: { name: string; slug: string; status: string };
    tickets: string[];
    registration: {
        ticketType: string;
        status: string;
        paid: boolean;
        checkedIn: boolean;
        qrSvg: string;
    } | null;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
}>();

const selected = ref(props.tickets[0] ?? 'standard');

const registerForm = useForm({ ticket_type: selected });
const cancelForm = useForm({});

function submit() {
    registerForm.ticket_type = selected.value;
    registerForm.post(route('events.register.store', { event: props.event.slug }), { preserveScroll: true });
}

function cancel() {
    cancelForm.delete(route('events.register.destroy', { event: props.event.slug }), { preserveScroll: true });
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-lg px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">{{ event.name }}</h1>

        <section v-if="registration" class="mt-8 space-y-6">
            <h2 class="text-xl font-semibold">{{ labels.my_registration }}</h2>
            <div class="rounded-lg border border-border p-4">
                <!-- eslint-disable-next-line vue/no-v-html -- server-rendered QR SVG, no user input -->
                <div class="mx-auto w-64" v-html="registration.qrSvg" />
                <p class="mt-3 text-center text-sm text-muted-foreground">{{ labels.qr_hint }}</p>
            </div>
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-muted-foreground">{{ labels.ticket }}</dt>
                <dd>{{ registration.ticketType }}</dd>
                <dt class="text-muted-foreground">{{ labels.paid }}</dt>
                <dd>{{ registration.paid ? labels.paid : labels.unpaid }}</dd>
            </dl>
            <Button variant="destructive" :disabled="cancelForm.processing" @click="cancel">
                {{ labels.cancel }}
            </Button>
        </section>

        <section v-else-if="event.status === 'registration'" class="mt-8 space-y-6">
            <h2 class="text-xl font-semibold">{{ labels.choose_ticket }}</h2>
            <div class="space-y-2">
                <label
                    v-for="ticket in tickets"
                    :key="ticket"
                    class="flex items-center gap-3 rounded-md border border-border p-3"
                >
                    <input v-model="selected" type="radio" :value="ticket" name="ticket" />
                    <span>{{ ticket }}</span>
                </label>
            </div>
            <Button :disabled="registerForm.processing" @click="submit">{{ labels.register }}</Button>
        </section>

        <p v-else class="mt-8 text-muted-foreground">{{ labels.closed }}</p>

        <Link :href="route('events.show', { event: event.slug })" class="mt-6 inline-block text-sm underline">
            {{ event.name }}
        </Link>
    </main>
</template>
```

- [ ] **Step 9: CTA-Verdrahtung auf `Event/Show.vue`**

Der aktuelle CTA (`<Button v-if="cta" size="lg">{{ cta }}</Button>`) ist inert. Ersetzen durch eine statusabhängige Variante: bei `registration` ein `Link`-Button auf `events.register`, sonst disabled mit `aria-disabled`.

Modify `resources/js/pages/Event/Show.vue` — CTA-Block:

```vue
        <div class="mt-10 flex flex-wrap gap-3">
            <Button
                v-if="event.status === 'registration'"
                as-child
                size="lg"
            >
                <Link :href="route('events.register', { event: event.slug })">{{ cta }}</Link>
            </Button>
            <Button
                v-else-if="cta"
                size="lg"
                disabled
                :aria-disabled="true"
            >
                {{ cta }}
            </Button>
            <Button as-child variant="outline">
                <Link :href="route('events.index')">{{ labels.to_archive }}</Link>
            </Button>
        </div>
```

> Der `cta`-Text kommt weiterhin aus `labels.cta[status]` (M1); nur der Registrierungs-Status bekommt ein Ziel, alle anderen bleiben als disabled-Hinweis sichtbar (announced „Bald…", live „Event läuft").

- [ ] **Step 10: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Feature/Registration/RegistrationPageTest.php`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add -A && git commit -m "feat(registration): registration page with qr code, my-registration view and event CTA wiring"
```

---

### Task 4: Filament Registrations-RelationManager (Roadmap 2.4)

**Files:**
- Create: `app/Modules/Registration/Filament/RelationManagers/RegistrationsRelationManager.php`
- Modify: `app/Modules/Events/Filament/Resources/Events/EventResource.php` (`getRelations()`), `app/Modules/Events/Models/Event.php` (`registrations()`-Relation), `lang/de/registration.php`
- Test: `tests/Feature/Registration/RegistrationRelationManagerTest.php`

**Interfaces:**
- Produces: RelationManager am Event mit Spalten (User-Name, Ticket, Status-Badge, Bezahlt, Eingecheckt), Suche über User-Name, Row-Action „Bezahlt umschalten" (setzt/entfernt `paid_at` — **nur über Action, nie Client-fillable**), Header-Action CSV-Export. Nur `isOrga()`.

> **Modul-Grenze:** Die Event→Registrations-`hasMany`-Relation lebt am `Event`-Model (Events-Modul „kennt" seine Registrierungen konzeptuell als Aggregat), zeigt aber auf das Registration-Modul-Model — das ist erlaubt (Aggregatwurzel referenziert ihre Kinder). Der RelationManager liegt im **Registration**-Modul.

> **Verify first (2026):** Filament-v5-RelationManager-API + `discoverResources`/RelationManager-Discovery und CSV-Export über laravel-boost („filament/filament v5" — Relation Managers, Actions, `Filament\Actions\Exports\ExportAction` bzw. einfacher StreamedResponse) prüfen. Für den CSV-Export genügt in v5 eine eigene Header-`Action` mit `->action()`, die eine `StreamedResponse` zurückgibt — kein zusätzliches Paket, falls die Filament-Export-Feature Queue/DB-Setup verlangt (YAGNI für LAN-Scale). Doku-Entscheidung im Commit vermerken.

- [ ] **Step 1: Event-Relation**

Modify `app/Modules/Events/Models/Event.php` — ergänzen:

```php
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use App\Modules\Registration\Models\EventRegistration;

    /** @return HasMany<EventRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }
```

- [ ] **Step 2: Failing Test**

Create `tests/Feature/Registration/RegistrationRelationManagerTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;

it('lists registrations on the event edit page for orga', function () {
    $event = Event::factory()->registration()->create();
    $participant = User::factory()->create(['name' => 'QR Tester']);
    EventRegistration::factory()->for($event)->for($participant)->create();

    $this->actingAs(User::factory()->orga()->create())
        ->get("/admin/events/{$event->id}/edit")
        ->assertOk()
        ->assertSee('QR Tester')
        ->assertSee('Bestätigt'); // german status label -> i18n gate
});
```

> Optional (Livewire-Component-Test des RelationManagers für den Paid-Toggle): `Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])->callTableAction('toggle_paid', $registration)` und `paid_at`-Assertion — API in der Filament-v5-Testing-Doku verifizieren.

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Registration/RegistrationRelationManagerTest.php`
Expected: FAIL — RelationManager nicht registriert (Registrations erscheinen nicht auf der Edit-Page).

- [ ] **Step 4: RelationManager**

Create `app/Modules/Registration/Filament/RelationManagers/RegistrationsRelationManager.php`:

```php
<?php

namespace App\Modules\Registration\Filament\RelationManagers;

use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('registration.admin.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('registration.admin.participant'))
                    ->searchable(),
                TextColumn::make('ticket_type')->label(__('registration.admin.ticket')),
                TextColumn::make('status')
                    ->label(__('registration.admin.status'))
                    ->badge()
                    ->formatStateUsing(fn (RegistrationStatus $state) => $state->label()),
                IconColumn::make('paid_at')
                    ->label(__('registration.admin.paid'))
                    ->boolean()
                    ->state(fn (EventRegistration $r) => $r->paid_at !== null),
                IconColumn::make('checked_in_at')
                    ->label(__('registration.admin.checked_in'))
                    ->boolean()
                    ->state(fn (EventRegistration $r) => $r->checked_in_at !== null),
            ])
            ->recordActions([
                Action::make('toggle_paid')
                    ->label(__('registration.admin.toggle_paid'))
                    ->action(function (EventRegistration $record): void {
                        $record->paid_at = $record->paid_at === null ? now() : null;
                        $record->save();
                    }),
            ])
            ->headerActions([
                Action::make('export_csv')
                    ->label(__('registration.admin.export'))
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ]);
    }

    private function exportCsv(): StreamedResponse
    {
        /** @var EventRegistration[] $rows */
        $rows = $this->getOwnerRecord()->registrations()->with('user')->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'ticket_type', 'status', 'paid_at', 'checked_in_at']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->user->name,
                    $r->ticket_type,
                    $r->status->value,
                    $r->paid_at?->toIso8601String(),
                    $r->checked_in_at?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, 'registrations.csv');
    }
}
```

> `paid_at` wird **nur hier** in einer Orga-Action gesetzt — nie über Mass-Assignment/Client. Das ist der einzige Ort, an dem der Bezahlstatus wechselt (v1-Realität: manuell durch Orga).

- [ ] **Step 5: RelationManager registrieren**

Modify `app/Modules/Events/Filament/Resources/Events/EventResource.php::getRelations()`:

```php
    use App\Modules\Registration\Filament\RelationManagers\RegistrationsRelationManager;

    public static function getRelations(): array
    {
        return [
            RegistrationsRelationManager::class,
        ];
    }
```

- [ ] **Step 6: Übersetzungen**

`lang/de/registration.php` um `admin` ergänzen:

```php
    'admin' => [
        'title' => 'Anmeldungen',
        'participant' => 'Teilnehmer',
        'ticket' => 'Ticket',
        'status' => 'Status',
        'paid' => 'Bezahlt',
        'checked_in' => 'Eingecheckt',
        'toggle_paid' => 'Bezahlt umschalten',
        'export' => 'CSV-Export',
    ],
```

- [ ] **Step 7: Grün + Gate**

Run: `./vendor/bin/pest tests/Feature/Registration/RegistrationRelationManagerTest.php`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(registration): filament registrations relation manager with paid toggle and csv export"
```

---

### Task 5: QR-Check-in (Roadmap 2.5)

**Files:**
- Install: `vue-qrcode-reader`
- Create: `app/Modules/Registration/Actions/CheckInRegistration.php`, `app/Modules/Registration/Exceptions/CheckInException.php`, `app/Modules/Registration/Http/CheckInController.php`, `app/Modules/Registration/Http/Requests/CheckInRequest.php`, `resources/js/pages/Orga/CheckIn.vue`
- Modify: `routes/web.php`, `lang/de/registration.php`, `resources/js/app.ts` (Layout-Resolver: `Orga/`-Prefix)
- Test: `tests/Feature/Registration/CheckInTest.php`

**Interfaces:**
- Produces:
  - `CheckInRegistration::handle(Event $event, string $qrToken): EventRegistration` — sucht Registration per `qr_token` **innerhalb des angegebenen Events**; wirft `CheckInException` bei: unbekanntem Token, Token eines anderen Events, bereits eingechecktem Teilnehmer, stornierter Anmeldung. Erfolg → setzt `checked_in_at = now()`.
  - `POST /orga/events/{event:slug}/checkin` (name `orga.checkin.store`) — `role:orga`-geschützt, validiert `qr_token` **serverseitig** über die Action, gibt JSON/Redirect mit Teilnehmerinfo zurück.
  - `GET /orga/events/{event:slug}/checkin` (name `orga.checkin`) → `Orga/CheckIn`-Seite (Kamera-Scan **oder** manuelle Token-Eingabe).

> **Sicherheits-Disziplin (M0):** Der `qr_token` wird **server-seitig** validiert; die Policy verlangt orga/admin (nie eine Client-User-ID vertrauen). `checked_in_at` ist nicht fillable — nur die Action setzt es. Das Event kommt aus der Route (nicht aus dem Client-Payload), damit „falsches Event" serverseitig erkannt wird.

> **Verify first (2026):** `vue-qrcode-reader` aktuelle stabile Version + Vue-3-Kompatibilität (Named-Export `QrcodeStream`) über npm/context7 prüfen. Kamera braucht HTTPS-Kontext; die manuelle Token-Eingabe ist der garantiert funktionierende Fallback (Test deckt nur den Server-Pfad ab).

- [ ] **Step 1: Exception + Action**

Create `app/Modules/Registration/Exceptions/CheckInException.php`:

```php
<?php

namespace App\Modules\Registration\Exceptions;

use DomainException;

class CheckInException extends DomainException
{
    public static function unknownToken(): self
    {
        return new self('No registration matches this QR token for this event.');
    }

    public static function alreadyCheckedIn(): self
    {
        return new self('This registration is already checked in.');
    }

    public static function notConfirmed(): self
    {
        return new self('This registration is not active.');
    }
}
```

Create `app/Modules/Registration/Actions/CheckInRegistration.php`:

```php
<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;

class CheckInRegistration
{
    public function handle(Event $event, string $qrToken): EventRegistration
    {
        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('qr_token', $qrToken)
            ->first();

        if ($registration === null) {
            throw CheckInException::unknownToken();
        }

        if ($registration->status === RegistrationStatus::Cancelled) {
            throw CheckInException::notConfirmed();
        }

        if ($registration->checked_in_at !== null) {
            throw CheckInException::alreadyCheckedIn();
        }

        $registration->checked_in_at = now();
        $registration->save();

        return $registration;
    }
}
```

- [ ] **Step 2: Failing Tests — unbekannt / doppelt / falsches Event**

Create `tests/Feature/Registration/CheckInTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;

it('checks in a registration by qr token', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $result = app(CheckInRegistration::class)->handle($event, $reg->qr_token);

    expect($result->checked_in_at)->not->toBeNull()
        ->and($reg->fresh()->checked_in_at)->not->toBeNull();
});

it('rejects an unknown token', function () {
    $event = Event::factory()->live()->create();

    expect(fn () => app(CheckInRegistration::class)->handle($event, 'nope'))
        ->toThrow(CheckInException::class);
});

it('rejects a token belonging to another event', function () {
    $eventA = Event::factory()->live()->create();
    $eventB = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($eventB)->create();

    expect(fn () => app(CheckInRegistration::class)->handle($eventA, $reg->qr_token))
        ->toThrow(CheckInException::class);
});

it('rejects a double check-in', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->checkedIn()->create();

    expect(fn () => app(CheckInRegistration::class)->handle($event, $reg->qr_token))
        ->toThrow(CheckInException::class);
});

it('rejects check-in of a cancelled registration', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->cancelled()->create();

    expect(fn () => app(CheckInRegistration::class)->handle($event, $reg->qr_token))
        ->toThrow(CheckInException::class);
});

it('forbids non-orga from the check-in endpoint', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token])
        ->assertForbidden();
});

it('checks in via the orga endpoint', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->orga()->create())
        ->post("/orga/events/{$event->slug}/checkin", ['qr_token' => $reg->qr_token])
        ->assertRedirect();

    expect($reg->fresh()->checked_in_at)->not->toBeNull();
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Registration/CheckInTest.php`
Expected: FAIL — Action existiert (Step 1), aber Route/Controller fehlen → die HTTP-Tests failen.

- [ ] **Step 4: FormRequest + Controller**

Create `app/Modules/Registration/Http/Requests/CheckInRequest.php`:

```php
<?php

namespace App\Modules\Registration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOrga() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'max:64'],
        ];
    }
}
```

Create `app/Modules/Registration/Http/CheckInController.php`:

```php
<?php

namespace App\Modules\Registration\Http;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Http\Requests\CheckInRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CheckInController
{
    public function show(Event $event): Response
    {
        return Inertia::render('Orga/CheckIn', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'labels' => trans('registration.checkin'),
        ]);
    }

    public function store(CheckInRequest $request, Event $event, CheckInRegistration $action): RedirectResponse
    {
        try {
            $registration = $action->handle($event, $request->validated()['qr_token']);
        } catch (CheckInException $e) {
            return back()->with('error', $e->getMessage());
        }

        $registration->loadMissing('user');

        return back()->with('success', __('registration.checkin.done', ['name' => $registration->user->name]));
    }
}
```

- [ ] **Step 5: Routen (role:orga)**

`routes/web.php`:

```php
use App\Modules\Registration\Http\CheckInController;

Route::middleware(['auth', 'role:orga'])->group(function () {
    Route::get('/orga/events/{event:slug}/checkin', [CheckInController::class, 'show'])->name('orga.checkin');
    Route::post('/orga/events/{event:slug}/checkin', [CheckInController::class, 'store'])->name('orga.checkin.store');
});
```

> Wayfinder-Helper danach neu generieren. `role:orga` ist der M0-Middleware-Alias (admin passiert via `Gate::before`).

- [ ] **Step 6: Layout-Resolver + Übersetzungen**

`resources/js/app.ts` — im Layout-Resolver `Orga/` als layout-lose Prefix ergänzen (analog zu `Event/`, weil die Scan-Seite Vollbild/fokussiert sein soll):

```ts
            case name.startsWith('Event/'):
            case name.startsWith('Profile/'):
            case name.startsWith('Orga/'):
                return null;
```

`lang/de/registration.php` um `checkin` ergänzen:

```php
    'checkin' => [
        'title' => 'Check-in',
        'scan' => 'QR-Code scannen',
        'manual' => 'Token manuell eingeben',
        'submit' => 'Einchecken',
        'done' => ':name eingecheckt.',
    ],
```

- [ ] **Step 7: Vue-Seite `Orga/CheckIn.vue`**

Create `resources/js/pages/Orga/CheckIn.vue` (Kamera via `vue-qrcode-reader`, manueller Fallback; Named-Export gegen Doku prüfen):

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { QrcodeStream } from 'vue-qrcode-reader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    event: { name: string; slug: string };
    labels: Record<string, string>;
}>();

const form = useForm({ qr_token: '' });

function submit() {
    form.post(route('orga.checkin.store', { event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => form.reset('qr_token'),
    });
}

function onDetect(detected: { rawValue: string }[]) {
    if (detected.length > 0) {
        form.qr_token = detected[0].rawValue;
        submit();
    }
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-md px-4 py-8">
        <h1 class="text-2xl font-bold">{{ labels.title }} — {{ event.name }}</h1>

        <div class="mt-6 aspect-square overflow-hidden rounded-lg border border-border">
            <QrcodeStream @detect="onDetect" />
        </div>

        <form class="mt-6 space-y-3" @submit.prevent="submit">
            <label class="text-sm text-muted-foreground" for="token">{{ labels.manual }}</label>
            <Input id="token" v-model="form.qr_token" type="text" />
            <Button type="submit" :disabled="form.processing">{{ labels.submit }}</Button>
        </form>
    </main>
</template>
```

- [ ] **Step 8: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Feature/Registration/CheckInTest.php`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat(registration): server-validated qr check-in action, orga scan page and endpoint"
```

---

### Task 6: Seating — Models + ClaimSeat/ReleaseSeat (Roadmap 2.6)

> **Controller-Entscheidung (2026-07-14, ersetzt Open Question 5):** Seat-Release bei Storno ist in M2 PFLICHT, nicht Backlog. Dieser Task enthält zusätzlich: `RegistrationCancelled`-Event (dispatcht aus `CancelRegistration` im Registration-Modul, trägt die `EventRegistration`), ein Listener im Seating-Modul (`ReleaseSeatOnCancellation`), registriert im AppServiceProvider, der eine etwaige SeatAssignment der Registration löst. Test: User mit Sitz storniert → Sitz frei; User ohne Sitz storniert → kein Fehler. Das ist die sanktionierte modulübergreifende Kommunikation (Laravel-Events), keine Grenzverletzung.

**Files:**
- Create: `database/migrations/<ts>_create_seats_table.php`, `database/migrations/<ts>_create_seat_assignments_table.php`, `app/Modules/Seating/Models/Seat.php`, `app/Modules/Seating/Models/SeatAssignment.php`, `app/Modules/Seating/Actions/ClaimSeat.php`, `app/Modules/Seating/Actions/ReleaseSeat.php`, `app/Modules/Seating/Exceptions/SeatException.php`, `app/Modules/Seating/Policies/SeatAssignmentPolicy.php`, `database/factories/SeatFactory.php`, `database/factories/SeatAssignmentFactory.php`
- Modify: `app/Providers/AppServiceProvider.php`, `lang/de/seating.php`
- Test: `tests/Feature/Seating/ClaimSeatTest.php`, `tests/Unit/Seating/SeatModelTest.php`

**Interfaces:**
- Produces:
  - `Seat` (`event_id, label, pos_x, pos_y, meta jsonb`), Relation `assignment()` (hasOne `SeatAssignment`).
  - `SeatAssignment` (`seat_id unique, registration_id unique`) — je Seat höchstens eine Zuweisung, je Registration höchstens ein Seat (beide DB-unique).
  - `ClaimSeat::handle(Seat $seat, EventRegistration $registration): SeatAssignment` — prüft: Seat gehört zum selben Event wie die Registration; Registration nicht cancelled. Wechselt einen bestehenden Platz des Users atomar (löscht alte Assignment, legt neue an). Die DB-UNIQUE(`seat_id`) fängt die Race (zwei User, ein Platz) → zweiter Claim wirft `QueryException`, von der Action in `SeatException::taken()` übersetzt.
  - `ReleaseSeat::handle(EventRegistration $registration): void` — löscht die Assignment der Registration (falls vorhanden).
  - `SeatAssignmentPolicy::claim(User, EventRegistration)` (nur eigene Registration ODER orga).

- [ ] **Step 1: Migrationen**

`create_seats_table`:

```php
Schema::create('seats', function (Blueprint $table) {
    $table->id();
    $table->foreignId('event_id')->constrained()->cascadeOnDelete();
    $table->string('label');
    $table->integer('pos_x');
    $table->integer('pos_y');
    $table->jsonb('meta')->default('{}');
    $table->timestamps();

    $table->unique(['event_id', 'label']);
});
```

`create_seat_assignments_table`:

```php
Schema::create('seat_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('seat_id')->unique()->constrained()->cascadeOnDelete();
    $table->foreignId('registration_id')->unique()
        ->constrained('event_registrations')->cascadeOnDelete();
    $table->timestamps();
});
```

- [ ] **Step 2: Models + Factories**

Create `app/Modules/Seating/Models/Seat.php`:

```php
<?php

namespace App\Modules\Seating\Models;

use App\Modules\Events\Models\Event;
use Database\Factories\SeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array<string, mixed> $meta
 */
class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory;

    protected $fillable = ['event_id', 'label', 'pos_x', 'pos_y', 'meta'];

    protected function casts(): array
    {
        return [
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasOne<SeatAssignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(SeatAssignment::class);
    }

    protected static function newFactory(): SeatFactory
    {
        return SeatFactory::new();
    }
}
```

Create `app/Modules/Seating/Models/SeatAssignment.php`:

```php
<?php

namespace App\Modules\Seating\Models;

use App\Modules\Registration\Models\EventRegistration;
use Database\Factories\SeatAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatAssignment extends Model
{
    /** @use HasFactory<SeatAssignmentFactory> */
    use HasFactory;

    protected $fillable = ['seat_id', 'registration_id'];

    /** @return BelongsTo<Seat, $this> */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    protected static function newFactory(): SeatAssignmentFactory
    {
        return SeatAssignmentFactory::new();
    }
}
```

Create `database/factories/SeatFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seat> */
class SeatFactory extends Factory
{
    protected $model = Seat::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'label' => strtoupper(fake()->unique()->bothify('?#')),
            'pos_x' => fake()->numberBetween(0, 10),
            'pos_y' => fake()->numberBetween(0, 10),
            'meta' => [],
        ];
    }
}
```

Create `database/factories/SeatAssignmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeatAssignment> */
class SeatAssignmentFactory extends Factory
{
    protected $model = SeatAssignment::class;

    public function definition(): array
    {
        return [
            'seat_id' => Seat::factory(),
            'registration_id' => EventRegistration::factory(),
        ];
    }
}
```

- [ ] **Step 3: Failing Tests — inkl. Race (2 User, 1 Platz)**

Create `tests/Feature/Seating/ClaimSeatTest.php`:

```php
<?php

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Actions\ReleaseSeat;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;

it('claims a free seat for a registration', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $assignment = app(ClaimSeat::class)->handle($seat, $reg);

    expect($assignment->seat_id)->toBe($seat->id)
        ->and($assignment->registration_id)->toBe($reg->id);
});

it('rejects claiming a seat from another event', function () {
    $reg = EventRegistration::factory()->for(Event::factory()->live())->create();
    $seat = Seat::factory()->for(Event::factory()->live())->create();

    expect(fn () => app(ClaimSeat::class)->handle($seat, $reg))
        ->toThrow(SeatException::class);
});

it('lets a user switch seats (releases the old one)', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();
    $seatA = Seat::factory()->for($event)->create();
    $seatB = Seat::factory()->for($event)->create();

    app(ClaimSeat::class)->handle($seatA, $reg);
    app(ClaimSeat::class)->handle($seatB, $reg);

    expect(SeatAssignment::where('registration_id', $reg->id)->count())->toBe(1)
        ->and(SeatAssignment::where('seat_id', $seatB->id)->exists())->toBeTrue()
        ->and(SeatAssignment::where('seat_id', $seatA->id)->exists())->toBeFalse();
});

it('rejects a second registration claiming the same seat (db unique race)', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $regA = EventRegistration::factory()->for($event)->create();
    $regB = EventRegistration::factory()->for($event)->create();

    app(ClaimSeat::class)->handle($seat, $regA);

    expect(fn () => app(ClaimSeat::class)->handle($seat, $regB))
        ->toThrow(SeatException::class);

    expect(SeatAssignment::where('seat_id', $seat->id)->first()->registration_id)->toBe($regA->id);
});

it('releases a seat', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $reg = EventRegistration::factory()->for($event)->create();
    app(ClaimSeat::class)->handle($seat, $reg);

    app(ReleaseSeat::class)->handle($reg);

    expect(SeatAssignment::where('registration_id', $reg->id)->exists())->toBeFalse();
});
```

Create `tests/Unit/Seating/SeatModelTest.php`:

```php
<?php

use App\Modules\Seating\Models\Seat;

it('casts pos and meta', function () {
    $seat = Seat::factory()->create(['pos_x' => 3, 'pos_y' => 4, 'meta' => ['switch_port' => 12]]);

    expect($seat->fresh())
        ->pos_x->toBe(3)
        ->pos_y->toBe(4)
        ->meta->toBe(['switch_port' => 12]);
});
```

- [ ] **Step 4: Rot laufen lassen**

Run: `php artisan migrate && ./vendor/bin/pest tests/Feature/Seating tests/Unit/Seating`
Expected: FAIL — Actions/Exception fehlen.

- [ ] **Step 5: Exception + Actions**

Create `app/Modules/Seating/Exceptions/SeatException.php`:

```php
<?php

namespace App\Modules\Seating\Exceptions;

use DomainException;

class SeatException extends DomainException
{
    public static function wrongEvent(): self
    {
        return new self('The seat does not belong to the registration event.');
    }

    public static function taken(): self
    {
        return new self('This seat is already taken.');
    }
}
```

Create `app/Modules/Seating/Actions/ClaimSeat.php`:

```php
<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ClaimSeat
{
    public function handle(Seat $seat, EventRegistration $registration): SeatAssignment
    {
        if ($seat->event_id !== $registration->event_id) {
            throw SeatException::wrongEvent();
        }

        try {
            return DB::transaction(function () use ($seat, $registration): SeatAssignment {
                // Switching seats: drop the registration's current assignment first.
                SeatAssignment::query()
                    ->where('registration_id', $registration->id)
                    ->delete();

                return SeatAssignment::create([
                    'seat_id' => $seat->id,
                    'registration_id' => $registration->id,
                ]);
            });
        } catch (QueryException $e) {
            // seat_id UNIQUE violation -> two users raced for one seat.
            throw SeatException::taken();
        }
    }
}
```

Create `app/Modules/Seating/Actions/ReleaseSeat.php`:

```php
<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\SeatAssignment;

class ReleaseSeat
{
    public function handle(EventRegistration $registration): void
    {
        SeatAssignment::query()
            ->where('registration_id', $registration->id)
            ->delete();
    }
}
```

> **Race-Nachweis:** Die UNIQUE(`seat_id`) auf `seat_assignments` ist die Wahrheitsquelle gegen den „zwei User, ein Platz"-Fall — kein `SELECT … then INSERT`-Check, der ein TOCTOU-Fenster hätte. Der zweite Claim schlägt beim INSERT mit `QueryException` fehl, die die Action in `SeatException::taken()` übersetzt.

- [ ] **Step 6: Policy + Registrierung + i18n**

Create `app/Modules/Seating/Policies/SeatAssignmentPolicy.php`:

```php
<?php

namespace App\Modules\Seating\Policies;

use App\Models\User;
use App\Modules\Registration\Models\EventRegistration;

class SeatAssignmentPolicy
{
    public function claim(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga() || $registration->user_id === $user->id;
    }
}
```

> Registrierung: da hier kein Model-gebundenes `Gate::policy` (die Policy hängt an einer Aktion über eine Registration) — als Gate-Ability in `AppServiceProvider::boot()` definieren: `Gate::define('claim-seat', [SeatAssignmentPolicy::class, 'claim']);` **oder** in Task 8 direkt im Controller mit `$user->id === $registration->user_id`-Check + `isOrga()`. Entscheidung: Gate-Ability, damit die Autorisierung testbar und wiederverwendbar bleibt.

Create `lang/de/seating.php` (Basis; wird in Task 7/8 ergänzt):

```php
<?php

return [
    'seat' => [
        'label' => 'Platz',
    ],
];
```

- [ ] **Step 7: Grün + Gate**

Run: `./vendor/bin/pest tests/Feature/Seating tests/Unit/Seating`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(seating): Seat/SeatAssignment models, ClaimSeat/ReleaseSeat with db-unique race guard"
```

---

### Task 7: Filament Seat-Editor — Bulk-Raster + Einzel-Edit (Roadmap 2.7)

**Files:**
- Create: `app/Modules/Seating/Filament/Resources/Seats/SeatResource.php` (+ `Schemas/SeatForm`, `Tables/SeatsTable`, `Pages/*`), `app/Modules/Seating/Actions/GenerateSeatGrid.php`, `app/Modules/Seating/Policies/SeatPolicy.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (Resource-Discovery Seating), `app/Providers/AppServiceProvider.php` (`Gate::policy(Seat…)`), `lang/de/seating.php`
- Test: `tests/Feature/Seating/SeatResourceTest.php`, `tests/Unit/Seating/GenerateSeatGridTest.php`

**Interfaces:**
- Produces:
  - `GenerateSeatGrid::handle(Event $event, int $rows, int $cols, string $labelPrefix): int` — legt `rows × cols` Seats mit Labels `"{prefix}{row}-{col}"` und `pos_x=col, pos_y=row` an; überspringt bereits existierende Labels (idempotent); gibt Anzahl neu erstellter Seats zurück.
  - Filament `SeatResource` unter `/admin/seats` (gefiltert nach Event über eine Select-Spalte/Filter), Einzel-Edit (Label, `pos_x`, `pos_y`, `meta.switch_port`, `meta.ip`), Header-Action „Raster anlegen" (Modal: Event, Reihen, Spalten, Prefix → `GenerateSeatGrid`).

> **Design-Hinweis:** Die Roadmap nennt „`/admin/events/{id}` Tab ‚Seats'". Ein eigenständiger `SeatResource` mit Event-Filter ist die pragmatischere Filament-v5-Umsetzung (RelationManager mit Raster-Bulk-Anlage wäre möglich, aber die Bulk-Modal-Action lebt sauberer an einer eigenen Resource). Entscheidung: eigenständige Resource; die Roadmap wird beim Phasenabschluss entsprechend nachgezogen.

> **Verify first (2026):** `make:filament-resource` in v5.6.8 erzeugt die `Resources/<Name>/`-Struktur mit `Schemas/`/`Tables/`-Split (wie EventResource). Nach Generierung nach `app/Modules/Seating/Filament/Resources/` verschieben + Namespaces korrigieren. Modal-Form-Action-API (`->schema([...])` / `->form([...])`, `->action()`) gegen laravel-boost bestätigen.

- [ ] **Step 1: GenerateSeatGrid-Action + Test (TDD)**

Create `tests/Unit/Seating/GenerateSeatGridTest.php`:

```php
<?php

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Seating\Models\Seat;

it('generates a rows x cols grid of seats', function () {
    $event = Event::factory()->create();

    $created = app(GenerateSeatGrid::class)->handle($event, 2, 3, 'A');

    expect($created)->toBe(6)
        ->and(Seat::where('event_id', $event->id)->count())->toBe(6)
        ->and(Seat::where('event_id', $event->id)->where('label', 'A1-1')->exists())->toBeTrue();
});

it('is idempotent and skips existing labels', function () {
    $event = Event::factory()->create();
    app(GenerateSeatGrid::class)->handle($event, 2, 2, 'A');

    $created = app(GenerateSeatGrid::class)->handle($event, 2, 2, 'A');

    expect($created)->toBe(0)
        ->and(Seat::where('event_id', $event->id)->count())->toBe(4);
});
```

Create `app/Modules/Seating/Actions/GenerateSeatGrid.php`:

```php
<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;

class GenerateSeatGrid
{
    public function handle(Event $event, int $rows, int $cols, string $labelPrefix): int
    {
        $existing = Seat::query()
            ->where('event_id', $event->id)
            ->pluck('label')
            ->flip();

        $created = 0;

        for ($row = 1; $row <= $rows; $row++) {
            for ($col = 1; $col <= $cols; $col++) {
                $label = "{$labelPrefix}{$row}-{$col}";
                if ($existing->has($label)) {
                    continue;
                }
                Seat::create([
                    'event_id' => $event->id,
                    'label' => $label,
                    'pos_x' => $col,
                    'pos_y' => $row,
                    'meta' => [],
                ]);
                $created++;
            }
        }

        return $created;
    }
}
```

Run: `./vendor/bin/pest tests/Unit/Seating/GenerateSeatGridTest.php`
Expected: PASS (rot→grün innerhalb dieses Steps: erst Test, dann Action; hier zusammengefasst).

- [ ] **Step 2: Policy + Failing Feature-Test**

Create `app/Modules/Seating/Policies/SeatPolicy.php` (orga-only, analog `EventPolicy`), registriere `Gate::policy(Seat::class, SeatPolicy::class)`.

Create `tests/Feature/Seating/SeatResourceTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;

it('lists seats for orga', function () {
    $event = Event::factory()->create();
    Seat::factory()->for($event)->create(['label' => 'A1-1']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/seats')
        ->assertOk()
        ->assertSee('A1-1');
});

it('forbids participants from the seats resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/seats')
        ->assertForbidden();
});

it('shows the german grid-generation label', function () {
    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/seats')
        ->assertOk()
        ->assertSee('Raster anlegen'); // i18n gate
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Seating/SeatResourceTest.php`
Expected: FAIL — Resource/Route fehlen.

- [ ] **Step 4: Resource generieren + anpassen + Discovery**

```bash
php artisan make:filament-resource Seat --generate
```

Dateien nach `app/Modules/Seating/Filament/Resources/Seats/` verschieben, Namespaces auf `App\Modules\Seating\Filament\Resources\Seats` setzen. Im `AdminPanelProvider` einen weiteren `discoverResources`-Aufruf ergänzen:

```php
->discoverResources(
    in: app_path('Modules/Seating/Filament/Resources'),
    for: 'App\\Modules\\Seating\\Filament\\Resources',
)
```

Form (`Schemas/SeatForm`): `TextInput label`, `event_id`-Select (Relationship `event`), `pos_x`/`pos_y` numeric, `meta.switch_port` (`TextInput::make('meta.switch_port')`), `meta.ip`. Table (`Tables/SeatsTable`): Spalten `event.name` (searchable), `label` (searchable), `pos_x`, `pos_y`, `assignment.registration.user.name` (belegt durch) — Nullwert-tolerant. Event-Filter via `SelectFilter::make('event_id')->relationship('event','name')`.

Header-Action „Raster anlegen" auf der ListPage (`Pages/ListSeats.php::getHeaderActions()`):

```php
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Events\Models\Event;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

protected function getHeaderActions(): array
{
    return [
        Action::make('generate_grid')
            ->label(__('seating.grid.action'))
            ->schema([
                Select::make('event_id')
                    ->label(__('seating.grid.event'))
                    ->options(fn () => Event::query()->pluck('name', 'id'))
                    ->required(),
                TextInput::make('rows')->label(__('seating.grid.rows'))->numeric()->required()->minValue(1),
                TextInput::make('cols')->label(__('seating.grid.cols'))->numeric()->required()->minValue(1),
                TextInput::make('prefix')->label(__('seating.grid.prefix'))->default('A')->required(),
            ])
            ->action(function (array $data): void {
                $event = Event::findOrFail($data['event_id']);
                $count = app(GenerateSeatGrid::class)->handle(
                    $event, (int) $data['rows'], (int) $data['cols'], $data['prefix'],
                );
                Notification::make()->title(__('seating.grid.done', ['count' => $count]))->success()->send();
            }),
    ];
}
```

- [ ] **Step 5: Übersetzungen**

`lang/de/seating.php` ergänzen:

```php
    'resource' => [
        'label' => 'Sitzplatz',
        'plural_label' => 'Sitzplätze',
    ],
    'fields' => [
        'event' => 'Event',
        'label' => 'Platz',
        'pos_x' => 'Spalte',
        'pos_y' => 'Reihe',
        'switch_port' => 'Switch-Port',
        'ip' => 'IP-Adresse',
        'occupied_by' => 'Belegt durch',
    ],
    'grid' => [
        'action' => 'Raster anlegen',
        'event' => 'Event',
        'rows' => 'Reihen',
        'cols' => 'Spalten',
        'prefix' => 'Label-Präfix',
        'done' => ':count Plätze angelegt.',
    ],
```

(`SeatResource::getModelLabel()`/`getPluralModelLabel()` auf `seating.resource.*` zeigen lassen.)

- [ ] **Step 6: Grün + Gate**

Run: `./vendor/bin/pest tests/Feature/Seating tests/Unit/Seating`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(seating): filament seat editor with grid bulk generation and per-seat network meta"
```

---

### Task 8: Teilnehmer-Sitzplan `Event/Seating.vue` (Roadmap 2.8)

**Files:**
- Create: `app/Modules/Seating/Http/SeatingController.php`, `resources/js/pages/Event/Seating.vue`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php` (`Gate::define('claim-seat', …)` falls nicht in Task 6 erfolgt), `lang/de/seating.php`, `resources/js/types/index.d.ts`
- Test: `tests/Feature/Seating/SeatingPageTest.php`

**Interfaces:**
- Produces:
  - `GET /events/{event:slug}/seating` (name `events.seating`) → `Event/Seating` — SVG-Raster aller Seats aus `pos_x/pos_y`, belegte Plätze mit Nickname des belegenden Users, eigener Platz markiert. Auth-frei lesbar (öffentliche „Wer sitzt wo"-Ansicht), aber Platzwahl nur eingeloggt + mit eigener Registration.
  - `POST /events/{event:slug}/seating/{seat}` (name `events.seating.claim`) → `ClaimSeat` (Gate `claim-seat`), Redirect.
  - `DELETE /events/{event:slug}/seating` (name `events.seating.release`) → `ReleaseSeat`, Redirect.

- [ ] **Step 1: Failing Feature-Test**

Create `tests/Feature/Seating/SeatingPageTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Inertia\Testing\AssertableInertia;

it('renders the seating map with seats and occupants', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1-1']);
    $occupant = User::factory()->create(['name' => 'Sitter']);
    $reg = EventRegistration::factory()->for($event)->for($occupant)->create();
    SeatAssignment::factory()->create(['seat_id' => $seat->id, 'registration_id' => $reg->id]);

    $this->get("/events/{$event->slug}/seating")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Seating')
            ->where('seats.0.label', 'A1-1')
            ->where('seats.0.occupant', 'Sitter')
            ->where('labels.title', 'Sitzplan')
        );
});

it('lets a registered user claim a seat', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/seating/{$seat->id}")
        ->assertRedirect();

    expect(SeatAssignment::where('seat_id', $seat->id)->exists())->toBeTrue();
});

it('forbids claiming without a registration', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->post("/events/{$event->slug}/seating/{$seat->id}")
        ->assertForbidden();
});
```

- [ ] **Step 2: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Seating/SeatingPageTest.php`
Expected: FAIL — Route/Controller fehlen.

- [ ] **Step 3: Controller**

Create `app/Modules/Seating/Http/SeatingController.php`:

```php
<?php

namespace App\Modules\Seating\Http;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Actions\ReleaseSeat;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Inertia\Inertia;
use Inertia\Response;

class SeatingController
{
    public function index(Request $request, Event $event): Response
    {
        $seats = Seat::query()
            ->where('event_id', $event->id)
            ->with('assignment.registration.user')
            ->orderBy('pos_y')->orderBy('pos_x')
            ->get()
            ->map(fn (Seat $seat) => [
                'id' => $seat->id,
                'label' => $seat->label,
                'x' => $seat->pos_x,
                'y' => $seat->pos_y,
                'occupant' => $seat->assignment?->registration?->user?->name,
            ])
            ->all();

        $mySeatId = null;
        if ($request->user() !== null) {
            $reg = $this->activeRegistration($event, $request->user()->id);
            $mySeatId = $reg?->id === null ? null
                : Seat::query()->whereHas('assignment', fn ($q) => $q->where('registration_id', $reg->id))->value('id');
        }

        return Inertia::render('Event/Seating', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'seats' => $seats,
            'mySeatId' => $mySeatId,
            'canClaim' => $request->user() !== null
                && $this->activeRegistration($event, $request->user()->id) !== null,
            'labels' => trans('seating.page'),
        ]);
    }

    public function claim(Request $request, Event $event, Seat $seat, ClaimSeat $action): RedirectResponse
    {
        $reg = $this->requireRegistration($request, $event);

        if (! $request->user()->can('claim-seat', $reg)) {
            throw new AccessDeniedHttpException;
        }

        try {
            $action->handle($seat, $reg);
        } catch (SeatException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function release(Request $request, Event $event, ReleaseSeat $action): RedirectResponse
    {
        $reg = $this->requireRegistration($request, $event);
        $action->handle($reg);

        return back();
    }

    private function requireRegistration(Request $request, Event $event): EventRegistration
    {
        $reg = $request->user() === null ? null : $this->activeRegistration($event, $request->user()->id);

        if ($reg === null) {
            throw new AccessDeniedHttpException;
        }

        return $reg;
    }

    private function activeRegistration(Event $event, int $userId): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->first();
    }
}
```

- [ ] **Step 4: Routen**

```php
use App\Modules\Seating\Http\SeatingController;

Route::get('/events/{event:slug}/seating', [SeatingController::class, 'index'])->name('events.seating');

Route::middleware(['auth'])->group(function () {
    Route::post('/events/{event:slug}/seating/{seat}', [SeatingController::class, 'claim'])->name('events.seating.claim');
    Route::delete('/events/{event:slug}/seating', [SeatingController::class, 'release'])->name('events.seating.release');
});
```

> `index` ist öffentlich (auth-frei); `claim`/`release` auth-pflichtig. Wayfinder danach neu generieren.

- [ ] **Step 5: Übersetzungen**

`lang/de/seating.php` um `page` ergänzen:

```php
    'page' => [
        'title' => 'Sitzplan',
        'free' => 'Frei',
        'my_seat' => 'Mein Platz',
        'claim' => 'Platz wählen',
        'release' => 'Platz freigeben',
        'need_registration' => 'Melde dich zuerst zum Event an, um einen Platz zu wählen.',
    ],
```

- [ ] **Step 6: Vue-Seite `Event/Seating.vue`**

Create `resources/js/pages/Event/Seating.vue` — SVG-Raster; jeder Seat ein `<g>` an `x*cell`/`y*cell`, klickbar wenn `canClaim`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';

interface SeatDto {
    id: number;
    label: string;
    x: number;
    y: number;
    occupant: string | null;
}

const props = defineProps<{
    event: { name: string; slug: string };
    seats: SeatDto[];
    mySeatId: number | null;
    canClaim: boolean;
    labels: Record<string, string>;
}>();

const CELL = 64;
const maxX = computed(() => Math.max(1, ...props.seats.map((s) => s.x)) + 1);
const maxY = computed(() => Math.max(1, ...props.seats.map((s) => s.y)) + 1);

function claim(seat: SeatDto) {
    if (!props.canClaim || seat.occupant) return;
    router.post(route('events.seating.claim', { event: props.event.slug, seat: seat.id }), {}, { preserveScroll: true });
}

function release() {
    router.delete(route('events.seating.release', { event: props.event.slug }), { preserveScroll: true });
}

function fill(seat: SeatDto): string {
    if (seat.id === props.mySeatId) return 'var(--primary)';
    return seat.occupant ? 'var(--muted)' : 'var(--card)';
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-5xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">{{ labels.title }} — {{ event.name }}</h1>

        <p v-if="!canClaim" class="mt-4 text-sm text-muted-foreground">{{ labels.need_registration }}</p>
        <button v-else-if="mySeatId" class="mt-4 text-sm underline" @click="release">{{ labels.release }}</button>

        <div class="mt-8 overflow-auto rounded-lg border border-border p-4">
            <svg :viewBox="`0 0 ${maxX * CELL} ${maxY * CELL}`" class="w-full">
                <g
                    v-for="seat in seats"
                    :key="seat.id"
                    :transform="`translate(${seat.x * CELL}, ${seat.y * CELL})`"
                    :class="canClaim && !seat.occupant ? 'cursor-pointer' : ''"
                    @click="claim(seat)"
                >
                    <rect
                        :width="CELL - 8"
                        :height="CELL - 8"
                        rx="6"
                        :fill="fill(seat)"
                        stroke="var(--border)"
                    />
                    <text x="8" y="20" class="text-[10px]" fill="var(--foreground)">{{ seat.label }}</text>
                    <text v-if="seat.occupant" x="8" y="40" class="text-[9px]" fill="var(--muted-foreground)">
                        {{ seat.occupant }}
                    </text>
                </g>
            </svg>
        </div>
    </main>
</template>
```

> Team-Badges an belegten Plätzen kommen erst ab M3 (Teams existieren noch nicht) — bewusst kein Vorgriff (YAGNI). Der `occupant`-Name genügt für M2.

- [ ] **Step 7: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Feature/Seating/SeatingPageTest.php`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(seating): participant seat map with svg grid, claim/switch/release"
```

---

### Task 9: Notifications-Grundgerüst — database-Channel + Glocke + Präferenzen (Roadmap 2.9)

**Files:**
- Create: migration `create_notifications_table` (Laravel-Standard, falls noch nicht vorhanden), migration `add_notification_prefs_to_users_table`, `app/Modules/Notifications/Notifications/RegistrationConfirmed.php`, `app/Modules/Notifications/Support/NotificationPreferences.php`, `app/Modules/Notifications/Http/NotificationController.php`, `resources/js/components/NotificationBell.vue`
- Modify: `app/Models/User.php` (`notification_prefs`-Cast, **nicht** fillable-Privileg — reguläres user-owned Feld, darf fillable sein), `app/Http/Middleware/HandleInertiaRequests.php` (shared prop `unreadNotifications`), Layout-Komponente (Glocke einhängen), `routes/web.php`, `lang/de/notifications.php`
- Test: `tests/Feature/Notifications/DatabaseChannelTest.php`

**Interfaces:**
- Produces:
  - `notifications`-Tabelle (Laravel-Standard: `php artisan make:notifications-table` bzw. `notifications:table` — aktuellen Befehl über laravel-boost prüfen).
  - `users.notification_prefs jsonb` (Kategorie→bool-Map, Default `{}` = alles an).
  - `NotificationPreferences::wants(User $user, string $category): bool` — default true, wenn Kategorie nicht deaktiviert.
  - Beispiel-Notification `RegistrationConfirmed` (via `database`-Channel), zugestellt beim erfolgreichen `RegisterForEvent` (Listener oder direkt in der Action-Konsumentenschicht — hier: der Controller `store()` verschickt sie nach erfolgreicher Action).
  - Inertia-Shared-Prop `unreadNotifications` (Liste `{id, title, body, createdAt}`); Glocken-Dropdown im Layout; `POST /notifications/{id}/read` (name `notifications.read`).

> **Verify first (2026):** Laravel-Notifications `database`-Channel-Setup (`notifications`-Migration-Befehl, `Notifiable`-Trait — bereits am User), Doku `laravel.com/docs/13.x/notifications#database-notifications`. Über laravel-boost bestätigen, ob die `notifications`-Tabelle vom Starter-Kit schon existiert (dann Migration überspringen).

- [ ] **Step 1: Migrationen**

- `notifications`-Tabelle (falls fehlt): Standardbefehl ausführen.
- `add_notification_prefs_to_users_table`:

```php
Schema::table('users', function (Blueprint $table) {
    $table->jsonb('notification_prefs')->default('{}');
});
```

`app/Models/User.php`: `notification_prefs` in `casts()` → `'array'`; in `#[Fillable([...])]` ergänzen (user-owned, unkritisch — **nicht** `role`).

- [ ] **Step 2: Failing Test**

Create `tests/Feature/Notifications/DatabaseChannelTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Notifications\Notifications\RegistrationConfirmed;
use Illuminate\Support\Facades\Notification;

it('sends a database notification on successful registration', function () {
    Notification::fake();
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/register", ['ticket_type' => 'standard']);

    Notification::assertSentTo($user, RegistrationConfirmed::class);
});

it('stores the notification in the database and exposes it as unread', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();
    $user->notify(new RegistrationConfirmed($event->name));

    expect($user->unreadNotifications()->count())->toBe(1);
});

it('respects a disabled category preference', function () {
    $user = User::factory()->create(['notification_prefs' => ['registration' => false]]);

    expect(app(\App\Modules\Notifications\Support\NotificationPreferences::class)
        ->wants($user, 'registration'))->toBeFalse();
});
```

- [ ] **Step 3: Rot laufen lassen**

Run: `./vendor/bin/pest tests/Feature/Notifications/DatabaseChannelTest.php`
Expected: FAIL — Notification/Preferences fehlen.

- [ ] **Step 4: Notification + Preferences**

Create `app/Modules/Notifications/Notifications/RegistrationConfirmed.php`:

```php
<?php

namespace App\Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RegistrationConfirmed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $eventName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'registration',
            'title' => __('notifications.registration_confirmed.title'),
            'body' => __('notifications.registration_confirmed.body', ['event' => $this->eventName]),
        ];
    }
}
```

Create `app/Modules/Notifications/Support/NotificationPreferences.php`:

```php
<?php

namespace App\Modules\Notifications\Support;

use App\Models\User;

class NotificationPreferences
{
    public function wants(User $user, string $category): bool
    {
        $prefs = $user->notification_prefs ?? [];

        return ($prefs[$category] ?? true) === true;
    }
}
```

- [ ] **Step 5: Notification im Registrierungs-Store verschicken**

Modify `app/Modules/Registration/Http/RegistrationController.php::store()` — nach erfolgreicher Action:

```php
$request->user()->notify(new \App\Modules\Notifications\Notifications\RegistrationConfirmed($event->name));
```

> Alternativ sauberer: ein `RegisteredForEvent`-Domain-Event + Listener im Notifications-Modul (entkoppelt). Für M2 genügt der direkte `notify()`-Aufruf im Controller (der Discord-Announcement-Flow in Task 11 nutzt dagegen bewusst Events/Outbox). Entscheidung im Commit vermerken.

- [ ] **Step 6: Shared-Prop + Read-Endpoint + Glocke**

`HandleInertiaRequests::share()` ergänzen:

```php
'unreadNotifications' => fn () => $request->user()?->unreadNotifications
    ->map(fn ($n) => [
        'id' => $n->id,
        'title' => $n->data['title'] ?? '',
        'body' => $n->data['body'] ?? '',
        'createdAt' => $n->created_at?->toIso8601String(),
    ])->values()->all() ?? [],
```

`NotificationController` + Route `POST /notifications/{notification}/read` (auth): markiert die Notification des `$request->user()` als gelesen (`->markAsRead()`; 404, wenn sie einem anderen User gehört — nie Client-User-ID vertrauen).

`resources/js/components/NotificationBell.vue`: Dropdown, liest `unreadNotifications` aus den Page-Props, „gelesen"-Button `router.post(route('notifications.read', {notification: id}))`. In die App-Layout-Header-Leiste einhängen (nur für eingeloggte User). Copy aus `notifications.bell`-Labels als Prop.

- [ ] **Step 7: Übersetzungen**

Create `lang/de/notifications.php`:

```php
<?php

return [
    'bell' => [
        'title' => 'Benachrichtigungen',
        'empty' => 'Keine neuen Benachrichtigungen.',
        'mark_read' => 'Als gelesen markieren',
    ],
    'registration_confirmed' => [
        'title' => 'Anmeldung bestätigt',
        'body' => 'Deine Anmeldung für :event ist bestätigt.',
    ],
];
```

- [ ] **Step 8: Grün + Gate + Frontend**

Run: `./vendor/bin/pest tests/Feature/Notifications`
Expected: PASS

Run: `composer check && npm run lint && npm run build`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat(notifications): database channel, bell dropdown, category preferences"
```

---

### Task 10: Discord-Basis — DiscordClient-Contract + Http/Fake + config (Roadmap 2.10)

**Files:**
- Create: `app/Modules/Discord/Contracts/DiscordClient.php`, `app/Modules/Discord/HttpDiscordClient.php`, `app/Modules/Discord/Testing/FakeDiscordClient.php`, `app/Modules/Discord/DiscordServiceProvider.php` (oder Binding im `AppServiceProvider`)
- Modify: `config/services.php` (`discord`-Block), `.env.example`, `bootstrap/providers.php` (falls eigener Provider)
- Test: `tests/Unit/Discord/FakeDiscordClientTest.php`, `tests/Feature/Discord/HttpDiscordClientTest.php`

**Interfaces:**
- Produces: `App\Modules\Discord\Contracts\DiscordClient` mit
  - `sendMessage(string $channelId, string $content, array $embeds = []): void`
  - `createChannel(string $guildId, string $name, ?string $parentId = null): string` (gibt Channel-ID zurück)
  - `deleteChannel(string $channelId): void`
  - `sendDm(string $userDiscordId, string $content): void`
  - `upsertPermissionOverwrites(string $channelId, array $overwrites): void`
  - `HttpDiscordClient` (Bot-Token via `config('services.discord.bot_token')`, Laravel `Http`-Client gegen `https://discord.com/api/v10`), `FakeDiscordClient` (In-Memory-Recording + Assertion-Helfer `assertMessageSent`, `assertDmSent`, `assertChannelCreated`).
- Binding: `DiscordClient` → `HttpDiscordClient` (App), in Tests via `app()->instance(DiscordClient::class, new FakeDiscordClient)`.

> **Verify first (2026):** Discord-REST-API-Version + Auth-Header (`Authorization: Bot <token>`), Endpunkte `POST /channels/{id}/messages`, `POST /guilds/{id}/channels`, `DELETE /channels/{id}`, `POST /users/@me/channels` (DM-Channel öffnen, dann Message) — Doku `discord.com/developers/docs`. Laravel-`Http`-Client (`Http::withToken`/`withHeaders`, `->throw()`) über laravel-boost. **Nur Contract + Fake sind testrelevant; der HttpDiscordClient wird gegen `Http::fake()` getestet, nie gegen die echte API** (Global Constraint).

- [ ] **Step 1: Contract**

Create `app/Modules/Discord/Contracts/DiscordClient.php`:

```php
<?php

namespace App\Modules\Discord\Contracts;

interface DiscordClient
{
    /**
     * @param  array<int, array<string, mixed>>  $embeds
     */
    public function sendMessage(string $channelId, string $content, array $embeds = []): void;

    public function createChannel(string $guildId, string $name, ?string $parentId = null): string;

    public function deleteChannel(string $channelId): void;

    public function sendDm(string $userDiscordId, string $content): void;

    /**
     * @param  array<int, array<string, mixed>>  $overwrites
     */
    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void;
}
```

- [ ] **Step 2: Fake + Test (TDD)**

Create `app/Modules/Discord/Testing/FakeDiscordClient.php`:

```php
<?php

namespace App\Modules\Discord\Testing;

use App\Modules\Discord\Contracts\DiscordClient;
use PHPUnit\Framework\Assert;

class FakeDiscordClient implements DiscordClient
{
    /** @var array<int, array{channelId: string, content: string, embeds: array<int, mixed>}> */
    public array $messages = [];

    /** @var array<int, array{userDiscordId: string, content: string}> */
    public array $dms = [];

    /** @var array<int, array{guildId: string, name: string, parentId: ?string, id: string}> */
    public array $channels = [];

    /** @var array<int, array{channelId: string, overwrites: array<int, mixed>}> */
    public array $overwrites = [];

    private int $sequence = 0;

    public function sendMessage(string $channelId, string $content, array $embeds = []): void
    {
        $this->messages[] = compact('channelId', 'content', 'embeds');
    }

    public function createChannel(string $guildId, string $name, ?string $parentId = null): string
    {
        $id = 'fake-channel-'.(++$this->sequence);
        $this->channels[] = compact('guildId', 'name', 'parentId', 'id');

        return $id;
    }

    public function deleteChannel(string $channelId): void
    {
        $this->channels = array_values(array_filter($this->channels, fn ($c) => $c['id'] !== $channelId));
    }

    public function sendDm(string $userDiscordId, string $content): void
    {
        $this->dms[] = compact('userDiscordId', 'content');
    }

    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
    {
        $this->overwrites[] = compact('channelId', 'overwrites');
    }

    public function assertMessageSent(string $channelId, ?string $contains = null): void
    {
        $match = collect($this->messages)->contains(
            fn ($m) => $m['channelId'] === $channelId && ($contains === null || str_contains($m['content'], $contains))
        );
        Assert::assertTrue($match, "No matching message sent to channel {$channelId}.");
    }

    public function assertDmSent(string $userDiscordId): void
    {
        Assert::assertTrue(
            collect($this->dms)->contains(fn ($d) => $d['userDiscordId'] === $userDiscordId),
            "No DM sent to user {$userDiscordId}."
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->messages, 'Expected no Discord messages.');
        Assert::assertSame([], $this->dms, 'Expected no Discord DMs.');
    }
}
```

Create `tests/Unit/Discord/FakeDiscordClientTest.php`:

```php
<?php

use App\Modules\Discord\Testing\FakeDiscordClient;

it('records messages and channels', function () {
    $fake = new FakeDiscordClient;

    $id = $fake->createChannel('guild1', 'match-1');
    $fake->sendMessage($id, 'Hello LAN');

    $fake->assertMessageSent($id, 'Hello');
    expect($fake->channels)->toHaveCount(1);

    $fake->deleteChannel($id);
    expect($fake->channels)->toHaveCount(0);
});

it('records dms', function () {
    $fake = new FakeDiscordClient;
    $fake->sendDm('123', 'ping');

    $fake->assertDmSent('123');
});
```

- [ ] **Step 3: HttpDiscordClient + Http::fake()-Test**

Create `app/Modules/Discord/HttpDiscordClient.php`:

```php
<?php

namespace App\Modules\Discord;

use App\Modules\Discord\Contracts\DiscordClient;
use Illuminate\Support\Facades\Http;

class HttpDiscordClient implements DiscordClient
{
    private string $base = 'https://discord.com/api/v10';

    public function __construct(private readonly string $botToken) {}

    public function sendMessage(string $channelId, string $content, array $embeds = []): void
    {
        $this->http()->post("{$this->base}/channels/{$channelId}/messages", array_filter([
            'content' => $content,
            'embeds' => $embeds ?: null,
        ]))->throw();
    }

    public function createChannel(string $guildId, string $name, ?string $parentId = null): string
    {
        $response = $this->http()->post("{$this->base}/guilds/{$guildId}/channels", array_filter([
            'name' => $name,
            'type' => 0, // text
            'parent_id' => $parentId,
        ]))->throw();

        return (string) $response->json('id');
    }

    public function deleteChannel(string $channelId): void
    {
        $this->http()->delete("{$this->base}/channels/{$channelId}")->throw();
    }

    public function sendDm(string $userDiscordId, string $content): void
    {
        $channel = $this->http()
            ->post("{$this->base}/users/@me/channels", ['recipient_id' => $userDiscordId])
            ->throw()->json('id');

        $this->sendMessage((string) $channel, $content);
    }

    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
    {
        foreach ($overwrites as $overwrite) {
            $this->http()->put(
                "{$this->base}/channels/{$channelId}/permissions/{$overwrite['id']}",
                $overwrite,
            )->throw();
        }
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['Authorization' => "Bot {$this->botToken}"])
            ->acceptJson();
    }
}
```

Create `tests/Feature/Discord/HttpDiscordClientTest.php`:

```php
<?php

use App\Modules\Discord\HttpDiscordClient;
use Illuminate\Support\Facades\Http;

it('posts a message with the bot token', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => '99'], 200)]);

    (new HttpDiscordClient('test-token'))->sendMessage('42', 'hi');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/channels/42/messages')
        && $request->hasHeader('Authorization', 'Bot test-token')
        && $request['content'] === 'hi');
});

it('opens a dm channel then posts', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => 'dm1'], 200)]);

    (new HttpDiscordClient('t'))->sendDm('user1', 'ping');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/users/@me/channels'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/channels/dm1/messages'));
});
```

- [ ] **Step 4: config + Binding**

`config/services.php` ergänzen:

```php
'discord' => [
    'client_id' => env('DISCORD_CLIENT_ID'),
    'client_secret' => env('DISCORD_CLIENT_SECRET'),
    'redirect' => env('DISCORD_REDIRECT_URI'),
    'bot_token' => env('DISCORD_BOT_TOKEN'),
    'guild_id' => env('DISCORD_GUILD_ID'),
    'announce_channel_id' => env('DISCORD_ANNOUNCE_CHANNEL_ID'),
],
```

> Der OAuth-Block (`client_id`/`secret`/`redirect`) existiert ggf. schon aus M0 — nicht duplizieren, nur `bot_token`/`guild_id`/`announce_channel_id` ergänzen.

Binding (in `AppServiceProvider::register()` oder eigenem `DiscordServiceProvider`):

```php
$this->app->bind(DiscordClient::class, fn () => new HttpDiscordClient(
    (string) config('services.discord.bot_token'),
));
```

`.env.example` um `DISCORD_BOT_TOKEN`, `DISCORD_GUILD_ID`, `DISCORD_ANNOUNCE_CHANNEL_ID` ergänzen (Werte leer).

- [ ] **Step 5: Grün + Gate**

Run: `./vendor/bin/pest tests/Unit/Discord tests/Feature/Discord`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(discord): DiscordClient contract, HttpDiscordClient and FakeDiscordClient with config binding"
```

---

### Task 11: Discord-Notification-Channel + Outbox-Dedup + Reminder-Scheduler (Roadmap 2.11)

**Files:**
- Create: migration `create_discord_outbox_table`, `app/Modules/Discord/Models/DiscordOutbox.php`, `app/Modules/Discord/Channels/DiscordChannel.php` (Notification-Channel), `app/Modules/Discord/Notifications/EventAnnouncement.php`, `app/Modules/Discord/Support/DiscordOutboxGuard.php`, `app/Modules/Discord/Console/SendRemindersCommand.php`, `app/Modules/Discord/Listeners/AnnounceRegistrationOpen.php`
- Modify: `app/Providers/AppServiceProvider.php` (Listener auf `EventStatusChanged`), `routes/console.php` bzw. `bootstrap/app.php` (Scheduler-Registrierung `lanomat:send-reminders`), `lang/de/discord.php`
- Test: `tests/Feature/Discord/OutboxDedupTest.php`, `tests/Feature/Discord/SendRemindersTest.php`, `tests/Feature/Discord/AnnounceRegistrationOpenTest.php`

**Interfaces:**
- Produces:
  - `discord_outbox` (`id, kind, dedup_key unique, sent_at, timestamps`) — Dedup-Speicher (ersetzt v1-In-Memory-Dedup).
  - `DiscordOutboxGuard::once(string $dedupKey, string $kind, Closure $send): bool` — legt bei erstem Aufruf einen Outbox-Eintrag an (DB-unique fängt Race) und ruft `$send`; bei bereits vorhandenem `dedup_key` No-op → gibt `false` zurück (nicht erneut gesendet).
  - `DiscordChannel` — Laravel-Notification-Channel `discord`: ruft `Notification::toDiscord($notifiable)` → sendet über `DiscordClient` (Channel-Post oder DM), respektiert `NotificationPreferences`.
  - `AnnounceRegistrationOpen`-Listener auf `EventStatusChanged` (from→registration): postet einmalig „Anmeldung offen" in den Announce-Channel (dedup_key `event-{id}-registration-open`).
  - `lanomat:send-reminders`-Command: findet Events mit `status ∈ {registration, announced, live}` und `starts_at` in ~24 h bzw. ~1 h Fenster, sendet je Reminder genau einmal (dedup_key `event-{id}-reminder-24h` / `-1h`).
- Scheduler: `lanomat:send-reminders` alle 5 Minuten (`bootstrap/app.php`→`withSchedule` bzw. `routes/console.php`; aktuellen Laravel-13-Scheduler-Ort über laravel-boost prüfen — `Schedule::command(...)->everyFiveMinutes()`).

> **Verify first (2026):** Custom-Notification-Channel-Registrierung in Laravel 13 (`Notification::resolved()` / `ChannelManager::extend('discord', ...)` bzw. der `via()`-basierte Custom-Channel per Klassen-String), Doku `laravel.com/docs/13.x/notifications#custom-channels`. Scheduler-Definition-Ort (Laravel 11+: `bootstrap/app.php`→`->withSchedule(...)` **oder** `routes/console.php` mit `Schedule`-Facade) — über laravel-boost bestätigen.

- [ ] **Step 1: Migration + Model + Guard**

`create_discord_outbox_table`:

```php
Schema::create('discord_outbox', function (Blueprint $table) {
    $table->id();
    $table->string('kind');
    $table->string('dedup_key')->unique();
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();
});
```

Create `app/Modules/Discord/Models/DiscordOutbox.php`:

```php
<?php

namespace App\Modules\Discord\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordOutbox extends Model
{
    protected $table = 'discord_outbox';

    protected $fillable = ['kind', 'dedup_key', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
```

Create `app/Modules/Discord/Support/DiscordOutboxGuard.php`:

```php
<?php

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordOutbox;
use Closure;
use Illuminate\Database\QueryException;

class DiscordOutboxGuard
{
    /**
     * Run $send exactly once per dedup_key. Returns true if it fired now.
     */
    public function once(string $dedupKey, string $kind, Closure $send): bool
    {
        try {
            DiscordOutbox::create(['kind' => $kind, 'dedup_key' => $dedupKey]);
        } catch (QueryException $e) {
            // Unique violation: already sent (or racing) -> do not send again.
            return false;
        }

        $send();

        DiscordOutbox::where('dedup_key', $dedupKey)->update(['sent_at' => now()]);

        return true;
    }
}
```

> **Dedup-Nachweis:** Der Outbox-Eintrag wird **vor** dem Senden per INSERT reserviert; die UNIQUE(`dedup_key`) macht den zweiten Versuch (auch nach Prozess-Neustart oder gleichzeitigem Scheduler-Tick) zum No-op. Das ist der explizite Ersatz für die v1-In-Memory-Dedup, die nach Bot-Restart Duplikate produzierte.

- [ ] **Step 2: Failing Test — Outbox-Dedup**

Create `tests/Feature/Discord/OutboxDedupTest.php`:

```php
<?php

use App\Modules\Discord\Models\DiscordOutbox;
use App\Modules\Discord\Support\DiscordOutboxGuard;

it('runs the callback only once per dedup key', function () {
    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    $first = $guard->once('key-1', 'test', function () use (&$calls) { $calls++; });
    $second = $guard->once('key-1', 'test', function () use (&$calls) { $calls++; });

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($calls)->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'key-1')->count())->toBe(1);
});

it('marks sent_at after a successful send', function () {
    app(DiscordOutboxGuard::class)->once('key-2', 'test', fn () => null);

    expect(DiscordOutbox::where('dedup_key', 'key-2')->first()->sent_at)->not->toBeNull();
});
```

- [ ] **Step 3: Rot laufen lassen + grün**

Run: `php artisan migrate && ./vendor/bin/pest tests/Feature/Discord/OutboxDedupTest.php`
Expected: PASS (Model+Guard aus Step 1).

- [ ] **Step 4: Notification-Channel + EventAnnouncement**

Create `app/Modules/Discord/Notifications/EventAnnouncement.php` (implementiert `toDiscord()` → gibt Channel-ID + Content zurück) und `app/Modules/Discord/Channels/DiscordChannel.php` (liest `toDiscord`, sendet über `DiscordClient`). Der Channel wird via `via(): ['discord' => DiscordChannel::class]` bzw. Custom-Channel-Registrierung angesprochen (Doku-Weg aus dem Verify-Step). Die `EventAnnouncement`-Notification ist die Trägerklasse für „Anmeldung offen" und die Reminder.

```php
<?php

namespace App\Modules\Discord\Channels;

use App\Modules\Discord\Contracts\DiscordClient;

class DiscordChannel
{
    public function __construct(private readonly DiscordClient $client) {}

    public function send(object $notifiable, \Illuminate\Notifications\Notification $notification): void
    {
        /** @var array{channelId: string, content: string} $payload */
        $payload = $notification->toDiscord($notifiable); // @phpstan-ignore-line method.notFound

        $this->client->sendMessage($payload['channelId'], $payload['content']);
    }
}
```

> **Design-Entscheidung:** Announcements gehen an einen festen Announce-Channel (`config services.discord.announce_channel_id`), nicht als DM — die Reminder erreichen so alle. DM-basierte Notifications (`sendDm`) bleiben für gezielte User-Benachrichtigungen ab M3 reserviert. Der `DiscordChannel` ist die generische Brücke; die konkrete Ziel-Channel-ID liefert die Notification.

- [ ] **Step 5: AnnounceRegistrationOpen-Listener + Test**

Create `tests/Feature/Discord/AnnounceRegistrationOpenTest.php`:

```php
<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config(['services.discord.announce_channel_id' => 'announce-1']);
});

it('announces once when an event enters registration', function () {
    $event = Event::factory()->announced()->create();

    app(TransitionEventStatus::class)->handle($event, EventStatus::Registration);

    $this->fake->assertMessageSent('announce-1');
    expect(collect($this->fake->messages))->toHaveCount(1);
});

it('does not announce twice for the same event', function () {
    $event = Event::factory()->announced()->create();

    // Transition to registration fires the announcement once; a manual re-fire of
    // the same domain event must be deduplicated by the outbox.
    app(TransitionEventStatus::class)->handle($event, EventStatus::Registration);
    event(new \App\Modules\Events\Events\EventStatusChanged($event, EventStatus::Announced, EventStatus::Registration));

    expect(collect($this->fake->messages))->toHaveCount(1);
});
```

Create `app/Modules/Discord/Listeners/AnnounceRegistrationOpen.php`:

```php
<?php

namespace App\Modules\Discord\Listeners;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;

class AnnounceRegistrationOpen
{
    public function __construct(
        private readonly DiscordOutboxGuard $guard,
        private readonly DiscordClient $client,
    ) {}

    public function handle(EventStatusChanged $event): void
    {
        if ($event->to !== EventStatus::Registration) {
            return;
        }

        $channelId = config('services.discord.announce_channel_id');
        if (blank($channelId)) {
            return;
        }

        $this->guard->once(
            "event-{$event->event->id}-registration-open",
            'registration_open',
            fn () => $this->client->sendMessage(
                (string) $channelId,
                __('discord.registration_open', ['event' => $event->event->name]),
            ),
        );
    }
}
```

Listener registrieren (Laravel-13-Event-Discovery oder explizit in `AppServiceProvider::boot()`):

```php
Event::listen(EventStatusChanged::class, AnnounceRegistrationOpen::class);
```

- [ ] **Step 6: SendRemindersCommand + Time-Travel-Test**

Create `tests/Feature/Discord/SendRemindersTest.php`:

```php
<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config(['services.discord.announce_channel_id' => 'announce-1']);
});

it('sends the 24h reminder exactly once within the window', function () {
    $event = Event::factory()->registration()->create(['starts_at' => now()->addHours(24)]);

    $this->travelTo(now(), function () {
        $this->artisan('lanomat:send-reminders')->assertSuccessful();
        $this->artisan('lanomat:send-reminders')->assertSuccessful(); // second tick
    });

    $sent = collect($this->fake->messages)->filter(fn ($m) => str_contains($m['content'], '24'));
    expect($sent)->toHaveCount(1);
});

it('sends the 1h reminder when starts_at is within an hour', function () {
    Event::factory()->registration()->create(['starts_at' => now()->addMinutes(45)]);

    $this->artisan('lanomat:send-reminders')->assertSuccessful();

    expect(collect($this->fake->messages))->not->toBeEmpty();
});

it('does not send a reminder for events far in the future', function () {
    Event::factory()->registration()->create(['starts_at' => now()->addDays(10)]);

    $this->artisan('lanomat:send-reminders')->assertSuccessful();

    $this->fake->assertNothingSent();
});
```

Create `app/Modules/Discord/Console/SendRemindersCommand.php`:

```php
<?php

namespace App\Modules\Discord\Console;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'lanomat:send-reminders';

    protected $description = 'Send Discord reminders for upcoming events (24h / 1h), deduplicated via the outbox.';

    public function handle(DiscordOutboxGuard $guard, DiscordClient $client): int
    {
        $channelId = config('services.discord.announce_channel_id');
        if (blank($channelId)) {
            return self::SUCCESS;
        }

        $upcoming = Event::query()
            ->whereIn('status', [
                EventStatus::Announced->value,
                EventStatus::Registration->value,
                EventStatus::Live->value,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(25))
            ->get();

        foreach ($upcoming as $event) {
            $hoursUntil = now()->diffInHours($event->starts_at, false);

            // 24h window: 23–25h out. 1h window: <=1h out.
            $reminder = match (true) {
                $hoursUntil <= 1 => ['1h', 1],
                $hoursUntil >= 23 && $hoursUntil <= 25 => ['24h', 24],
                default => null,
            };

            if ($reminder === null) {
                continue;
            }

            [$suffix, $hours] = $reminder;

            $guard->once(
                "event-{$event->id}-reminder-{$suffix}",
                "reminder_{$suffix}",
                fn () => $client->sendMessage(
                    (string) $channelId,
                    __('discord.reminder', ['event' => $event->name, 'hours' => $hours]),
                ),
            );
        }

        return self::SUCCESS;
    }
}
```

> **Time-Travel-Test-Logik:** Zwei aufeinanderfolgende `lanomat:send-reminders`-Ticks im selben Zeitfenster ergeben genau **einen** 24h-Reminder — der zweite Tick trifft auf den bestehenden Outbox-`dedup_key` und ist No-op. Das ist der geforderte „Reminder feuert genau einmal"-Nachweis. Die `diffInHours(..., false)`-Vorzeichen-Variante gegen die aktuelle Carbon-Doku prüfen (Verify-Step) — falls die Semantik abweicht, die Fensterbedingung anpassen und im Commit vermerken.

- [ ] **Step 7: Scheduler + Übersetzungen**

Scheduler registrieren (Ort per Verify-Step; Beispiel `bootstrap/app.php`→`->withSchedule()`):

```php
use Illuminate\Console\Scheduling\Schedule;

->withSchedule(function (Schedule $schedule): void {
    $schedule->command('lanomat:send-reminders')->everyFiveMinutes();
})
```

Create `lang/de/discord.php`:

```php
<?php

return [
    'registration_open' => 'Die Anmeldung für :event ist jetzt geöffnet!',
    'reminder' => 'Erinnerung: :event beginnt in etwa :hours Stunden.',
];
```

- [ ] **Step 8: Grün + Gate**

Run: `./vendor/bin/pest tests/Feature/Discord`
Expected: PASS

Run: `composer check`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat(discord): notification channel, outbox dedup guard, registration-open listener and send-reminders scheduler command"
```

---

### Task 12: Phasenabschluss — Doku, Locale-Smoke-Check, Voll-Verifikation, Tag

**Files:**
- Modify: `CLAUDE.md` (Current-state), `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (M2-Erkenntnisse + Seat-Editor-Abweichung nachtragen)

- [ ] **Step 1: Roadmap als lebendes Dokument pflegen**

M2-Erkenntnisse in der Roadmap nachtragen (z. B.: Seat-Editor als eigenständige `SeatResource` statt Event-Tab; Registrierung verschickt Notification direkt im Controller statt via Domain-Event; gewählte QR-Lib; Scheduler-Registrierungsort; Discord-Custom-Channel-Weg).

- [ ] **Step 2: Locale-Smoke-Check (i18n-Gate)**

Manuell mit `APP_LOCALE=de` (bzw. ein dedizierter Feature-Test): öffentliche Anmeldeseite, „Meine Anmeldung", Sitzplan und die Glocke rendern **deutschen Text**, keine rohen Übersetzungs-Keys (`registration.page.title` etc.). Filament-Labels (Anmeldungen-RelationManager, Seat-Grid) auf Deutsch. Ein Beispiel-Assertion existiert bereits pro Task (i18n-Gate), hier der End-to-End-Sichtcheck.

- [ ] **Step 3: Manuelle Abnahme (kompletter Durchlauf)**

- Orga legt Event „Testlan 2026" an, `settings.tickets = ['early_bird','standard']`, schaltet auf `registration` → Discord-Announce-Channel bekommt „Anmeldung offen" (Testnachricht landet im Channel).
- Teilnehmer meldet sich an (QR erscheint), wählt einen Platz auf dem Sitzplan, wechselt ihn.
- Orga öffnet `/orga/events/testlan-2026/checkin`, scannt/tippt den Token → Teilnehmer eingecheckt; erneuter Scan meldet „bereits eingecheckt".
- Glocke zeigt „Anmeldung bestätigt".
- `php artisan lanomat:send-reminders` in einem 24h-Fenster feuert genau einmal (zweiter Aufruf sendet nichts).

- [ ] **Step 4: Voll-Verifikation**

Run: `composer check && npm run lint && npm run build`
Expected: alles PASS

- [ ] **Step 5: Commit + Tag**

```bash
git add -A && git commit -m "docs: update state after M2 (registration, seating, notifications, discord base)"
git tag m2 && git push --tags
```

---

## Abnahme-Checkliste M2

- [ ] CI grün (php + frontend Jobs), `composer check` lokal grün, `npm run lint && npm run build` grün.
- [ ] **Anmelde-→Platzwahl-→Check-in-Durchlauf** in Feature-Tests vollständig: Anmeldung (voll/doppelt/falscher Status/unbekanntes Ticket), Storno (inkl. Idempotenz), Platzwahl + Wechsel + Race (zwei User, ein Platz → DB-Unique → `SeatException::taken()`), QR-Check-in (unbekannt/doppelt/falsches Event/storniert), Orga-Autorisierung.
- [ ] **Mass-Assignment-Disziplin (M0):** `status`, `paid_at`, `checked_in_at`, `qr_token` nicht fillable; nur über Actions/Orga-Actions gesetzt; `role` nie berührt. QR-Check-in validiert `qr_token` serverseitig, Event kommt aus der Route, Policy orga/admin.
- [ ] Jede Autorisierung über Policy/Gate; nie Client-User-ID (`$request->user()` überall).
- [ ] **Task 0:** `Event::isPubliclyVisible()`/`scopePubliclyVisible()`; Controller nutzt den Helper; Draft-Event → 404; Filament-EventsTable zeigt kopierbaren öffentlichen Link.
- [ ] Registrierungs-CTA auf der Event-Seite verdrahtet: bei `registration` Link auf `events.register`, sonst disabled + `aria-disabled`.
- [ ] Filament: Registrations-RelationManager (Suche, Paid-Toggle als Action, CSV-Export) am Event; Seat-Editor (`/admin/seats`, Raster-Bulk-Anlage, Einzel-Edit inkl. `meta.switch_port`/`meta.ip`), beide orga-only (403 für Participant).
- [ ] Teilnehmer-Sitzplan (`/events/{slug}/seating`): öffentliches SVG-Raster mit Nicknames belegter Plätze; Platzwahl/-wechsel nur mit eigener aktiver Registration.
- [ ] Notifications: `database`-Channel + Glocken-Dropdown + `notification_prefs`; „Anmeldung bestätigt" landet in-app.
- [ ] Discord: `DiscordClient`-Contract + `HttpDiscordClient` (nur gegen `Http::fake()` getestet) + `FakeDiscordClient`; **kein Test trifft die echte API**.
- [ ] **Outbox-Dedup:** `discord_outbox.dedup_key` unique; „Anmeldung offen"-Announcement und 24h/1h-Reminder feuern **genau einmal** (Time-Travel-Test, zweiter Scheduler-Tick ist No-op).
- [ ] Scheduler-Command `lanomat:send-reminders` registriert (alle 5 Minuten).
- [ ] **i18n-Gate:** pro `lang/de`-Task mindestens eine Feature-Assertion auf übersetztes Label; Locale-Smoke-Check (`APP_LOCALE=de`) zeigt deutschen Text, keine rohen Keys, auf allen Teilnehmerseiten + Filament-Flächen.
- [ ] Modul-Grenzen: `Registration`, `Seating`, `Notifications`, `Discord` als eigenständige Module unter `app/Modules/`; Kommunikation nur über `event_id`-FKs und Laravel-Events, keine Fremd-Tabellenzugriffe.
- [ ] Manuell: Testnachricht landet im Discord-Channel; kompletter Durchlauf durchgeklickt.
- [ ] Git-Tag `m2` gesetzt.

---

## Offene Fragen / Annahmen (aus den Inputs nicht abschließend auflösbar)

1. **`RegistrationStatus::Pending` vs. `Confirmed` beim Anmelden:** Die Roadmap listet drei Status (`pending/confirmed/cancelled`), das Design nennt „Bezahlstatus manuell durch Orga". Ich habe entschieden: Anmeldung → sofort `Confirmed` (LAN-Realität: Teilnahme steht, nur Bezahlung ist manuell über `paid_at`). `Pending` bleibt als Status vorhanden, wird in M2 aber nicht automatisch vergeben. Falls stattdessen `Pending`-bis-bezahlt gewünscht ist, ändert sich nur der Default in `RegisterForEvent` + ein Test.
2. **Discord-Custom-Channel-Registrierung:** Der exakte Laravel-13-Weg (Klassen-String-Channel via `via()` vs. `ChannelManager::extend('discord', ...)`) ist im Verify-Step offengelassen — beide sind valide; der Ausführende wählt nach aktueller Doku. Die Tests hängen nur am `DiscordClient`/`FakeDiscordClient`, nicht am Registrierungsweg.
3. **Scheduler-Definitionsort:** Laravel 11+ erlaubt `bootstrap/app.php`→`withSchedule()` **oder** `routes/console.php`. Der im Repo bereits etablierte Ort (falls M0/M1 schon einen Scheduler-Eintrag haben) ist zu bevorzugen — im Verify-Step prüfen.
4. **Reminder-Fenster-Semantik:** Das 24h-Fenster (23–25h) setzt einen Scheduler-Lauf mindestens alle ~2h voraus (bei 5-Minuten-Takt garantiert). Die genaue `Carbon::diffInHours`-Vorzeichen-Konvention ist zu verifizieren; die Outbox-Dedup macht ein zu breites Fenster unschädlich (höchstens früherer, aber einmaliger Versand).
5. **Seat-Release bei Storno:** `CancelRegistration` gibt den Sitzplatz in M2 **nicht** automatisch frei (kein Listener verdrahtet), da `ReleaseSeat` eine `EventRegistration` erwartet und die Kopplung Registration→Seating ein modulübergreifender Listener wäre. Für LAN-Scale akzeptabel (Orga kann Platz im Editor lösen); ein `RegistrationCancelled`-Event + Seating-Listener ist ein sauberer Nachzug (Backlog-Notiz für die Roadmap). Falls für M2 zwingend gewünscht, wäre das ein zusätzlicher kleiner Task.
