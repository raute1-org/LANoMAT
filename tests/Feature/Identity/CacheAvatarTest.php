<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Actions\CacheAvatar;
use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use App\Modules\Identity\Jobs\RefreshAvatarJob;
use App\Modules\Identity\Support\AvatarProcessor;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AvatarHelpers.php';

uses(RefreshDatabase::class);

it('re-encodes the download to a square 256 px image on the private disk, content-addressed', function () {
    $fake = fakeAvatars()->willReturn(avatarImage('png', 400, 250));
    $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);

    app(CacheAvatar::class)->handle($user);

    $user->refresh();
    $stored = Storage::disk('local')->get($user->avatar_path);
    [$width, $height] = getimagesizefromstring($stored);
    $extension = function_exists('imagewebp') ? 'webp' : 'jpg';

    expect($fake->fetched)->toBe(['https://cdn.discordapp.com/avatars/1/a.png'])
        ->and([$width, $height])->toBe([256, 256])
        ->and($user->avatar_path)->toBe("avatars/{$user->id}-{$user->avatar_hash}.{$extension}")
        ->and($user->avatar_hash)->toBe(substr(hash('sha256', $stored), 0, 16))
        ->and($user->avatar_checked_at)->not->toBeNull()
        // The remembered source stays untouched.
        ->and($user->avatar_url)->toBe('https://cdn.discordapp.com/avatars/1/a.png');
});

it('drops EXIF and every other metadata segment', function () {
    $source = avatarJpegWithExif();
    expect($source)->toContain('PRIVATE-GPS-MARKER');

    fakeAvatars()->willReturn($source, 'image/jpeg');
    $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.jpg']);

    app(CacheAvatar::class)->handle($user);

    $stored = Storage::disk('local')->get($user->refresh()->avatar_path);

    expect($stored)->not->toContain('PRIVATE-GPS-MARKER')
        ->and($stored)->not->toContain('Exif');
});

it('is idempotent: the same source gives the same file and no duplicate', function () {
    fakeAvatars()->willReturn(avatarImage());
    $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);

    app(CacheAvatar::class)->handle($user);
    $first = $user->refresh()->avatar_path;
    app(CacheAvatar::class)->handle($user);

    expect($user->refresh()->avatar_path)->toBe($first)
        ->and(Storage::disk('local')->allFiles('avatars'))->toBe([$first]);
});

it('deletes the old file when the avatar changes', function () {
    $fake = fakeAvatars()->willReturn(avatarImage('png', 300, 300));
    $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);
    app(CacheAvatar::class)->handle($user);
    $old = $user->refresh()->avatar_path;
    $oldHash = $user->avatar_hash;

    $fake->willReturn(avatarImage('jpeg', 280, 280));
    app(CacheAvatar::class)->handle($user);

    expect($user->refresh()->avatar_path)->not->toBe($old)
        ->and($user->avatar_hash)->not->toBe($oldHash)
        ->and(Storage::disk('local')->exists($old))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('avatars'))->toBe([$user->avatar_path]);
});

it('keeps the old file and row when a fetch fails', function () {
    $fake = fakeAvatars()->willReturn(avatarImage());
    $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);
    app(CacheAvatar::class)->handle($user);
    $before = $user->refresh()->only(['avatar_path', 'avatar_hash']);

    $fake->willFail('CDN down');

    expect(fn () => app(CacheAvatar::class)->handle($user))->toThrow(AvatarFetchFailed::class)
        ->and($user->refresh()->only(['avatar_path', 'avatar_hash']))->toBe($before)
        ->and(Storage::disk('local')->exists($before['avatar_path']))->toBeTrue();
});

it('refuses bytes that are not a real image even with an image content type', function () {
    fakeAvatars()->willReturn('<?php echo "not an image";', 'image/png');
    $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);

    expect(fn () => app(CacheAvatar::class)->handle($user))->toThrow(AvatarFetchFailed::class)
        ->and($user->refresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('avatars'))->toBe([]);
});

it('refuses images with unreasonable dimensions', function () {
    $processor = app(AvatarProcessor::class);

    expect(fn () => $processor->handle(avatarImage('png', 8, 8)))->toThrow(AvatarFetchFailed::class)
        ->and(fn () => $processor->handle(avatarImage('png', 9000, 20)))->toThrow(AvatarFetchFailed::class);
});

