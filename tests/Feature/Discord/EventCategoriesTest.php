<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Models\DiscordEventCategory;
use App\Modules\Discord\Support\EventCategories;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config(['services.discord.guild_id' => 'guild-1']);
});

function fillCategory(FakeDiscordClient $fake, string $categoryId, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $fake->createChannel('guild-1', "filler-{$categoryId}-{$i}", $categoryId);
    }
}

it('creates a category named after the event on first use and reuses it', function () {
    $event = Event::factory()->create(['name' => 'LAN 2026']);

    $first = app(EventCategories::class)->liveFor($event, 3);
    $second = app(EventCategories::class)->liveFor($event, 3);

    expect($second)->toBe($first);
    expect(collect($this->fake->channels)->where('category', true)->pluck('name')->all())->toBe(['LAN 2026']);
    expect(DiscordEventCategory::query()->where('event_id', $event->id)->where('kind', 'live')->count())->toBe(1);
});

it('keeps every event in a category of its own', function () {
    $a = app(EventCategories::class)->liveFor(Event::factory()->create(['name' => 'LAN 2026']), 3);
    $b = app(EventCategories::class)->liveFor(Event::factory()->create(['name' => 'LAN 2027']), 3);

    expect($a)->not->toBe($b);
});

it('starts a numbered follow-up category when the current one cannot take the new channels', function () {
    $event = Event::factory()->create(['name' => 'LAN 2026']);
    $first = app(EventCategories::class)->liveFor($event, 3);
    fillCategory($this->fake, $first, 48); // 2 free, 3 needed

    $second = app(EventCategories::class)->liveFor($event, 3);

    expect($second)->not->toBe($first);
    expect(collect($this->fake->channels)->where('category', true)->pluck('name')->all())->toBe(['LAN 2026', 'LAN 2026 (2)']);
    expect(app(EventCategories::class)->liveFor($event, 3))->toBe($second);
});

it('still uses the current category when exactly enough room is left', function () {
    $event = Event::factory()->create(['name' => 'LAN 2026']);
    $first = app(EventCategories::class)->liveFor($event, 3);
    fillCategory($this->fake, $first, 47); // 3 free

    expect(app(EventCategories::class)->liveFor($event, 3))->toBe($first);
});

it('keeps the archive in its own category per event, with follow-ups', function () {
    $event = Event::factory()->create(['name' => 'LAN 2026']);
    $live = app(EventCategories::class)->liveFor($event, 3);
    $archive = app(EventCategories::class)->archiveFor($event);
    fillCategory($this->fake, $archive, 50);

    $next = app(EventCategories::class)->archiveFor($event);

    expect($archive)->not->toBe($live);
    expect($next)->not->toBe($archive);
    expect(collect($this->fake->channels)->where('category', true)->pluck('name')->all())
        ->toBe(['LAN 2026', 'Archiv: LAN 2026', 'Archiv: LAN 2026 (2)']);
});

it('creates the category again when it was deleted on the server', function () {
    $event = Event::factory()->create(['name' => 'LAN 2026']);
    $old = app(EventCategories::class)->liveFor($event, 3);
    $this->fake->deleteChannel($old);

    $new = app(EventCategories::class)->liveFor($event, 3);

    expect($new)->not->toBe($old);
    expect(DiscordEventCategory::query()->where('event_id', $event->id)->where('kind', 'live')->value('category_id'))->toBe($new);
});

it('shortens a long event name to what Discord accepts', function () {
    $event = Event::factory()->create(['name' => str_repeat('A', 140)]);

    app(EventCategories::class)->liveFor($event, 3);
    app(EventCategories::class)->archiveFor($event);

    foreach (collect($this->fake->channels)->where('category', true)->pluck('name') as $name) {
        expect(mb_strlen($name))->toBeLessThanOrEqual(100);
    }
});
