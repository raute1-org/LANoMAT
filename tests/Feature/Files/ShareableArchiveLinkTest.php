<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * The files page may only offer "Als Spiel teilen" for a file the share page
 * would actually accept. Files must not import GameRecipes for that answer, so
 * the files page asks a Files-owned contract; this test keeps the two surfaces
 * in step.
 */
beforeEach(function () {
    Storage::fake('local');
});

it('offers sharing exactly for the archives the share page accepts', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $names = [
        'Blitz Racer.zip' => true,
        'blitz-racer-1.0.tar.bz2' => true,
        'Blitz Racer.7z' => false,
        '.zip' => false,
        'a.zip' => false,
        'blitz`racer.zip' => false,
        'blitz*.zip' => false,
        'blitz racer notes.txt' => false,
    ];

    foreach (array_keys($names) as $name) {
        SharedFile::factory()->for($event)->create([
            'user_id' => $user->id,
            'original_name' => $name,
        ]);
    }

    $this->actingAs($user)
        ->get("/events/{$event->slug}/files")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Files/Index')
            ->where('files', function ($files) use ($names): bool {
                $flags = collect($files)->mapWithKeys(
                    static fn (array $file): array => [$file['originalName'] => $file['shareableAsGame']],
                )->all();
                $expected = $names;
                ksort($flags);
                ksort($expected);

                return $flags === $expected;
            })
        );

    $this->actingAs($user)
        ->get('/games/share')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Games/Share')
            ->where('files', function ($files) use ($names): bool {
                $flags = collect($files)->mapWithKeys(
                    static fn (array $file): array => [$file['name'] => $file['usable']],
                )->all();
                $expected = $names;
                ksort($flags);
                ksort($expected);

                return $flags === $expected;
            })
        );
});

it('offers no sharing at all while the game recipes module is off', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    SharedFile::factory()->for($event)->create([
        'user_id' => $user->id,
        'original_name' => 'Blitz Racer.zip',
    ]);

    app()->instance(Modules::class, new Modules('full', ['game_recipes' => false]));

    $this->actingAs($user)
        ->get("/events/{$event->slug}/files")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Files/Index')
            ->where('files.0.shareableAsGame', false)
        );
});
