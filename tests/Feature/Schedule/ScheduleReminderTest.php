<?php

use App\Models\User;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use App\Modules\Schedule\Notifications\ScheduleItemStartingSoon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends exactly one start reminder to a favoriter inside the lead window and stamps reminded_at', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addMinutes(10)]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->artisan('lanomat:send-schedule-reminders')->assertSuccessful();

    Notification::assertSentTo($user, ScheduleItemStartingSoon::class);
    Notification::assertSentToTimes($user, ScheduleItemStartingSoon::class, 1);

    $favorite = ScheduleItemFavorite::query()->firstOrFail();
    expect($favorite->reminded_at)->not->toBeNull();
});

it('does not resend the reminder on a second run', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addMinutes(10)]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->artisan('lanomat:send-schedule-reminders')->assertSuccessful();
    $this->artisan('lanomat:send-schedule-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, ScheduleItemStartingSoon::class, 1);
});

it('does not send a reminder for an item outside the lead window', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->addHours(2)]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->artisan('lanomat:send-schedule-reminders')->assertSuccessful();

    Notification::assertNotSentTo($user, ScheduleItemStartingSoon::class);
});

it('does not send a reminder for an item that already started', function () {
    Notification::fake();

    $item = ScheduleItem::factory()->create(['starts_at' => now()->subMinutes(5)]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->artisan('lanomat:send-schedule-reminders')->assertSuccessful();

    Notification::assertNotSentTo($user, ScheduleItemStartingSoon::class);
});

it('stores the german title and body in the reminder notification payload', function () {
    fakeDiscord();

    $item = ScheduleItem::factory()->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addMinutes(10),
    ]);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->artisan('lanomat:send-schedule-reminders')->assertSuccessful();

    $data = $user->unreadNotifications()->firstOrFail()->data;

    expect($data['category'])->toBe('schedule')
        ->and($data['title'])->not->toBeEmpty()
        ->and($data['body'])->toContain('Opening Ceremony');
});
