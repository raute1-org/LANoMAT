<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Models\UploadReservation;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Testing\FakeFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

const TUS_HOOK_SECRET = 'tus-hook-secret-0123456789-abcdefghijklmnop';
const TUS_GB = 1024 * 1024 * 1024;

/** Switches the Files module to resumable mode with a fake file host. */
function resumable(): FakeFileStore
{
    config([
        'files.store' => 'host',
        'files.host.id' => 1,
        'files.host.url' => 'https://files.example.test',
        'files.host.secret' => 'abcdefghijklmnopqrstuvwxyzABCDEF_-0123456789',
        'files.tus.endpoint' => 'https://files.example.test/upload/',
        'files.tus.hook_secret' => TUS_HOOK_SECRET,
        'files.tus.data_dir' => '/data',
        'files.max_file_gb' => 100,
        'files.per_user_quota_mb' => 100 * 1024,
    ]);

    $store = new FakeFileStore('filehost');
    app(FileStores::class)->extend($store);

    return $store;
}

/** @return array{ticket: string, reservationId: int, chunkSize: int, endpoint: string} */
function ticketFor(Event $event, User $user, string $name = 'game.zip', int $size = 15 * TUS_GB): array
{
    $response = test()->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => $name, 'size' => $size]);
    $response->assertOk();

    return $response->json();
}

function hook(string $type, array $upload, ?string $secret = TUS_HOOK_SECRET)
{
    return test()->postJson(
        '/internal/files/tusd-hook',
        ['Type' => $type, 'Event' => ['Upload' => $upload, 'HTTPRequest' => ['Method' => 'POST', 'URI' => '', 'RemoteAddr' => '10.0.0.1', 'Header' => []]]],
        $secret === null ? [] : ['X-Tusd-Hook-Secret' => $secret],
    );
}

function preCreate(string $ticket, string $name = 'game.zip', int $size = 15 * TUS_GB, array $meta = [])
{
    return hook('pre-create', [
        'ID' => '',
        'Size' => $size,
        'SizeIsDeferred' => false,
        'Offset' => 0,
        'MetaData' => array_merge(['filename' => $name, 'filetype' => 'application/zip', 'ticket' => $ticket], $meta),
    ]);
}

// ---------------------------------------------------------------- tickets

it('issues a ticket and reserves the quota up front', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $ticket = ticketFor($event, $user);

    expect($ticket['endpoint'])->toBe('https://files.example.test/upload/')
        ->and($ticket['chunkSize'])->toBe(64 * 1024 * 1024)
        ->and($ticket['ticket'])->toBeString()->and(strlen($ticket['ticket']))->toBeGreaterThan(40);

    $reservation = UploadReservation::query()->firstOrFail();
    expect($reservation->user_id)->toBe($user->id)
        ->and($reservation->event_id)->toBe($event->id)
        ->and($reservation->size_bytes)->toBe(15 * TUS_GB)
        ->and($reservation->status)->toBe(UploadReservationStatus::Ticketed)
        // only the hash is stored, never the ticket
        ->and($reservation->ticket_hash)->toBe(hash('sha256', $ticket['ticket']));
});

it('refuses tickets when the app is not in resumable mode', function () {
    config(['files.store' => 'local']);
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/events/{$event->slug}/files/uploads", ['name' => 'a.zip', 'size' => 10])
        ->assertStatus(422)->assertJsonPath('code', 'uploads_unavailable');
});

it('requires a logged-in user for a ticket', function () {
    resumable();
    $event = Event::factory()->registration()->create();

    $this->postJson("/events/{$event->slug}/files/uploads", ['name' => 'a.zip', 'size' => 10])->assertUnauthorized();
});

it('refuses a file over the per-file maximum', function () {
    resumable();
    config(['files.max_file_gb' => 50]);
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/events/{$event->slug}/files/uploads", ['name' => 'huge.zip', 'size' => 51 * TUS_GB])
        ->assertStatus(422)->assertJsonPath('code', 'too_large');

    expect(UploadReservation::query()->count())->toBe(0);
});

it('refuses a blocked extension at ticket time', function () {
    resumable();
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/events/{$event->slug}/files/uploads", ['name' => 'page.HTML', 'size' => 100])
        ->assertStatus(422)->assertJsonPath('code', 'blocked_type');

    $this->actingAs(User::factory()->create())
        ->postJson("/events/{$event->slug}/files/uploads", ['name' => 'x.svg.', 'size' => 100, 'type' => 'image/svg+xml'])
        ->assertStatus(422)->assertJsonPath('code', 'blocked_type');
});

