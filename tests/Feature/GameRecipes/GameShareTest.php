<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    // The share page's gameUrl depends on the lineup: an unrestricted lineup
    // is the back-compat path and keeps this file about sharing, not curation.
    Config::set('launcher.lineup', '');
});

/** An archive the participant uploaded to the current event. */
function gameShareFile(User $user, Event $event, string $name = 'tiny-racer.zip', array $overrides = []): SharedFile
{
    return SharedFile::factory()->approved()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'original_name' => $name,
        ...$overrides,
    ]);
}

/**
 * The share form body.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gameSharePayload(SharedFile $file, array $overrides = []): array
{
    return [
        'title' => 'Tiny Racer',
        'shared_file_id' => $file->id,
        'platforms' => ['windows'],
        'executable' => 'bin/TinyRacer.exe',
        'multiplayer' => true,
        ...$overrides,
    ];
}

it('sends a guest to the login from the share page and the share action', function () {
    $this->get('/games/share')->assertRedirect(route('login'));
    $this->post('/games/share', [])->assertRedirect(route('login'));
});

it('renders the share page with the current event, upload surfaces and the viewers own files', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();

    $approved = gameShareFile($user, $event, 'tiny-racer.zip');
    $pending = SharedFile::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'original_name' => 'tiny-racer-notes.txt',
    ]);
    // Not on the page: rejected, foreign and another event.
    SharedFile::factory()->rejected()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'original_name' => 'old-build.zip',
    ]);
    SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'foreign.zip',
    ]);
    SharedFile::factory()->approved()->create([
        'user_id' => $user->id,
        'event_id' => Event::factory()->finished()->create()->id,
        'original_name' => 'last-year.zip',
    ]);

    $draft = GameRecipe::factory()->contributedBy($user)->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);
    $draft->files()->create([
        'role' => 'base',
        'label' => 'Tiny Racer (portable archive)',
        'match_patterns' => ['tiny-racer.zip'],
        'required' => true,
        'sort' => 0,
        'shared_file_id' => $approved->id,
    ]);

    $published = GameRecipe::factory()->contributedBy($user)->published()->create([
        'slug' => 'zulu-racer',
        'title' => 'Zulu Racer',
    ]);

    $this->actingAs($user)
        ->get('/games/share')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Share')
            ->where('event.name', $event->name)
            ->where('event.slug', $event->slug)
            ->where('upload.mode', 'form')
            ->where('upload.ticketUrl', null)
            ->where('upload.storeUrl', '/events/'.$event->slug.'/files')
            ->where('upload.maxFileBytes', (int) config('files.max_upload_mb') * 1024 * 1024)
            ->where('quota.usedBytes', UploadQuota::usedBytes($event->id, $user->id))
            ->where('quota.capBytes', UploadQuota::capBytes($user))
            ->has('files', 2)
            // Newest first: the pending text file was created last.
            ->where('files.0.name', $pending->original_name)
            ->where('files.0.visibility', 'pending')
            ->where('files.0.usable', false)
            ->where('files.1.name', 'tiny-racer.zip')
            ->where('files.1.visibility', 'approved')
            ->where('files.1.usable', true)
            ->where('selectedFileId', null)
            ->has('shares', 2)
            ->where('shares.0.slug', 'zulu-racer')
            ->where('shares.0.status', 'published')
            ->where('shares.0.statusLabel', 'Veröffentlicht')
            ->where('shares.0.gameUrl', '/games/zulu-racer')
            ->where('shares.0.file', null)
            ->where('shares.0.pullRequest', null)
            ->where('shares.1.slug', 'tiny-racer')
            ->where('shares.1.status', 'draft')
            ->where('shares.1.statusLabel', 'Entwurf')
            ->where('shares.1.inLineup', false)
            ->where('shares.1.gameUrl', null)
            ->where('shares.1.file.name', 'tiny-racer.zip')
            ->where('shares.1.file.visibility', 'approved')
            ->where('shares.1.updatedAt', $draft->updated_at->toIso8601String())
            ->where('limits.titleMax', 120)
            ->where('limits.summaryMax', 200)
            ->where('limits.executableMax', 200)
            ->where('limits.archiveExtensions', ['.zip', '.tar.bz2'])
            ->has('uploadLabels.up_pick'));

    // The published row exists so the page can show both states; the fixture
    // is only here to keep the assertions above honest.
    expect($published->fresh()->status)->toBe(RecipeStatus::Published);
});

it('renders without a current event and without upload surfaces', function () {
    $user = User::factory()->create();
    $finished = Event::factory()->finished()->create();
    SharedFile::factory()->approved()->create([
        'user_id' => $user->id,
        'event_id' => $finished->id,
        'original_name' => 'old.zip',
    ]);

    $this->actingAs($user)
        ->get('/games/share')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Share')
            ->where('event', null)
            ->where('upload', null)
            ->where('quota', null)
            ->has('files', 0)
            ->has('shares', 0));
});

it('preselects only an own file that the page lists', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $mine = gameShareFile($user, $event);
    $rejected = SharedFile::factory()->rejected()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'original_name' => 'old.zip',
    ]);
    $foreign = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'foreign.zip',
    ]);

    $this->actingAs($user)->get('/games/share?file='.$mine->id)
        ->assertInertia(fn ($page) => $page->where('selectedFileId', $mine->id));

    $this->actingAs($user)->get('/games/share?file='.$rejected->id)
        ->assertInertia(fn ($page) => $page->where('selectedFileId', null));

    $this->actingAs($user)->get('/games/share?file='.$foreign->id)
        ->assertInertia(fn ($page) => $page->where('selectedFileId', null));

    $this->actingAs($user)->get('/games/share?file=not-a-number')
        ->assertInertia(fn ($page) => $page->where('selectedFileId', null));
});

it('creates a draft portable recipe pinned to the shared archive', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    $file = gameShareFile($user, $event, 'Tiny Racer.zip', ['size_bytes' => 987_654]);
    $file->forceFill(['sha256' => str_repeat('b', 64)])->save();

    $response = $this->actingAs($user)->post('/games/share', gameSharePayload($file, [
        'summary' => 'Kleiner Racer für die LAN.',
        'version' => '1.4',
        'players' => '2-8',
        'homepage' => 'https://example.test/tiny-racer',
    ]));

    $response->assertRedirect(route('games.share'))->assertSessionHas('success');

    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->firstOrFail();
    $pinned = $recipe->files()->first();

    expect($recipe->status)->toBe(RecipeStatus::Draft)
        ->and($recipe->source)->toBe(GameRecipe::SOURCE_CONTRIBUTION)
        ->and($recipe->source_ref)->toBe('web')
        ->and($recipe->contributor_user_id)->toBe($user->id)
        ->and($recipe->metadata['portable'])->toBeTrue()
        ->and($recipe->metadata['multiplayer'])->toBeTrue()
        ->and($recipe->metadata['players'])->toBe('2-8')
        ->and($recipe->metadata['tags'])->toBe(['shared'])
        ->and($recipe->summary)->toBe('Kleiner Racer für die LAN.')
        ->and($recipe->version)->toBe('1.4')
        ->and($recipe->homepage)->toBe('https://example.test/tiny-racer')
        ->and($recipe->requirements)->toHaveCount(1)
        ->and($recipe->requirements[0]['role'])->toBe('base')
        ->and($recipe->requirements[0]['required'])->toBeTrue()
        ->and($recipe->requirements[0]['match'])->toBe(['Tiny Racer.zip'])
        ->and($recipe->requirements[0])->not->toHaveKey('shared_file_id')
        ->and($pinned?->shared_file_id)->toBe($file->id)
        ->and($pinned?->role->value)->toBe('base')
        ->and($pinned?->sha256)->toBe(str_repeat('b', 64));

    expect(app(LauncherSupport::class)->supports($recipe->refresh()))->toBeTrue();

    $steps = $recipe->steps ?? [];
    expect(count($steps))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6);
    expect(array_column($steps, 'id'))->toBe(['get-archive', 'unpack-windows', 'check-install']);

    foreach ($steps as $step) {
        expect(trim((string) $step['detail']))->not->toBe('');

        foreach ($step['commands'] ?? [] as $command) {
            expect($command['run'])->not->toContain('&&');
        }
    }

    $skill = (string) $recipe->skill_markdown;
    $lines = substr_count($skill, "\n") + 1;

    expect($lines)->toBeGreaterThanOrEqual(60)
        ->and($lines)->toBeLessThanOrEqual(150)
        ->and($skill)->toStartWith("---\nname: tiny-racer\ndescription: \"")
        ->and($skill)->toContain('needs verification on the LAN');
});

it('accepts a backslash executable path and stores the forward-slash form', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $file = gameShareFile($user, $event);

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file, ['executable' => 'bin\\TinyRacer.exe']))
        ->assertRedirect(route('games.share'));

    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->firstOrFail();

    expect($recipe->launchTargets()[0]['command'])
        ->toBe('Start-Process -FilePath "{{target}}/bin/TinyRacer.exe" -WorkingDirectory "{{target}}"');
});

it('builds the launch targets the selected platform needs', function (array $overrides, array $targetIds, array $commands) {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $file = gameShareFile($user, $event);

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file, $overrides))
        ->assertRedirect(route('games.share'));

    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->firstOrFail();
    $targets = $recipe->launchTargets();

    expect(array_column($targets, 'id'))->toBe($targetIds)
        ->and(array_column($targets, 'command'))->toBe($commands)
        ->and(array_column($targets, 'os'))->toBe($overrides['platforms']);
})->with([
    'windows only' => [
        ['platforms' => ['windows']],
        ['windows-direct'],
        ['Start-Process -FilePath "{{target}}/bin/TinyRacer.exe" -WorkingDirectory "{{target}}"'],
    ],
    'linux through wine' => [
        ['platforms' => ['linux'], 'linux_runner' => 'wine'],
        ['linux-wine'],
        ['wine "{{target}}/bin/TinyRacer.exe"'],
    ],
    'linux native' => [
        ['platforms' => ['linux'], 'linux_runner' => 'native', 'linux_executable' => 'bin/tiny-racer'],
        ['linux-native'],
        ['"{{target}}/bin/tiny-racer"'],
    ],
    'both platforms' => [
        ['platforms' => ['windows', 'linux'], 'linux_runner' => 'wine'],
        ['windows-direct', 'linux-wine'],
        [
            'Start-Process -FilePath "{{target}}/bin/TinyRacer.exe" -WorkingDirectory "{{target}}"',
            'wine "{{target}}/bin/TinyRacer.exe"',
        ],
    ],
]);

it('refuses a file the viewer cannot see, a rejected file and a non-archive', function (string $case) {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();

    $file = match ($case) {
        'foreign pending' => SharedFile::factory()->create(['event_id' => $event->id, 'original_name' => 'foreign.zip']),
        'rejected own' => SharedFile::factory()->rejected()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'original_name' => 'rejected.zip',
        ]),
        'seven zip' => gameShareFile($user, $event, 'tiny-racer.7z'),
        default => throw new RuntimeException('unknown case'),
    };

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file))
        ->assertSessionHasErrors('shared_file_id');

    expect(GameRecipe::query()->count())->toBe(0);
})->with(['foreign pending', 'rejected own', 'seven zip']);

it('refuses unsafe executable paths', function (string $path) {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $file = gameShareFile($user, $event);

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file, ['executable' => $path]))
        ->assertSessionHasErrors('executable');

    expect(GameRecipe::query()->count())->toBe(0);
})->with([
    'traversal' => '../escape.exe',
    'backslash traversal' => 'bin\\..\\escape.exe',
    'absolute' => '/bin/TinyRacer.exe',
    'drive letter' => 'C:\\Games\\TinyRacer.exe',
    'double quote' => 'bin/Ga"me.exe',
    'single quote' => "bin/Ga'me.exe",
    'backtick' => 'bin/Ga`me.exe',
    'dollar' => 'bin/Ga$me.exe',
    'semicolon' => 'bin/Ga;me.exe',
    'pipe' => 'bin/Ga|me.exe',
    'ampersand' => 'bin/Ga&me.exe',
    'less than' => 'bin/Ga<me.exe',
    'greater than' => 'bin/Ga>me.exe',
    'asterisk' => 'bin/Ga*me.exe',
    'question mark' => 'bin/Ga?me.exe',
    'control character' => "bin/Ga\tme.exe",
    'current directory' => 'bin/./TinyRacer.exe',
]);

it('refuses a title that is taken, reserved or has no slug', function (string $title, ?RecipeStatus $existing) {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $file = gameShareFile($user, $event);

    if ($existing !== null) {
        GameRecipe::factory()
            ->when($existing === RecipeStatus::Draft, fn ($factory) => $factory->contributedBy(User::factory()->create()))
            ->when($existing === RecipeStatus::Published, fn ($factory) => $factory->published())
            ->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);
    }

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file, ['title' => $title]))
        ->assertSessionHasErrors('title');

    expect(GameRecipe::query()->count())->toBe($existing === null ? 0 : 1);
})->with([
    'published recipe owns the slug' => ['Tiny Racer', RecipeStatus::Published],
    'a foreign draft owns the slug' => ['Tiny Racer', RecipeStatus::Draft],
    'reserved share page' => ['Share', null],
    'no usable slug' => ['***', null],
]);

it('requires a linux runner and a native executable for a linux share', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $file = gameShareFile($user, $event);

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file, ['platforms' => ['linux']]))
        ->assertSessionHasErrors('linux_runner');

    $this->actingAs($user)
        ->post('/games/share', gameSharePayload($file, ['platforms' => ['linux'], 'linux_runner' => 'native']))
        ->assertSessionHasErrors('linux_executable');

    expect(GameRecipe::query()->count())->toBe(0);
});

it('updates the owners draft when the same title is shared again', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $first = gameShareFile($user, $event, 'tiny-racer-v1.zip');
    $second = gameShareFile($user, $event, 'tiny-racer-v2.zip');

    $this->actingAs($user)->post('/games/share', gameSharePayload($first, ['summary' => 'First cut.']));
    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->firstOrFail();

    $this->actingAs($user)->post('/games/share', gameSharePayload($second, ['summary' => 'Second cut.']));

    expect(GameRecipe::query()->count())->toBe(1);

    $recipe->refresh();

    expect($recipe->summary)->toBe('Second cut.')
        ->and($recipe->status)->toBe(RecipeStatus::Draft)
        ->and($recipe->files()->first()?->shared_file_id)->toBe($second->id)
        ->and($recipe->requirements[0]['match'])->toBe(['tiny-racer-v2.zip']);
});

it('does not let /games/{slug} swallow the share page', function () {
    $user = User::factory()->create();
    GameRecipe::factory()->published()->create(['slug' => 'share', 'title' => 'Share']);

    $this->actingAs($user)
        ->get('/games/share')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Games/Share'));
});

it('answers an unknown file id exactly like a file of somebody else', function () {
    $user = User::factory()->create();
    $foreign = SharedFile::factory()->create(['original_name' => 'foreign.zip']);

    $body = [
        'title' => 'Tiny Racer',
        'platforms' => ['windows'],
        'executable' => 'TinyRacer.exe',
    ];

    // Same message for both, so the form cannot be used to probe file ids.
    $expected = ['shared_file_id' => __('game_share.errors.file_missing')];

    $this->actingAs($user)
        ->post('/games/share', [...$body, 'shared_file_id' => 987654321])
        ->assertSessionHasErrors($expected);

    $this->actingAs($user)
        ->post('/games/share', [...$body, 'shared_file_id' => $foreign->id])
        ->assertSessionHasErrors($expected);

    expect(GameRecipe::query()->count())->toBe(0);
});
