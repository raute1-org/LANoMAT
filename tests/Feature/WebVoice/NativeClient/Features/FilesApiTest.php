<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Models\UploadReservation;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Testing\FakeFileStore;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

const NATIVE_GB = 1024 * 1024 * 1024;
const NATIVE_MB = 1024 * 1024;
const NATIVE_FILE_SECRET = 'abcdefghijklmnopqrstuvwxyzABCDEF_-0123456789';

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    $this->user = User::factory()->create();
    $this->headers = nativeBearer($this->user);
    $this->event = Event::factory()->live()->create();
});

/** Resumable mode with a fake file host store (mirrors the browser ticket tests). */
function nativeResumable(): FakeFileStore
{
    config([
        'files.store' => 'host',
        'files.host.id' => 1,
        'files.host.url' => 'https://files.example.test',
        'files.host.secret' => NATIVE_FILE_SECRET,
        'files.tus.endpoint' => 'https://files.example.test/upload/',
        'files.tus.hook_secret' => 'tus-hook-secret-0123456789-abcdefghijklmnop',
        'files.tus.data_dir' => '/data',
        'files.max_file_gb' => 100,
        'files.per_user_quota_mb' => 100 * 1024,
    ]);

    $store = new FakeFileStore('filehost');
    app(FileStores::class)->extend($store);

    return $store;
}

function nativeFile(Event $event, User $owner, FileVisibility $visibility, string $name = 'mods.zip', int $size = 1000): SharedFile
{
    return SharedFile::factory()->for($event)->create([
        'user_id' => $owner->id,
        'disk' => 'filehost',
        'path' => 'event-'.$event->id.'/secret-storage-name-'.$name,
        'original_name' => $name,
        'size_bytes' => $size,
        'visibility' => $visibility,
    ]);
}

it('lists approved files plus the own pending ones, never rejected or foreign pending', function () {
    nativeResumable();
    $other = User::factory()->create();
    $approved = nativeFile($this->event, $other, FileVisibility::Approved, 'maps.zip');
    $ownPending = nativeFile($this->event, $this->user, FileVisibility::Pending, 'mine.zip');
    nativeFile($this->event, $other, FileVisibility::Pending, 'theirs.zip');
    nativeFile($this->event, $this->user, FileVisibility::Rejected, 'bad.zip');
    nativeFile(Event::factory()->live()->create(), $other, FileVisibility::Approved, 'elsewhere.zip');

    $response = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/files", $this->headers)->assertOk();

    $files = collect($response->json('files'))->keyBy('id');
    expect($files->keys()->sort()->values()->all())->toBe(collect([$approved->id, $ownPending->id])->sort()->values()->all())
        ->and($files[$approved->id])->toMatchArray(['name' => 'maps.zip', 'visibility' => 'approved', 'mine' => false, 'can_download' => true, 'can_delete' => false, 'uploader_name' => $other->name])
        ->and($files[$ownPending->id])->toMatchArray(['visibility' => 'pending', 'mine' => true, 'can_download' => true, 'can_delete' => true]);
    // The raw storage path never appears.
    expect(json_encode($response->json()))->not->toContain('secret-storage-name');
});

it('reports quota usage including open reservations, and the upload mode', function () {
    nativeResumable();
    config(['files.per_user_quota_mb' => 10, 'files.max_file_gb' => 2]);
    nativeFile($this->event, $this->user, FileVisibility::Approved, 'a.zip', 4 * NATIVE_MB);
    nativeFile($this->event, User::factory()->create(), FileVisibility::Approved, 'b.zip', 3 * NATIVE_MB);

    $quota = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/files/quota", $this->headers)->assertOk();

    expect($quota->json('quota'))->toBe(['used_bytes' => 4 * NATIVE_MB, 'cap_bytes' => 10 * NATIVE_MB, 'remaining_bytes' => 6 * NATIVE_MB])
        ->and($quota->json('upload'))->toBe(['resumable' => true, 'max_file_bytes' => 2 * NATIVE_GB]);

    $this->postJson("/api/voice-client/v1/events/{$this->event->slug}/files/uploads/ticket", ['name' => 'c.zip', 'size' => 5 * NATIVE_MB], $this->headers)->assertOk();

    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/files/quota", $this->headers)
        ->assertJsonPath('quota.used_bytes', 9 * NATIVE_MB)->assertJsonPath('quota.remaining_bytes', NATIVE_MB);
});

it('reports resumable uploads as off when the app is not in file-host mode', function () {
    config(['files.store' => 'local']);

    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/files", $this->headers)
        ->assertOk()->assertJsonPath('upload.resumable', false);
});

