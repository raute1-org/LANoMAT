<?php

use App\Models\User;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Models\Event;
use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Registration\Actions\RotateEventCheckinToken;
use App\Modules\Registration\Models\EventCheckinToken;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\SelfCheckInWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventBus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function selfCheckinDiscordUser(): SocialiteUser
{
    $user = new SocialiteUser;
    $user->map(['id' => '999', 'nickname' => 'Walkin', 'name' => 'Walkin', 'email' => 'walkin@example.com', 'avatar' => null]);

    return $user;
}

function tokenFor(Event $event): string
{
    return EventCheckinToken::forEvent($event)->token;
}

it('redirects guests to login and keeps the check-in URL as intended target', function () {
    $event = Event::factory()->live()->create();
    $url = '/checkin/'.tokenFor($event);

    $this->get($url)->assertRedirect('/login');
    expect(session('url.intended'))->toBe(url($url));
});

it('returns to the check-in page after the Discord OAuth round trip', function () {
    $event = Event::factory()->live()->create();
    $url = url('/checkin/'.tokenFor($event));

    $this->get($url)->assertRedirect('/login');

    Socialite::shouldReceive('driver->user')->andReturn(selfCheckinDiscordUser());
    $this->get('/auth/discord/callback')->assertRedirect($url);
});

it('also protects the POST for guests', function () {
    $event = Event::factory()->live()->create();

    $this->post('/checkin/'.tokenFor($event))->assertRedirect('/login');
});

it('answers 404 for unknown and rotated tokens without leaking info', function () {
    $event = Event::factory()->live()->create();
    $old = tokenFor($event);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/checkin/does-not-exist')->assertNotFound();
    $this->actingAs($user)->post('/checkin/does-not-exist')->assertNotFound();

    app(RotateEventCheckinToken::class)->handle($event);

    $this->actingAs($user)->get("/checkin/{$old}")->assertNotFound();
    $this->actingAs($user)->post("/checkin/{$old}")->assertNotFound();
    $this->actingAs($user)->get('/checkin/'.tokenFor($event))->assertOk();
});

it('answers 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->get('/checkin/'.tokenFor($event))->assertNotFound();
});

it('rotates the token: new value, rotated_at set, one row per event', function () {
    $event = Event::factory()->live()->create();
    $old = tokenFor($event);

    $new = app(RotateEventCheckinToken::class)->handle($event);

    expect($new->token)->not->toBe($old)->and(strlen($new->token))->toBe(40)
        ->and($new->rotated_at)->not->toBeNull()
        ->and(EventCheckinToken::where('event_id', $event->id)->count())->toBe(1);
});

it('checks a registered user in on a live event through CheckInRegistration', function () {
    EventBus::fake([PresenceUpdated::class]);
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->post('/checkin/'.tokenFor($event))->assertRedirect('/checkin/'.tokenFor($event));

    expect($reg->fresh()->checked_in_at)->not->toBeNull();
    EventBus::assertDispatched(PresenceUpdated::class);
});

it('allows unpaid registrations to check in', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create(['paid_at' => null]);

    $this->actingAs($user)->post('/checkin/'.tokenFor($event));

    expect($reg->fresh()->checked_in_at)->not->toBeNull();
});

it('is idempotent on a second tap and shows the already state', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();
    $token = tokenFor($event);

    $this->actingAs($user)->post("/checkin/{$token}");
    $first = $reg->fresh()->checked_in_at;
    $this->actingAs($user)->post("/checkin/{$token}")->assertRedirect();

    expect($reg->fresh()->checked_in_at->equalTo($first))->toBeTrue();
    $this->actingAs($user)->get("/checkin/{$token}")
        ->assertInertia(fn ($p) => $p->component('Checkin/Show')->where('state', 'already')
            ->where('checkedInAt', fn ($v) => $v !== null));
});

it('opens the window 12h before start for a registration-status event', function () {
    $event = Event::factory()->registration()->create(['starts_at' => now()->addHours(11)]);
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->post('/checkin/'.tokenFor($event));

    expect(SelfCheckInWindow::HOURS_BEFORE_START)->toBe(12)
        ->and($reg->fresh()->checked_in_at)->not->toBeNull();
});

it('denies outside the window and shows not_open with the opening time', function () {
    $event = Event::factory()->registration()->create(['starts_at' => now()->addHours(13)]);
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();
    $token = tokenFor($event);

    $this->actingAs($user)->post("/checkin/{$token}");
    expect($reg->fresh()->checked_in_at)->toBeNull();

    $this->actingAs($user)->get("/checkin/{$token}")
        ->assertInertia(fn ($p) => $p->where('state', 'not_open')
            ->where('opensAt', $event->starts_at->copy()->subHours(12)->toIso8601String()));
});

it('shows over for finished events and denies', function () {
    $event = Event::factory()->finished()->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)->post('/checkin/'.tokenFor($event));

    expect($reg->fresh()->checked_in_at)->toBeNull();
    $this->actingAs($user)->get('/checkin/'.tokenFor($event))->assertInertia(fn ($p) => $p->where('state', 'over'));
});

