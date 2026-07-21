<?php

use App\Modules\News\Models\NewsPost;
use App\Modules\News\Support\NewsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('orders published posts most-recent-first and excludes drafts and future-dated posts', function () {
    $old = NewsPost::factory()->create(['published_at' => now()->subDays(2)]);
    $recent = NewsPost::factory()->create(['published_at' => now()->subHour()]);
    NewsPost::factory()->draft()->create(); // published_at null → excluded
    NewsPost::factory()->scheduledInFuture()->create(); // future → excluded

    $posts = app(NewsQuery::class)->published();

    expect($posts->pluck('id')->all())->toBe([$recent->id, $old->id]);
});

it('caps published() at the given limit', function () {
    NewsPost::factory()->count(5)->create(['published_at' => now()->subHour()]);

    $posts = app(NewsQuery::class)->published(2);

    expect($posts)->toHaveCount(2);
});

it('defaults published() to a limit of 3', function () {
    NewsPost::factory()->count(5)->create(['published_at' => now()->subHour()]);

    $posts = app(NewsQuery::class)->published();

    expect($posts)->toHaveCount(3);
});

it('includes a post published exactly now', function () {
    $post = NewsPost::factory()->create(['published_at' => now()]);

    $posts = app(NewsQuery::class)->published();

    expect($posts->pluck('id')->all())->toBe([$post->id]);
});