it('counts existing files and open reservations against the quota', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    SharedFile::factory()->for($event)->create(['user_id' => $user->id, 'size_bytes' => 40 * TUS_GB]);
    ticketFor($event, $user, 'first.zip', 40 * TUS_GB);

    // 40 + 40 reserved, 30 more would be 110 > 100
    $this->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'second.zip', 'size' => 30 * TUS_GB])
        ->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');

    // 20 fits exactly
    $this->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'third.zip', 'size' => 20 * TUS_GB])
        ->assertOk();

    // another user is unaffected
    $other = User::factory()->create();
    ticketFor($event, $other, 'mine.zip', 90 * TUS_GB);
});

it('does not reserve twice for the same file asked again (resume after reload)', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $first = ticketFor($event, $user, 'game.zip', 60 * TUS_GB);
    $second = ticketFor($event, $user, 'game.zip', 60 * TUS_GB);

    expect(UploadReservation::query()->count())->toBe(1)
        ->and($second['ticket'])->not->toBe($first['ticket']);

    // the rotated ticket replaces the old one
    preCreate($first['ticket'], 'game.zip', 60 * TUS_GB)->assertJsonPath('RejectUpload', true);
    preCreate($second['ticket'], 'game.zip', 60 * TUS_GB)->assertJsonMissingPath('RejectUpload');
});

it('rate limits ticket requests per user', function () {
    resumable();
    config(['files.tus.tickets_per_minute' => 2]);
    RateLimiter::clear('file-upload-tickets|1');
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => "f{$i}.zip", 'size' => 10])->assertOk();
    }

    $this->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'f3.zip', 'size' => 10])->assertStatus(429);
});

it('blocks the legacy form upload while resumable mode is on', function () {
    resumable();
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/files", [
        'file' => UploadedFile::fake()->create('maps.zip', 10, 'application/zip'),
    ])->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(SharedFile::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ hooks

it('rejects hook calls without or with a wrong secret', function () {
    resumable();

    hook('pre-create', ['Size' => 1], null)->assertUnauthorized();
    hook('pre-create', ['Size' => 1], 'wrong-secret-wrong-secret-wrong-secret-0000')->assertUnauthorized();
});

it('refuses every hook when no hook secret is configured', function () {
    resumable();
    config(['files.tus.hook_secret' => '']);

    hook('pre-create', ['Size' => 1], '')->assertUnauthorized();
});

it('accepts pre-create for a valid ticket and decides the id and path itself', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $ticket = ticketFor($event, $user);

    $response = preCreate($ticket['ticket'], meta: ['relativePath' => '../../etc/passwd']);

    $response->assertOk()->assertJsonMissingPath('RejectUpload');
    $reservation = UploadReservation::query()->firstOrFail();
    $id = $response->json('ChangeFileInfo.ID');

    expect($id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($response->json('ChangeFileInfo.Storage.Path'))->toBe("/data/event-{$event->id}/{$id}")
        // the ticket does not stay in tusd's .info file, the client's other metadata does not either
        ->and($response->json('ChangeFileInfo.MetaData'))->toBe(['filename' => 'game.zip', 'filetype' => 'application/zip'])
        ->and($reservation->status)->toBe(UploadReservationStatus::Uploading)
        ->and($reservation->upload_id)->toBe($id)
        ->and($reservation->stored_path)->toBe("event-{$event->id}/{$id}")
        ->and($reservation->ticket_hash)->toBeNull();
});

it('rejects a ticket that is replayed', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $ticket = ticketFor($event, User::factory()->create());

    preCreate($ticket['ticket'])->assertJsonMissingPath('RejectUpload');
    preCreate($ticket['ticket'])->assertJsonPath('RejectUpload', true)->assertJsonPath('HTTPResponse.StatusCode', 403);
});

it('rejects an unknown, an expired and a malformed ticket with distinct codes', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $ticket = ticketFor($event, User::factory()->create());

    $code = fn ($response) => json_decode($response->json('HTTPResponse.Body'), true)['code'];

    expect($code(preCreate('nope-nope-nope')))->toBe('ticket_invalid');
    expect($code(hook('pre-create', ['Size' => 15 * TUS_GB, 'MetaData' => ['filename' => 'game.zip']])))->toBe('ticket_invalid');

    $this->travel(11)->minutes();
    expect($code(preCreate($ticket['ticket'])))->toBe('ticket_expired');
});

