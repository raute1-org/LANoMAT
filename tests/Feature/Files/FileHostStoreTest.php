<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\ApproveSharedFile;
use App\Modules\Files\Actions\DeleteSharedFile;
use App\Modules\Files\Actions\RejectSharedFile;
use App\Modules\Files\Actions\UploadSharedFile;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const FILEHOST_SECRET = 'abcdefghijklmnopqrstuvwxyzABCDEF_-0123456789';

/** Points the Files module at a registered RemoteHost with a fake SFTP executor. */
function fileHost(): array
{
    $remote = fakeRemote();
    $host = RemoteHost::factory()->create();

    config([
        'files.store' => 'host',
        'files.host.id' => $host->id,
        'files.host.path' => '/data',
        'files.host.url' => 'https://files.example.test',
        'files.host.secret' => FILEHOST_SECRET,
        'files.host.url_ttl_seconds' => 300,
    ]);

    return [$remote, $host];
}

function hostFile(Event $event, User $owner, FileVisibility $visibility, string $name = 'mods.zip'): SharedFile
{
    return SharedFile::factory()->for($event)->create([
        'user_id' => $owner->id,
        'disk' => 'filehost',
        'path' => 'event-'.$event->id.'/0b7e1c9a-1111-4222-8333-444455556666',
        'original_name' => $name,
        'visibility' => $visibility,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
});

it('forwards the upload to the file host, keeps no copy on the app server and records the store', function () {
    [$remote, $host] = fileHost();
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => UploadedFile::fake()->createWithContent('setup.exe', 'MZ-binary-content'),
    ])->assertSessionDoesntHaveErrors();

    $shared = SharedFile::query()->firstOrFail();

    expect($shared->disk)->toBe('filehost')
        ->and($shared->visibility)->toBe(FileVisibility::Pending)
        ->and($remote->fileUploads)->toHaveCount(1)
        ->and($remote->fileUploads[0]['host_id'])->toBe($host->id)
        ->and($remote->fileUploads[0]['remote_path'])->toBe('/data/'.$shared->path)
        ->and($remote->fileUploads[0]['contents'])->toBe('MZ-binary-content')
        // The original name never reaches the host's file system.
        ->and($shared->path)->not->toContain('setup');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('leaves no row and no file when the file host is unreachable on upload', function () {
    [$remote] = fileHost();
    $remote->failWith = RemoteExecutionException::connectFailed();
    $event = Event::factory()->registration()->create();

    $response = $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/files", [
        'file' => UploadedFile::fake()->create('maps.zip', 10, 'application/zip'),
    ]);

    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $response->assertSessionHas('inertia.flash_data.toast.message', trans('files.errors.store_unavailable'));
    expect(trans('files.errors.store_unavailable'))->not->toBe('files.errors.store_unavailable')
        ->and(SharedFile::query()->count())->toBe(0)
        ->and($remote->remoteFiles)->toBe([]);
});

it('removes the file from the host again when the row cannot be written', function () {
    [$remote] = fileHost();
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    SharedFile::saving(fn () => throw new RuntimeException('db down'));

    try {
        app(UploadSharedFile::class)->handle($event, $uploader, UploadedFile::fake()->create('maps.zip', 10, 'application/zip'));
        $this->fail('expected the insert to fail');
    } catch (RuntimeException) {
        // expected
    } finally {
        SharedFile::flushEventListeners();
    }

    expect($remote->fileUploads)->toHaveCount(1)
        ->and($remote->remoteFiles)->toBe([])
        ->and(SharedFile::query()->count())->toBe(0);
});

it('redirects an approved download to a signed, expiring link on the file host without touching the host', function () {
    [$remote] = fileHost();
    $event = Event::factory()->registration()->create();
    $file = hostFile($event, User::factory()->create(), FileVisibility::Approved, 'My Mod Pack.zip');

    $response = $this->actingAs(User::factory()->create())->get("/files/{$file->id}/download");

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://files.example.test/dl/'.$file->path.'?expires=')
        ->and($location)->toContain('&md5=')
        ->and($location)->toContain('&n=My%20Mod%20Pack.zip')
        ->and($location)->not->toContain(FILEHOST_SECRET)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBeGreaterThan(time())->toBeLessThanOrEqual(time() + 300);

    // No SSH/SFTP in the download request: the app never streams or checks the bytes.
    expect($remote->commands)->toBe([])->and($remote->fileUploads)->toBe([]);
});

