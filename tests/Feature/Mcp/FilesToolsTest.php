<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\FileDownloadTool;
use App\Modules\Mcp\Tools\FilesListTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists approved files plus the caller\'s own pending uploads', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $event = Event::factory()->live()->create();

    SharedFile::factory()->approved()->create(['event_id' => $event->id, 'original_name' => 'ut2004.iso']);
    SharedFile::factory()->create(['event_id' => $event->id, 'user_id' => $user->id, 'original_name' => 'mine.zip']);
    SharedFile::factory()->create(['event_id' => $event->id, 'user_id' => $other->id, 'original_name' => 'theirs.zip']);
    SharedFile::factory()->rejected()->create(['event_id' => $event->id, 'original_name' => 'rejected.zip']);

    LanomatServer::actingAs($user);

    $response = LanomatServer::tool(FilesListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertSee('ut2004.iso')
        ->assertSee('mine.zip')
        ->assertDontSee('theirs.zip')
        ->assertDontSee('rejected.zip');

    $response->assertStructuredContentCount(2, 'files');
});

it('filters the file list by filename', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    SharedFile::factory()->approved()->create(['event_id' => $event->id, 'original_name' => 'UT2004-DVD1.iso']);
    SharedFile::factory()->approved()->create(['event_id' => $event->id, 'original_name' => 'avatar.vrm']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(FilesListTool::class, ['event_slug' => $event->slug, 'query' => 'ut2004'])
        ->assertOk()
        ->assertStructuredContentCount(1, 'files')
        ->assertStructuredContentPath('files.0.name', 'UT2004-DVD1.iso');
});

it('404s the file list of a draft event', function () {
    $user = User::factory()->create();
    $draft = Event::factory()->draft()->create();

    LanomatServer::actingAs($user);

    LanomatServer::tool(FilesListTool::class, ['event_slug' => $draft->slug])
        ->assertHasErrors(['Not found']);
});

it('returns a bearer download URL for an approved file', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $file = SharedFile::factory()->approved()->create(['event_id' => $event->id, 'size_bytes' => 4096]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(FileDownloadTool::class, ['file_id' => $file->id])
        ->assertOk()
        ->assertStructuredContentPath('file.id', $file->id)
        ->assertStructuredContentPath('file.size_bytes', 4096)
        ->assertStructuredContentPath('file.download_url', fn ($url): bool => is_string($url) && str_contains($url, '/api/v1/files/'))
        ->assertStructuredContentPath('file.auth', 'Authorization: Bearer <LANoMAT token>');
});

it('refuses a rejected file and another user\'s pending file (policy 403)', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $event = Event::factory()->live()->create();
    $rejected = SharedFile::factory()->rejected()->create(['event_id' => $event->id]);
    $foreignPending = SharedFile::factory()->create(['event_id' => $event->id, 'user_id' => $other->id]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(FileDownloadTool::class, ['file_id' => $rejected->id])
        ->assertHasErrors(['Forbidden']);

    LanomatServer::tool(FileDownloadTool::class, ['file_id' => $foreignPending->id])
        ->assertHasErrors(['Forbidden']);
});

it('allows the uploader to fetch their own pending file', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $pending = SharedFile::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(FileDownloadTool::class, ['file_id' => $pending->id])
        ->assertOk()
        ->assertStructuredContentPath('file.id', $pending->id);
});
