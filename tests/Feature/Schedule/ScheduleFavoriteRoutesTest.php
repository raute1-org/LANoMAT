<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('favorites a schedule item via the authenticated route', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/schedule/{$item->id}/favorite")
        ->assertRedirect();

    expect(ScheduleItemFavorite::query()
        ->where('schedule_item_id', $item->id)
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

it('requires authentication to favorite a schedule item', function () {
    $item = ScheduleItem::factory()->create();

    $this->post("/schedule/{$item->id}/favorite")->assertRedirect('/login');

    expect(ScheduleItemFavorite::query()->count())->toBe(0);
});

it('unfavorites a schedule item via the authenticated route', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->actingAs($user)
        ->delete("/schedule/{$item->id}/favorite")
        ->assertRedirect();

    expect(ScheduleItemFavorite::query()->count())->toBe(0);
});

it("only removes the acting user's own favorite, never another user's", function () {
    $item = ScheduleItem::factory()->create();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $owner);

    $this->actingAs($intruder)
        ->delete("/schedule/{$item->id}/favorite")
        ->assertRedirect();

    expect(ScheduleItemFavorite::query()
        ->where('schedule_item_id', $item->id)
        ->where('user_id', $owner->id)
        ->exists())->toBeTrue();
});

it('marks an item as mine in the schedule page payload once favorited', function () {
    $event = Event::factory()->live()->create();
    $item = ScheduleItem::factory()->for($event)->create();
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/schedule")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('items.0.mine', true));
});

it('marks an item as not mine for a guest visitor', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create();

    $this->get("/events/{$event->slug}/schedule")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('items.0.mine', false));
});
