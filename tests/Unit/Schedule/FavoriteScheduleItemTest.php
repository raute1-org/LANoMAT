<?php

use App\Models\User;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Actions\UnfavoriteScheduleItem;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use Illuminate\Database\UniqueConstraintViolationException;

it('favorites a schedule item for a user', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();

    $favorite = app(FavoriteScheduleItem::class)->handle($item, $user);

    expect($favorite)->toBeInstanceOf(ScheduleItemFavorite::class)
        ->and($favorite->schedule_item_id)->toBe($item->id)
        ->and($favorite->user_id)->toBe($user->id)
        ->and(ScheduleItemFavorite::query()->count())->toBe(1);
});

it('is idempotent when favoriting the same item twice', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();

    $first = app(FavoriteScheduleItem::class)->handle($item, $user);
    $second = app(FavoriteScheduleItem::class)->handle($item, $user);

    expect($second->id)->toBe($first->id)
        ->and(ScheduleItemFavorite::query()->count())->toBe(1);
});

it('enforces a unique constraint per (schedule_item, user)', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();

    ScheduleItemFavorite::factory()->create([
        'schedule_item_id' => $item->id,
        'user_id' => $user->id,
    ]);

    expect(fn () => ScheduleItemFavorite::query()->insert([
        'schedule_item_id' => $item->id,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows different users to favorite the same item', function () {
    $item = ScheduleItem::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    app(FavoriteScheduleItem::class)->handle($item, $userA);
    app(FavoriteScheduleItem::class)->handle($item, $userB);

    expect(ScheduleItemFavorite::query()->count())->toBe(2);
});

it('removes a favorite on unfavorite', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($item, $user);

    app(UnfavoriteScheduleItem::class)->handle($item, $user);

    expect(ScheduleItemFavorite::query()->count())->toBe(0);
});

it('does nothing when unfavoriting an item that was never favorited', function () {
    $item = ScheduleItem::factory()->create();
    $user = User::factory()->create();

    app(UnfavoriteScheduleItem::class)->handle($item, $user);

    expect(ScheduleItemFavorite::query()->count())->toBe(0);
});