it('hides the files and the quota of a draft event', function () {
    $draft = Event::factory()->draft()->create();

    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/files", $this->headers)->assertNotFound();
    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/files/quota", $this->headers)->assertNotFound();
    $this->postJson("/api/voice-client/v1/events/{$draft->slug}/files/uploads/ticket", ['name' => 'a.zip', 'size' => 5], $this->headers)->assertNotFound();
});

it('returns the signed file host URL for an approved file and never the raw path', function () {
    $remote = fakeRemote();
    $host = RemoteHost::factory()->create();
    config([
        'files.store' => 'host', 'files.host.id' => $host->id, 'files.host.path' => '/data',
        'files.host.url' => 'https://files.example.test', 'files.host.secret' => NATIVE_FILE_SECRET,
        'files.host.url_ttl_seconds' => 300,
    ]);
    $file = nativeFile($this->event, User::factory()->create(), FileVisibility::Approved, 'My Mod Pack.zip');

    $response = $this->getJson("/api/voice-client/v1/files/{$file->id}/download", $this->headers)->assertOk();

    $url = $response->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($url)->toStartWith('https://files.example.test/dl/'.$file->path.'?expires=')
        ->and($url)->toContain('&md5=')->toContain('&n=My%20Mod%20Pack.zip')
        ->and($url)->not->toContain(NATIVE_FILE_SECRET)
        ->and($response->json('name'))->toBe('My Mod Pack.zip')
        ->and($response->json('size_bytes'))->toBe(1000)
        ->and($response->json('expires_at'))->toBe(date(DATE_ATOM, (int) $query['expires']))
        ->and((int) $query['expires'])->toBeGreaterThan(time())->toBeLessThanOrEqual(time() + 300)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($remote->commands)->toBe([]);
});

it('keeps the moderation gate on downloads: pending only for the owner and orga, rejected for nobody', function () {
    nativeResumable();
    $owner = User::factory()->create();
    $pending = nativeFile($this->event, $owner, FileVisibility::Pending);
    $rejected = nativeFile($this->event, $owner, FileVisibility::Rejected);

    $this->getJson("/api/voice-client/v1/files/{$pending->id}/download", $this->headers)->assertForbidden();
    $this->getJson("/api/voice-client/v1/files/{$pending->id}/download", nativeBearer($owner))->assertOk()->assertJsonStructure(['url']);
    $this->getJson("/api/voice-client/v1/files/{$pending->id}/download", nativeBearer(User::factory()->orga()->create()))->assertOk();
    $this->getJson("/api/voice-client/v1/files/{$rejected->id}/download", nativeBearer($owner))->assertForbidden();
    $this->getJson("/api/voice-client/v1/files/{$rejected->id}/download", nativeBearer(User::factory()->orga()->create()))->assertForbidden();
});

it('answers 404 for downloads and deletes in a draft event', function () {
    nativeResumable();
    $draft = Event::factory()->draft()->create();
    $file = nativeFile($draft, $this->user, FileVisibility::Approved);

    $this->getJson("/api/voice-client/v1/files/{$file->id}/download", $this->headers)->assertNotFound();
    $this->deleteJson("/api/voice-client/v1/files/{$file->id}", [], $this->headers)->assertNotFound();
});

it('answers a friendly 503 when the store cannot sign', function () {
    $host = RemoteHost::factory()->create();
    config(['files.store' => 'host', 'files.host.id' => $host->id, 'files.host.secret' => 'short', 'files.host.url' => null]);
    fakeRemote();
    $file = nativeFile($this->event, User::factory()->create(), FileVisibility::Approved);

    $this->getJson("/api/voice-client/v1/files/{$file->id}/download", $this->headers)->assertStatus(503);
});

it('deletes the own file through DeleteSharedFile and refuses foreign files', function () {
    nativeResumable();
    $mine = nativeFile($this->event, $this->user, FileVisibility::Approved);
    $theirs = nativeFile($this->event, User::factory()->create(), FileVisibility::Approved, 'theirs.zip');

    $this->deleteJson("/api/voice-client/v1/files/{$theirs->id}", [], $this->headers)->assertForbidden();
    expect(SharedFile::query()->whereKey($theirs->id)->exists())->toBeTrue();

    $this->deleteJson("/api/voice-client/v1/files/{$mine->id}", [], $this->headers)->assertNoContent();
    expect(SharedFile::query()->whereKey($mine->id)->exists())->toBeFalse();

    $this->deleteJson('/api/voice-client/v1/files/999999', [], $this->headers)->assertNotFound();
});

