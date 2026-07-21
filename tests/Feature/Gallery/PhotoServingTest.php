<?php

use App\Models\User;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('serves an approved photo to any authenticated viewer but 403s a stranger on a pending one', function () {
    Storage::fake('local');
    $approved = EventPhoto::factory()->approved()->create();
    Storage::disk('local')->put($approved->path, 'x');
    $pending = EventPhoto::factory()->create();
    Storage::disk('local')->put($pending->path, 'y');

    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get("/gallery/photos/{$approved->id}")->assertOk();
    $this->actingAs($viewer)->get("/gallery/photos/{$pending->id}")->assertForbidden();
});

it('lets the owner view their own pending photo and thumbnail', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $pending = EventPhoto::factory()->create(['uploaded_by' => $owner->id]);
    Storage::disk('local')->put($pending->path, 'x');
    Storage::disk('local')->put($pending->thumb_path, 'x-thumb');

    $this->actingAs($owner)->get("/gallery/photos/{$pending->id}")->assertOk();
    $this->actingAs($owner)->get("/gallery/photos/{$pending->id}/thumb")->assertOk();
});

it('lets orga view any pending photo', function () {
    Storage::fake('local');
    $orga = User::factory()->orga()->create();
    $pending = EventPhoto::factory()->create();
    Storage::disk('local')->put($pending->path, 'x');

    $this->actingAs($orga)->get("/gallery/photos/{$pending->id}")->assertOk();
});

it('redirects a guest to login instead of serving a photo', function () {
    Storage::fake('local');
    $approved = EventPhoto::factory()->approved()->create();
    Storage::disk('local')->put($approved->path, 'x');

    $this->get("/gallery/photos/{$approved->id}")->assertRedirect();
});

it('serves the thumbnail for an approved photo to any authenticated viewer', function () {
    Storage::fake('local');
    $approved = EventPhoto::factory()->approved()->create();
    Storage::disk('local')->put($approved->thumb_path, 'thumb-bytes');

    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get("/gallery/photos/{$approved->id}/thumb")->assertOk();
});
