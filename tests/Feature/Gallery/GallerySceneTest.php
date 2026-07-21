<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('builds a gallery scene payload with only public photo url + caption (no PII)', function () {
    $event = Event::factory()->create();
    $scene = InfoscreenScene::factory()->for($event)->create(['type' => SceneType::Gallery]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'caption' => 'Finale']);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['photos'][0])
        ->toHaveKey('url')
        ->toHaveKey('caption')
        ->not->toHaveKey('uploaderName')
        ->not->toHaveKey('uploaded_by')
        ->not->toHaveKey('id')
        ->and($payload['data']['photos'][0]['caption'])->toBe('Finale');
});

it('excludes pending and rejected photos from the gallery scene payload', function () {
    $event = Event::factory()->create();
    $scene = InfoscreenScene::factory()->for($event)->create(['type' => SceneType::Gallery]);
    EventPhoto::factory()->create(['event_id' => $event->id]); // pending
    EventPhoto::factory()->rejected()->create(['event_id' => $event->id]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['photos'])->toBe([]);
});

it('returns an empty photo list when the scene has no event', function () {
    $scene = InfoscreenScene::factory()->create(['type' => SceneType::Gallery]);
    $scene->event()->dissociate();

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->toBe(['photos' => []]);
});

it('serves an approved photo of a public event via the public route', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Announced]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($photo->path, 'photo-bytes');
    Storage::disk('local')->put($photo->thumb_path, 'thumb-bytes');

    $this->get(route('gallery.photos.public.show', $photo))->assertOk();
    $this->get(route('gallery.photos.public.thumb', $photo))->assertOk();
});

it('404s a pending photo on the public route even for a guest', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Announced]);
    $photo = EventPhoto::factory()->create(['event_id' => $event->id, 'visibility' => PhotoVisibility::Pending]);
    Storage::disk('local')->put($photo->path, 'photo-bytes');

    $this->get(route('gallery.photos.public.show', $photo))->assertNotFound();
});

it('404s an approved photo belonging to a draft (not publicly visible) event on the public route', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Draft]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($photo->path, 'photo-bytes');

    $this->get(route('gallery.photos.public.show', $photo))->assertNotFound();
});