it('lets orga delete any file', function () {
    nativeResumable();
    $file = nativeFile($this->event, User::factory()->create(), FileVisibility::Approved);

    $this->deleteJson("/api/voice-client/v1/files/{$file->id}", [], nativeBearer(User::factory()->orga()->create()))->assertNoContent();
});

it('issues the same ticket payload as the browser route and reserves the quota', function () {
    nativeResumable();

    $response = $this->postJson("/api/voice-client/v1/events/{$this->event->slug}/files/uploads/ticket", ['name' => 'game.zip', 'size' => 15 * NATIVE_GB], $this->headers)->assertOk();

    expect($response->json())->toHaveKeys(['endpoint', 'ticket', 'chunkSize', 'expiresIn', 'name', 'reservationId'])
        ->and($response->json('endpoint'))->toBe('https://files.example.test/upload/')
        ->and($response->json('chunkSize'))->toBe(64 * NATIVE_MB)
        ->and($response->json('name'))->toBe('game.zip')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $reservation = UploadReservation::query()->findOrFail($response->json('reservationId'));
    expect($reservation->user_id)->toBe($this->user->id)
        ->and($reservation->event_id)->toBe($this->event->id)
        ->and($reservation->size_bytes)->toBe(15 * NATIVE_GB)
        ->and($reservation->ticket_hash)->toBe(hash('sha256', $response->json('ticket')));

    // The browser route returns the same shape.
    $browser = $this->actingAs($this->user)->postJson("/events/{$this->event->slug}/files/uploads", ['name' => 'other.zip', 'size' => 1000])->assertOk();
    expect(array_keys($browser->json()))->toBe(array_keys($response->json()));
});

it('refuses tickets over the per-file maximum, over quota, for blocked types and when not resumable', function () {
    nativeResumable();
    config(['files.max_file_gb' => 50, 'files.per_user_quota_mb' => 20 * 1024]);
    $url = "/api/voice-client/v1/events/{$this->event->slug}/files/uploads/ticket";

    $this->postJson($url, ['name' => 'huge.zip', 'size' => 51 * NATIVE_GB], $this->headers)->assertStatus(422)->assertJsonPath('code', 'too_large');
    $this->postJson($url, ['name' => 'big.zip', 'size' => 15 * NATIVE_GB], $this->headers)->assertOk();
    $this->postJson($url, ['name' => 'big2.zip', 'size' => 6 * NATIVE_GB], $this->headers)->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');
    $this->postJson($url, ['name' => 'page.HTML', 'size' => 100], $this->headers)->assertStatus(422)->assertJsonPath('code', 'blocked_type');
    expect(UploadReservation::query()->count())->toBe(1);

    config(['files.store' => 'local']);
    $this->postJson($url, ['name' => 'a.zip', 'size' => 10], $this->headers)->assertStatus(422)->assertJsonPath('code', 'uploads_unavailable');
});

it('counts existing files towards the ticket quota at the exact boundary', function () {
    nativeResumable();
    config(['files.per_user_quota_mb' => 10]);
    nativeFile($this->event, $this->user, FileVisibility::Pending, 'a.zip', 4 * NATIVE_MB);
    $url = "/api/voice-client/v1/events/{$this->event->slug}/files/uploads/ticket";

    $this->postJson($url, ['name' => 'b.zip', 'size' => 6 * NATIVE_MB + 1], $this->headers)->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');
    $this->postJson($url, ['name' => 'b.zip', 'size' => 6 * NATIVE_MB], $this->headers)->assertOk();
});

it('validates the ticket request body', function () {
    nativeResumable();
    $url = "/api/voice-client/v1/events/{$this->event->slug}/files/uploads/ticket";

    $this->postJson($url, [], $this->headers)->assertStatus(422)->assertJsonValidationErrors(['name', 'size']);
    $this->postJson($url, ['name' => 'a.zip', 'size' => 0], $this->headers)->assertStatus(422)->assertJsonValidationErrors('size');
    $this->postJson($url, ['name' => 'a.zip', 'size' => 'big'], $this->headers)->assertStatus(422)->assertJsonValidationErrors('size');
});

it('never takes the user from the body', function () {
    nativeResumable();
    $other = User::factory()->create();

    $response = $this->postJson("/api/voice-client/v1/events/{$this->event->slug}/files/uploads/ticket", ['name' => 'a.zip', 'size' => 100, 'user_id' => $other->id], $this->headers)->assertOk();

    expect(UploadReservation::query()->findOrFail($response->json('reservationId'))->user_id)->toBe($this->user->id);
});
