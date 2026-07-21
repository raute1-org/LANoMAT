<?php

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Support\GalleryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('orders approved photos with highlights first, then most recent, eager-loading the uploader', function () {
    $event = Event::factory()->create();

    $old = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => now()->subHour()]);
    $recent = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => now()]);
    $highlight = EventPhoto::factory()->approved()->create([
        'event_id' => $event->id,
        'is_highlight' => true,
        'created_at' => now()->subDay(),
    ]);
    EventPhoto::factory()->create(['event_id' => $event->id]); // pending → excluded
    EventPhoto::factory()->rejected()->create(['event_id' => $event->id]); // rejected → excluded

    $photos = app(GalleryQuery::class)->approvedFor($event);

    expect($photos->pluck('id')->all())->toBe([$highlight->id, $recent->id, $old->id]);
    expect($photos->first()->relationLoaded('uploader'))->toBeTrue();
});

it('breaks ties between equally-ranked photos by id descending', function () {
    $event = Event::factory()->create();
    $sameTimestamp = now();

    $first = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => $sameTimestamp]);
    $second = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => $sameTimestamp]);

    $photos = app(GalleryQuery::class)->approvedFor($event);

    expect($photos->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('scopes approvedFor to the given event only', function () {
    $event = Event::factory()->create();
    $other = Event::factory()->create();

    $mine = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    EventPhoto::factory()->approved()->create(['event_id' => $other->id]);

    $photos = app(GalleryQuery::class)->approvedFor($event);

    expect($photos->pluck('id')->all())->toBe([$mine->id]);
});

it('returns highlights first for highlightsFor, falling back to most-recent approved, capped at the limit', function () {
    $event = Event::factory()->create();

    $highlight = EventPhoto::factory()->approved()->create([
        'event_id' => $event->id,
        'is_highlight' => true,
        'created_at' => now()->subDay(),
    ]);
    $recentA = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => now()]);
    $recentB = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => now()->subMinute()]);
    EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'created_at' => now()->subMinutes(2)]);

    $photos = app(GalleryQuery::class)->highlightsFor($event, limit: 3);

    expect($photos->pluck('id')->all())->toBe([$highlight->id, $recentA->id, $recentB->id]);
});

it('defaults highlightsFor to a limit of 6', function () {
    $event = Event::factory()->create();
    EventPhoto::factory()->approved()->count(8)->create(['event_id' => $event->id]);

    $photos = app(GalleryQuery::class)->highlightsFor($event);

    expect($photos)->toHaveCount(6);
});