it('binds a ticket to the declared name and size', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $code = fn ($response) => json_decode($response->json('HTTPResponse.Body'), true)['code'];

    $ticket = ticketFor($event, $user, 'game.zip', 15 * TUS_GB);
    expect($code(preCreate($ticket['ticket'], 'other.zip', 15 * TUS_GB)))->toBe('ticket_mismatch');

    $ticket = ticketFor($event, $user, 'game.zip', 15 * TUS_GB);
    expect($code(preCreate($ticket['ticket'], 'game.zip', 16 * TUS_GB)))->toBe('ticket_mismatch');

    // a deferred length would dodge the declared size
    $ticket = ticketFor($event, $user, 'game.zip', 15 * TUS_GB);
    $response = hook('pre-create', ['Size' => null, 'SizeIsDeferred' => true, 'MetaData' => ['filename' => 'game.zip', 'ticket' => $ticket['ticket']]]);
    expect($code($response))->toBe('ticket_mismatch');
});

it('finishes an upload into a pending shared file and converts the reservation', function () {
    $store = resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $ticket = ticketFor($event, $user);
    $id = preCreate($ticket['ticket'])->json('ChangeFileInfo.ID');

    hook('post-finish', ['ID' => $id, 'Size' => 15 * TUS_GB, 'Offset' => 15 * TUS_GB, 'MetaData' => ['filename' => 'game.zip']])->assertOk();

    $file = SharedFile::query()->firstOrFail();
    expect($file->visibility)->toBe(FileVisibility::Pending)
        ->and($file->disk)->toBe('filehost')
        ->and($file->path)->toBe("event-{$event->id}/{$id}")
        ->and($file->original_name)->toBe('game.zip')
        ->and($file->size_bytes)->toBe(15 * TUS_GB)
        ->and($file->user_id)->toBe($user->id)
        ->and($file->event_id)->toBe($event->id)
        ->and($file->mime)->toBe('application/octet-stream');

    $reservation = UploadReservation::query()->firstOrFail();
    expect($reservation->status)->toBe(UploadReservationStatus::Completed)
        ->and($reservation->shared_file_id)->toBe($file->id);

    // quota is counted once: the 15 TUS_GB are the file now, not the reservation
    ticketFor($event, $user, 'second.zip', 85 * TUS_GB);
    expect($store->files)->toBe([]);

    // a repeated post-finish (tusd retry) creates nothing new
    hook('post-finish', ['ID' => $id, 'Size' => 15 * TUS_GB, 'Offset' => 15 * TUS_GB, 'MetaData' => []])->assertOk();
    expect(SharedFile::query()->count())->toBe(1);
});

it('deletes the file and releases the quota when the finished size differs', function () {
    $store = resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $ticket = ticketFor($event, $user);
    $id = preCreate($ticket['ticket'])->json('ChangeFileInfo.ID');
    $path = "event-{$event->id}/{$id}";
    $store->files[$path] = 'partial';

    hook('post-finish', ['ID' => $id, 'Size' => 15 * TUS_GB, 'Offset' => 14 * TUS_GB, 'MetaData' => []])->assertOk();

    expect(SharedFile::query()->count())->toBe(0)
        ->and($store->files)->not->toHaveKey($path)
        ->and(UploadReservation::query()->firstOrFail()->status)->toBe(UploadReservationStatus::Released);
    ticketFor($event, $user, 'again.zip', 100 * TUS_GB);
});

it('re-checks the extension at finish time and removes the file', function () {
    $store = resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $ticket = ticketFor($event, $user, 'game.zip', 1000);
    $id = preCreate($ticket['ticket'], 'game.zip', 1000)->json('ChangeFileInfo.ID');
    $path = "event-{$event->id}/{$id}";
    $store->files[$path] = 'data';

    // the reservation row was tampered with (or the blocklist grew meanwhile)
    config(['files.blocked_extensions' => ['zip']]);
    hook('post-finish', ['ID' => $id, 'Size' => 1000, 'Offset' => 1000, 'MetaData' => []])->assertOk();

    expect(SharedFile::query()->count())->toBe(0)
        ->and($store->files)->not->toHaveKey($path);
});

it('ignores a post-finish for an upload it never authorised', function () {
    resumable();

    hook('post-finish', ['ID' => 'unknown-id', 'Size' => 10, 'Offset' => 10, 'MetaData' => ['filename' => 'evil.zip']])->assertOk();

    expect(SharedFile::query()->count())->toBe(0);
});

