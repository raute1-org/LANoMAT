<?php

use App\Models\User;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Events\ScheduleItemTimeChanged;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Notifications\ScheduleItemChanged;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('dispatches ScheduleItemTimeChanged when starts_at changes', function () {
    Event::fake([ScheduleItemTimeChanged::class]);

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $item->update(['starts_at' => now()->addHours(2)]);

    Event::assertDispatched(ScheduleItemTimeChanged::class, fn ($event) => $event->scheduleItem->is($item));
});

it('does not dispatch ScheduleItemTimeChanged when only the title changes', function () {
    Event::fake([ScheduleItemTimeChanged::class]);

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $item->update(['title' => 'New Title']);

    Event::assertNotDispatched(ScheduleItemTimeChanged::class);
});

it('notifies favoriters with ScheduleItemChanged when the listener handles the time change', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['starts_at' => now()->addHours(3)]);

    Notification::assertSentTo($user, ScheduleItemChanged::class);
});

it('does not notify favoriters when only the title changes', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['title' => 'New Title']);

    Notification::assertNotSentTo($user, ScheduleItemChanged::class);
});

it('stores the german title and body in the change-alarm notification payload', function () {
    fakeDiscord();

    $item = ScheduleItem::factory()->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addHour(),
    ]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['starts_at' => now()->addHours(3)]);

    $data = $user->unreadNotifications()->firstOrFail()->data;

    expect($data['category'])->toBe('schedule')
        ->and($data['title'])->not->toBeEmpty()
        ->and($data['body'])->toContain('Opening Ceremony');
});

it('alarms an enrolled tournament participant who did not favorite the item', function () {
    Notification::fake();

    $tournament = Tournament::factory()->create(['starts_at' => now()->addHour()]);
    $item = ScheduleItem::factory()->create([
        'ref_type' => 'tournament',
        'ref_id' => $tournament->id,
        'starts_at' => $tournament->starts_at,
    ]);

    $participant = User::factory()->create();
    TournamentEntry::factory()->for($tournament)->solo()->create(['user_id' => $participant->id]);

    $item->update(['starts_at' => now()->addHours(3)]);

    Notification::assertSentTo($participant, ScheduleItemChanged::class);
});

it('notifies a user who is both favoriter and participant exactly once', function () {
    Notification::fake();

    $tournament = Tournament::factory()->create(['starts_at' => now()->addHour()]);
    $item = ScheduleItem::factory()->create([
        'ref_type' => 'tournament',
        'ref_id' => $tournament->id,
        'starts_at' => $tournament->starts_at,
    ]);

    $user = User::factory()->create();
    TournamentEntry::factory()->for($tournament)->solo()->create(['user_id' => $user->id]);
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['starts_at' => now()->addHours(3)]);

    Notification::assertSentToTimes($user, ScheduleItemChanged::class, 1);
});

it('alarms only favoriters for a non-tournament (custom) schedule item', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create([
        'ref_type' => null,
        'ref_id' => null,
        'starts_at' => now()->addHour(),
    ]);

    $favoriter = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $favoriter);

    $stranger = User::factory()->create();

    $item->update(['starts_at' => now()->addHours(3)]);

    Notification::assertSentTo($favoriter, ScheduleItemChanged::class);
    Notification::assertNotSentTo($stranger, ScheduleItemChanged::class);
});
