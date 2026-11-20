<?php

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\Files\Testing\FakeFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const UQ_GB = 1024 * 1024 * 1024;

beforeEach(function () {
    config([
        'files.store' => 'host',
        'files.host.id' => 1,
        'files.host.url' => 'https://files.example.test',
        'files.host.secret' => 'abcdefghijklmnopqrstuvwxyzABCDEF_-0123456789',
        'files.tus.endpoint' => 'https://files.example.test/upload/',
        'files.tus.hook_secret' => 'tus-hook-secret-0123456789-abcdefghijklmnop',
        'files.tus.data_dir' => '/data',
        'files.max_file_gb' => 2000,
        'files.per_user_quota_mb' => 100 * 1024,
    ]);
    app(FileStores::class)->extend(new FakeFileStore('filehost'));
});

it('uses the configured default cap when a user has no override', function () {
    $user = User::factory()->create();

    expect($user->files_quota_mb)->toBeNull()
        ->and(UploadQuota::capBytes())->toBe(100 * UQ_GB)
        ->and(UploadQuota::capBytes($user))->toBe(100 * UQ_GB);
});

it('uses the per-user override instead of the default', function () {
    $user = User::factory()->create();
    $user->forceFill(['files_quota_mb' => 1024 * 1024])->save();

    expect(UploadQuota::capBytes($user->fresh()))->toBe(1024 * UQ_GB)
        ->and(UploadQuota::capBytes())->toBe(100 * UQ_GB);
});

it('lets a user with an override reserve more than the default cap, and keeps others at the default', function () {
    $event = Event::factory()->registration()->create();
    $big = User::factory()->create();
    $big->forceFill(['files_quota_mb' => 1024 * 1024])->save();
    $normal = User::factory()->create();

    $this->actingAs($normal)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'huge.zip', 'size' => 150 * UQ_GB])
        ->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');

    $this->actingAs($big)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'huge.zip', 'size' => 150 * UQ_GB])
        ->assertOk();
});

it('shows the override as the cap on the page', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $user->forceFill(['files_quota_mb' => 1024 * 1024])->save();
    SharedFile::factory()->for($event)->create(['user_id' => $user->id, 'size_bytes' => 5 * UQ_GB]);

    $this->actingAs($user)->get("/events/{$event->slug}/files")
        ->assertInertia(fn ($page) => $page
            ->where('quota.usedBytes', 5 * UQ_GB)
            ->where('quota.capBytes', 1024 * UQ_GB));
});

it('is not mass assignable', function () {
    $user = User::factory()->create();
    $user->fill(['files_quota_mb' => 999])->save();

    expect($user->fresh()->files_quota_mb)->toBeNull();
});

it('lets an admin set and clear a user quota on the user page', function () {
    $user = User::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('filesQuota', ['quota_gb' => 1024])
        ->assertHasNoActionErrors();

    expect($user->fresh()->files_quota_mb)->toBe(1024 * 1024);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('filesQuota', ['quota_gb' => null])
        ->assertHasNoActionErrors();

    expect($user->fresh()->files_quota_mb)->toBeNull();
});

it('hides the quota action from orga', function () {
    $user = User::factory()->create();

    Livewire::actingAs(User::factory()->orga()->create())
        ->test(ViewUser::class, ['record' => $user->getKey()])
        ->assertActionHidden('filesQuota');
});