it('releases the reservation when the upload is terminated', function () {
    $store = resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $ticket = ticketFor($event, $user, 'game.zip', 90 * TUS_GB);
    $id = preCreate($ticket['ticket'], 'game.zip', 90 * TUS_GB)->json('ChangeFileInfo.ID');
    $store->files["event-{$event->id}/{$id}"] = 'partial';

    hook('post-terminate', ['ID' => $id, 'Size' => 90 * TUS_GB, 'Offset' => 1, 'MetaData' => []])->assertOk();

    expect(UploadReservation::query()->firstOrFail()->status)->toBe(UploadReservationStatus::Released)
        ->and($store->files)->toBe([]);
    ticketFor($event, $user, 'again.zip', 90 * TUS_GB);
});

it('never logs the ticket or the secret', function () {
    resumable();
    Log::spy();
    $event = Event::factory()->registration()->create();
    $ticket = ticketFor($event, User::factory()->create());

    preCreate($ticket['ticket']);
    preCreate($ticket['ticket']);
    hook('pre-create', ['Size' => 1], 'wrong-secret-wrong-secret-wrong-secret-0000');

    Log::shouldNotHaveReceived('warning', fn ($message, $context = []) => str_contains(json_encode([$message, $context]), $ticket['ticket']) || str_contains(json_encode([$message, $context]), TUS_HOOK_SECRET));
    expect(true)->toBeTrue();
});

// ------------------------------------------------------------------ sweep

it('sweeps abandoned uploads: deletes leftovers, frees the quota, is idempotent and keeps active ones', function () {
    $store = resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $abandoned = ticketFor($event, $user, 'abandoned.zip', 50 * TUS_GB);
    $abandonedId = preCreate($abandoned['ticket'], 'abandoned.zip', 50 * TUS_GB)->json('ChangeFileInfo.ID');
    $store->files["event-{$event->id}/{$abandonedId}"] = 'partial';
    $store->files["_tus/{$abandonedId}.info"] = '{}';

    $this->travel(25)->hours();

    $active = ticketFor($event, $user, 'active.zip', 40 * TUS_GB);
    $activeId = preCreate($active['ticket'], 'active.zip', 40 * TUS_GB)->json('ChangeFileInfo.ID');
    $store->files["event-{$event->id}/{$activeId}"] = 'partial';

    $this->artisan('lanomat:sweep-file-uploads')->assertSuccessful();

    expect($store->files)->toHaveKey("event-{$event->id}/{$activeId}")
        ->and($store->files)->not->toHaveKey("event-{$event->id}/{$abandonedId}")
        ->and($store->files)->not->toHaveKey("_tus/{$abandonedId}.info")
        ->and(UploadReservation::query()->where('upload_id', $abandonedId)->firstOrFail()->status)->toBe(UploadReservationStatus::Released)
        ->and(UploadReservation::query()->where('upload_id', $activeId)->firstOrFail()->status)->toBe(UploadReservationStatus::Uploading);

    $this->artisan('lanomat:sweep-file-uploads')->assertSuccessful();
    expect(UploadReservation::query()->count())->toBe(2);
});

it('keeps an abandoned upload for the next run when the file host is unreachable', function () {
    $store = resumable();
    $event = Event::factory()->registration()->create();
    $ticket = ticketFor($event, User::factory()->create(), 'a.zip', 1000);
    $id = preCreate($ticket['ticket'], 'a.zip', 1000)->json('ChangeFileInfo.ID');
    $this->travel(25)->hours();

    $store->unreachable = true;
    $this->artisan('lanomat:sweep-file-uploads')->assertSuccessful();
    expect(UploadReservation::query()->firstOrFail()->status)->toBe(UploadReservationStatus::Uploading);

    $store->unreachable = false;
    $store->files["event-{$event->id}/{$id}"] = 'x';
    $this->artisan('lanomat:sweep-file-uploads')->assertSuccessful();
    expect(UploadReservation::query()->firstOrFail()->status)->toBe(UploadReservationStatus::Released)
        ->and($store->files)->toBe([]);
});

it('expires an unused ticket and frees its quota', function () {
    resumable();
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    ticketFor($event, $user, 'a.zip', 100 * TUS_GB);

    $this->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'b.zip', 'size' => 1])
        ->assertStatus(422);

    $this->travel(11)->minutes();
    $this->artisan('lanomat:sweep-file-uploads')->assertSuccessful();

    $this->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => 'b.zip', 'size' => 1])
        ->assertOk();
});
