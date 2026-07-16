<?php

use App\Models\User;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Events\ScheduleItemTimeChanged;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Notifications\ScheduleItemChanged;
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