it('shows not_registered with register CTA when walk-in registration is open', function () {
    $event = Event::factory()->live()->create(['max_participants' => 5]);

    $this->actingAs(User::factory()->create())->get('/checkin/'.tokenFor($event))
        ->assertInertia(fn ($p) => $p->where('state', 'not_registered')
            ->where('availability', 'open')->where('freeSpots', 5));
});

it('shows full for non-registered users when the event is full', function () {
    $event = Event::factory()->live()->create(['max_participants' => 1]);
    EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())->get('/checkin/'.tokenFor($event))
        ->assertInertia(fn ($p) => $p->where('state', 'not_registered')->where('availability', 'full'));
});

it('does not check in a user without or with a cancelled registration', function () {
    $event = Event::factory()->live()->create();
    $cancelledUser = User::factory()->create();
    $cancelled = EventRegistration::factory()->for($event)->for($cancelledUser)->cancelled()->create();
    $token = tokenFor($event);

    $this->actingAs(User::factory()->create())->post("/checkin/{$token}")->assertRedirect();
    $this->actingAs($cancelledUser)->post("/checkin/{$token}")->assertRedirect();

    expect($cancelled->fresh()->checked_in_at)->toBeNull()
        ->and(EventRegistration::whereNotNull('checked_in_at')->count())->toBe(0);
});

it('lets a walk-in register on the check-in page and come back to check in', function () {
    $event = Event::factory()->live()->create(['max_participants' => 3]);
    $user = User::factory()->create();
    $token = tokenFor($event);

    $this->actingAs($user)->from("/checkin/{$token}")
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])
        ->assertRedirect("/checkin/{$token}");

    $this->actingAs($user)->get("/checkin/{$token}")->assertInertia(fn ($p) => $p->where('state', 'ready'));
    $this->actingAs($user)->post("/checkin/{$token}");

    expect(EventRegistration::where('user_id', $user->id)->first()->checked_in_at)->not->toBeNull();
});

it('only checks in the token owner event, never another one', function () {
    $a = Event::factory()->live()->create();
    $b = Event::factory()->live()->create();
    $user = User::factory()->create();
    $regB = EventRegistration::factory()->for($b)->for($user)->create();

    $this->actingAs($user)->post('/checkin/'.tokenFor($a));

    expect($regB->fresh()->checked_in_at)->toBeNull();
});

it('leaks neither a ticket qr token nor foreign data in the props', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();
    $other = EventRegistration::factory()->for($event)->create();
    $token = tokenFor($event);

    $json = $this->actingAs($user)->get("/checkin/{$token}")->viewData('page')['props'];
    $encoded = json_encode($json);

    expect($encoded)->not->toContain($reg->qr_token)
        ->and($encoded)->not->toContain($other->qr_token)
        ->and($encoded)->not->toContain($token)
        ->and($encoded)->not->toContain($other->user->name);
});

it('throttles the check-in endpoints', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $token = tokenFor($event);

    foreach (range(1, 30) as $_) {
        $this->actingAs($user)->post("/checkin/{$token}");
    }

    $this->actingAs($user)->post("/checkin/{$token}")->assertStatus(429);
});

it('rotates the token through the admin header action', function () {
    $event = Event::factory()->live()->create();
    $old = tokenFor($event);
    $this->actingAs(User::factory()->orga()->create());

    Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionExists('checkinQr')
        ->callAction('rotateCheckinToken')
        ->assertNotified();

    expect(tokenFor($event))->not->toBe($old);
});

it('opens the QR modal for orga', function () {
    $event = Event::factory()->live()->create();
    $token = tokenFor($event);
    $this->actingAs(User::factory()->orga()->create());

    Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->mountAction('checkinQr')
        ->assertActionMounted('checkinQr');
});

it('serves the printable QR page to orga only', function () {
    $event = Event::factory()->live()->create();
    $token = tokenFor($event);

    $this->actingAs(User::factory()->create())->get("/orga/events/{$event->slug}/checkin-qr")->assertForbidden();
    $this->actingAs(User::factory()->helper()->create())->get("/orga/events/{$event->slug}/checkin-qr")->assertForbidden();
    $this->actingAs(User::factory()->orga()->create())->get("/orga/events/{$event->slug}/checkin-qr")
        ->assertOk()->assertSee('QR-Code scannen und einchecken')->assertSee(url("/checkin/{$token}"));
});

it('keeps participants out of the admin event page', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())->get("/admin/events/{$event->getRouteKey()}/edit")->assertForbidden();
});

it('keeps the window closed even for admins (Gate::before must not bypass it)', function () {
    $event = Event::factory()->registration()->create(['starts_at' => now()->addDays(3)]);
    $admin = User::factory()->admin()->create();
    $reg = EventRegistration::factory()->for($event)->for($admin)->create();

    $this->actingAs($admin)->post('/checkin/'.tokenFor($event))->assertRedirect();

    expect($reg->fresh()->checked_in_at)->toBeNull();
});
