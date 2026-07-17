<?php

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Filament\Resources\SharedFiles\Pages\ListSharedFiles;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Policies\SharedFilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('forbids participants from the shared-files resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/shared-files')
        ->assertForbidden();
});

it('enforces viewAny=isOrga on SharedFilePolicy directly, independent of the panel gate', function () {
    $policy = new SharedFilePolicy;

    expect($policy->viewAny(User::factory()->orga()->create()))->toBeTrue()
        ->and($policy->viewAny(User::factory()->create()))->toBeFalse()
        ->and($policy->viewAny(User::factory()->helper()->create()))->toBeFalse();
});

it('allows orga into the shared-files resource and shows a pending file', function () {
    SharedFile::factory()->create([
        'original_name' => 'wartend.zip',
        'visibility' => FileVisibility::Pending,
    ]);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/shared-files')
        ->assertOk()
        // i18n gate: German visibility badge label (see lang/de/files.php).
        ->assertSee(trans('files.visibility.pending'))
        ->assertSee('wartend.zip');
});

it('calls ApproveSharedFile via the Freigeben row action', function () {
    $file = SharedFile::factory()->create([
        'visibility' => FileVisibility::Pending,
    ]);

    $orga = User::factory()->orga()->create();
    $this->actingAs($orga);

    Livewire::test(ListSharedFiles::class)
        ->callTableAction('approve', $file);

    expect($file->refresh()->visibility)->toBe(FileVisibility::Approved)
        ->and($file->reviewed_by)->toBe($orga->id);
});

it('calls RejectSharedFile via the Ablehnen row action', function () {
    $file = SharedFile::factory()->create([
        'visibility' => FileVisibility::Pending,
    ]);

    $orga = User::factory()->orga()->create();
    $this->actingAs($orga);

    Livewire::test(ListSharedFiles::class)
        ->callTableAction('reject', $file);

    expect($file->refresh()->visibility)->toBe(FileVisibility::Rejected)
        ->and($file->reviewed_by)->toBe($orga->id);
});