it('never issues a link for a pending file of someone else, a rejected file, or to guests', function () {
    fileHost();
    $event = Event::factory()->registration()->create();
    $owner = User::factory()->create();
    $pending = hostFile($event, $owner, FileVisibility::Pending);
    $rejected = hostFile($event, $owner, FileVisibility::Rejected);

    $this->actingAs(User::factory()->create())->get("/files/{$pending->id}/download")->assertForbidden();
    $this->actingAs($owner)->get("/files/{$rejected->id}/download")->assertForbidden();
    $this->actingAs(User::factory()->orga()->create())->get("/files/{$rejected->id}/download")->assertForbidden();

    auth()->logout();
    $guest = $this->get("/files/{$pending->id}/download");
    $guest->assertRedirect();
    expect($guest->headers->get('Location'))->not->toContain('files.example.test');
});

it('lets the uploader and orga download a pending file (orga must inspect before approving)', function () {
    fileHost();
    $event = Event::factory()->registration()->create();
    $owner = User::factory()->create();
    $pending = hostFile($event, $owner, FileVisibility::Pending);

    foreach ([$owner, User::factory()->orga()->create()] as $user) {
        $this->actingAs($user)->get("/files/{$pending->id}/download")
            ->assertRedirectContains('https://files.example.test/dl/');
    }
});

it('answers a friendly 503 instead of a 500 when the file host is misconfigured', function (array $override) {
    fileHost();
    config($override);
    $event = Event::factory()->registration()->create();
    $file = hostFile($event, User::factory()->create(), FileVisibility::Approved);

    $this->actingAs(User::factory()->create())->get("/files/{$file->id}/download")->assertStatus(503);
})->with([
    'secret too short' => [['files.host.secret' => 'short']],
    'no url' => [['files.host.url' => null]],
    'no host id' => [['files.host.id' => null]],
]);

it('keeps serving files uploaded before the store was switched', function () {
    config(['files.store' => 'host']);
    $event = Event::factory()->registration()->create();
    $file = SharedFile::factory()->for($event)->approved()->create();
    Storage::disk('local')->put($file->path, 'old bytes');

    $response = $this->actingAs(User::factory()->create())->get("/files/{$file->id}/download");

    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('deletes from the host before removing the row and keeps the row when the host is unreachable', function () {
    [$remote] = fileHost();
    $event = Event::factory()->registration()->create();
    $owner = User::factory()->create();
    $file = hostFile($event, $owner, FileVisibility::Approved);
    $remote->remoteFiles['1:/data/'.$file->path] = true;

    $remote->failWith = RemoteExecutionException::connectFailed();
    $response = $this->actingAs($owner)->delete("/files/{$file->id}");
    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
    expect(SharedFile::query()->count())->toBe(1);

    $remote->failWith = null;
    $this->actingAs($owner)->delete("/files/{$file->id}");

    expect(SharedFile::query()->count())->toBe(0)
        ->and($remote->deletes)->toHaveCount(1)
        ->and($remote->deletes[0]['remote_path'])->toBe('/data/'.$file->path);
});

it('removes a rejected file from the host, keeps the row and its quota, and cannot be approved again', function () {
    [$remote] = fileHost();
    $event = Event::factory()->registration()->create();
    $file = hostFile($event, User::factory()->create(), FileVisibility::Pending);
    $helper = User::factory()->helper()->create();

    app(RejectSharedFile::class)->handle($file, $helper);

    expect($file->refresh()->visibility)->toBe(FileVisibility::Rejected)
        ->and($remote->deletes)->toHaveCount(1)
        ->and($remote->deletes[0]['remote_path'])->toBe('/data/'.$file->path)
        ->and(SharedFile::query()->whereKey($file->id)->exists())->toBeTrue();

    expect(fn () => app(ApproveSharedFile::class)->handle($file, $helper))->toThrow(FileException::class);
    expect($file->refresh()->visibility)->toBe(FileVisibility::Rejected);
});

it('keeps a file pending when the host cannot be reached while rejecting', function () {
    [$remote] = fileHost();
    $remote->failWith = RemoteExecutionException::connectFailed();
    $event = Event::factory()->registration()->create();
    $file = hostFile($event, User::factory()->create(), FileVisibility::Pending);

    expect(fn () => app(RejectSharedFile::class)->handle($file, User::factory()->helper()->create()))
        ->toThrow(FileException::class);
    expect($file->refresh()->visibility)->toBe(FileVisibility::Pending);
});

it('treats an already-missing remote file as deleted', function () {
    [$remote] = fileHost();
    $event = Event::factory()->registration()->create();
    $file = hostFile($event, User::factory()->create(), FileVisibility::Approved);

    app(DeleteSharedFile::class)->handle($file);

    expect(SharedFile::query()->count())->toBe(0);
});

it('shows orga where each file is stored', function () {
    fileHost();
    $event = Event::factory()->registration()->create();
    hostFile($event, User::factory()->create(), FileVisibility::Approved, 'remote.zip');
    SharedFile::factory()->for($event)->create(['original_name' => 'lokal.zip']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/shared-files')
        ->assertOk()
        ->assertSee(trans('files.storage.filehost'))
        ->assertSee(trans('files.storage.local'));
});
