<?php

use App\Models\User;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Events\ScheduleItemModified;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Notifications\ScheduleItemChanged;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('dispatches ScheduleItemModified when starts_at changes', function () {
    Event::fake([ScheduleItemModified::class]);

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $item->update(['starts_at' => now()->addHours(2)]);

    Event::assertDispatched(ScheduleItemModified::class, fn ($event) => $event->scheduleItem->is($item));
});

it('does not dispatch ScheduleItemModified when only unrelated attributes change', function () {
    Event::fake([ScheduleItemModified::class]);

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $item->update(['sort' => 5, 'description' => 'More text']);

    Event::assertNotDispatched(ScheduleItemModified::class);
});

it('notifies favoriters with ScheduleItemChanged when the listener handles the time change', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['starts_at' => now()->addHours(3)]);

    Notification::assertSentTo($user, ScheduleItemChanged::class);
});

it('does not notify favoriters when only unrelated attributes change', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour()]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['sort' => 3]);

    Notification::assertNotSentTo($user, ScheduleItemChanged::class);
});

it('sends one bundled alarm for several changed fields in a single save', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHour(), 'title' => 'Old', 'location' => 'Hall A']);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->update(['title' => 'New', 'location' => 'Hall B', 'ends_at' => now()->addHours(4)]);

    Notification::assertSentToTimes($user, ScheduleItemChanged::class, 1);
});

it('alarms favoriters when the item is deleted', function () {
    fakeDiscord();

    $item = ScheduleItem::factory()->create(['title' => 'Karaoke', 'starts_at' => now()->addHour()]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->delete();

    $data = $user->unreadNotifications()->firstOrFail()->data;
    expect($data['title'])->toBe('Programmpunkt entfällt')
        ->and($data['body'])->toContain('Karaoke');
});

it('names the day when an item moves to another day', function () {
    fakeDiscord();

    $item = ScheduleItem::factory()->create(['title' => 'Finale', 'starts_at' => now()->addHour()]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $newStart = now()->addDays(2)->setTime(21, 0);
    $item->update(['starts_at' => $newStart]);

    $body = $user->unreadNotifications()->firstOrFail()->data['body'];
    $local = $newStart->copy()->setTimezone(config('lanomat.timezone'));
    expect($body)->toContain($local->format('d.m.'))
        ->and($body)->toContain($local->format('H:i'));
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

it('delivers the removal alarm for real even though the item row is already gone', function () {
    fakeDiscord();
    $item = ScheduleItem::factory()->create(['title' => 'Opening']);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $item->delete();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['body'])->toContain('Opening');
});