it('removes the local copy when the user no longer has a Discord avatar', function () {
    $user = userWithAvatar();
    $path = $user->avatar_path;

    $user->forceFill(['avatar_url' => null])->save();
    app(CacheAvatar::class)->handle($user);

    expect($user->refresh()->avatar_path)->toBeNull()
        ->and($user->avatar_hash)->toBeNull()
        ->and($user->avatar_src)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

describe('job', function () {
    it('fills the avatar and is idempotent', function () {
        fakeAvatars()->willReturn(avatarImage());
        $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);

        RefreshAvatarJob::dispatchSync($user->id);
        $path = $user->refresh()->avatar_path;
        RefreshAvatarJob::dispatchSync($user->id);

        expect($path)->not->toBeNull()
            ->and($user->refresh()->avatar_path)->toBe($path)
            ->and(Storage::disk('local')->allFiles('avatars'))->toHaveCount(1);
    });

    it('swallows a failed fetch and ignores a deleted user', function () {
        fakeAvatars()->willFail();
        $user = User::factory()->create(['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);

        RefreshAvatarJob::dispatchSync($user->id);
        RefreshAvatarJob::dispatchSync(999_999);

        expect($user->refresh()->avatar_path)->toBeNull();
    });

    it('is dispatched by the Discord login without blocking it', function () {
        // Covered with the callback in DiscordLoginTest; here only the unique id contract.
        expect((new RefreshAvatarJob(7))->uniqueId())->toBe('7');
    });
});

describe('lanomat:refresh-avatars', function () {
    function avatarUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);
    }

    it('refreshes recently active users and registered participants, not everybody', function () {
        $fake = fakeAvatars()->willReturn(avatarImage());
        $recent = avatarUser(['last_login_at' => now()->subDays(3)]);
        $stale = avatarUser(['last_login_at' => now()->subDays(90)]);
        $never = avatarUser();
        $registered = avatarUser();
        EventRegistration::factory()->create([
            'user_id' => $registered->id,
            'event_id' => Event::factory()->create(['status' => EventStatus::Registration]),
        ]);
        $oldEventUser = avatarUser();
        EventRegistration::factory()->create([
            'user_id' => $oldEventUser->id,
            'event_id' => Event::factory()->create(['status' => EventStatus::Archived]),
        ]);

        $this->artisan('lanomat:refresh-avatars', ['--pause' => 0])->assertSuccessful();

        expect($recent->refresh()->avatar_path)->not->toBeNull()
            ->and($registered->refresh()->avatar_path)->not->toBeNull()
            ->and($stale->refresh()->avatar_path)->toBeNull()
            ->and($never->refresh()->avatar_path)->toBeNull()
            ->and($oldEventUser->refresh()->avatar_path)->toBeNull()
            ->and($fake->fetched)->toHaveCount(2);
    });

    it('--all refreshes every user with a Discord avatar', function () {
        $fake = fakeAvatars()->willReturn(avatarImage());
        $a = avatarUser();
        $b = avatarUser(['last_login_at' => now()->subDays(400)]);
        $none = User::factory()->create(['avatar_url' => null]);

        $this->artisan('lanomat:refresh-avatars', ['--all' => true, '--pause' => 0])->assertSuccessful();

        expect($a->refresh()->avatar_path)->not->toBeNull()
            ->and($b->refresh()->avatar_path)->not->toBeNull()
            ->and($none->refresh()->avatar_path)->toBeNull()
            ->and($fake->fetched)->toHaveCount(2);
    });

    it('skips users that were checked recently on the scheduled run', function () {
        $fake = fakeAvatars()->willReturn(avatarImage());
        avatarUser(['last_login_at' => now(), 'avatar_checked_at' => now()->subHours(2)]);

        $this->artisan('lanomat:refresh-avatars', ['--pause' => 0])->assertSuccessful();

        expect($fake->fetched)->toBe([]);
    });

    it('never fails the whole run because one user fails', function () {
        $fake = fakeAvatars()->willFail('CDN down');
        avatarUser(['last_login_at' => now()]);
        avatarUser(['last_login_at' => now()]);

        $this->artisan('lanomat:refresh-avatars', ['--pause' => 0])
            ->expectsOutputToContain('failed: 2')
            ->assertSuccessful();

        expect($fake->fetched)->toHaveCount(2);
    });

    it('can refresh a single user', function () {
        $fake = fakeAvatars()->willReturn(avatarImage());
        $one = avatarUser();
        avatarUser();

        $this->artisan('lanomat:refresh-avatars', ['--user' => $one->id, '--pause' => 0])->assertSuccessful();

        expect($fake->fetched)->toHaveCount(1)->and($one->refresh()->avatar_path)->not->toBeNull();
    });

    it('is on the daily schedule', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'lanomat:refresh-avatars'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('30 4 * * *');
    });
});
