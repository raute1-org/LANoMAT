<?php

use App\Models\User;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Filament\Resources\EventPhotos\Pages\ListEventPhotos;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Policies\EventPhotoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('forbids participants from the event-photos resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/event-photos')
        ->assertForbidden();
});

it('enforces viewAny=isOrga on EventPhotoPolicy directly, independent of the panel gate', function () {
    $policy = new EventPhotoPolicy;

    expect($policy->viewAny(User::factory()->orga()->create()))->toBeTrue()
        ->and($policy->viewAny(User::factory()->create()))->toBeFalse()
        ->and($policy->viewAny(User::factory()->helper()->create()))->toBeFalse();
});

it('allows orga into the event-photos resource and shows a pending photo', function () {
    EventPhoto::factory()->create([
        'caption' => 'wartendes foto',
        'visibility' => PhotoVisibility::Pending,
    ]);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/event-photos')
        ->assertOk()
        // i18n gate: German visibility badge label (see lang/de/gallery.php).
        ->assertSee(trans('gallery.visibility.pending'))
        ->assertSee('wartendes foto');
});

it('calls ApprovePhoto via the Freigeben row action', function () {
    $photo = EventPhoto::factory()->create([
        'visibility' => PhotoVisibility::Pending,
    ]);

    $orga = User::factory()->orga()->create();
    $this->actingAs($orga);

    Livewire::test(ListEventPhotos::class)
        ->callTableAction('approve', $photo);

    expect($photo->refresh()->visibility)->toBe(PhotoVisibility::Approved)
        ->and($photo->reviewed_by)->toBe($orga->id);
});

it('calls RejectPhoto via the Ablehnen row action', function () {
    $photo = EventPhoto::factory()->create([
        'visibility' => PhotoVisibility::Pending,
    ]);

    $orga = User::factory()->orga()->create();
    $this->actingAs($orga);

    Livewire::test(ListEventPhotos::class)
        ->callTableAction('reject', $photo);

    expect($photo->refresh()->visibility)->toBe(PhotoVisibility::Rejected)
        ->and($photo->reviewed_by)->toBe($orga->id);
});

it('calls ToggleHighlight via the row action', function () {
    $photo = EventPhoto::factory()->create([
        'visibility' => PhotoVisibility::Approved,
        'is_highlight' => false,
    ]);

    $orga = User::factory()->orga()->create();
    $this->actingAs($orga);

    Livewire::test(ListEventPhotos::class)
        ->callTableAction('toggleHighlight', $photo);

    expect($photo->refresh()->is_highlight)->toBeTrue();
});
