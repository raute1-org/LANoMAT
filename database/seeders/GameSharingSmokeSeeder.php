<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Support\LocationSnapshot;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Fixtures for scripts/smoke/game-sharing-smoke.mjs: the real-browser twin of
 * tests/Feature/GameRecipes/GameSharingStoryTest.php.
 *
 * Deliberately NOT wired into DatabaseSeeder — a standalone, opt-in seeder
 * (`php artisan db:seed --class=GameSharingSmokeSeeder`). It creates the
 * accounts the journey needs (a participant who shares, an orga who reviews, a
 * second participant who finds and joins, one participant who hides their
 * location, and an account that is not registered for the event), one live LAN,
 * the game with two ready servers, and the Presence snapshot behind the
 * "Hier spielen" line. The game and its servers exist from the start on
 * purpose: the install block on the server rows only appears after
 * publication, so nothing has to be created through the panel mid-run.
 *
 * Reproducible: every row the smoke run creates is owned by this seeder's
 * event/users or by a known slug/title, and a second run deletes and recreates
 * them, so `/games/share` starts without an existing "Tiny Racer" recipe (the
 * share would otherwise refuse the title as taken). Files uploaded by a run
 * live on the filesystem; deleting their rows here leaves the bytes behind,
 * which costs a few KB in a throwaway database and nothing else.
 *
 * Factories are used for a side-effect-free path (no lifecycle actions, no
 * queued provisioning jobs), exactly like ScreenshotSeeder.
 */
class GameSharingSmokeSeeder extends Seeder
{
    /** Login for scripts/smoke/game-sharing-smoke.mjs. Not secrets: throwaway demo data. */
    public const SHARER_EMAIL = 'smoke-sharer@example.test';

    public const ORGA_EMAIL = 'smoke-orga@example.test';

    public const PLAYER_EMAIL = 'smoke-player@example.test';

    /** Registered, but hides the location: must not appear in another participant's "Hier spielen". */
    public const HIDDEN_EMAIL = 'smoke-hidden@example.test';

    /** Has an account, is not registered for the event: sees no "Hier spielen" at all. */
    public const OUTSIDER_EMAIL = 'smoke-outsider@example.test';

    public const PASSWORD = 'smoke-demo-password';

    public const EVENT_SLUG = 'smoke-lan';

    /** The slug `Str::slug('Tiny Racer')` produces; the smoke shares that title. */
    public const GAME_SLUG = 'tiny-racer';

    public const SERVER_TITLE = 'Tiny Racer · Halle 3';

    /**
     * The same game on a server whose join info is a `steam://connect/…` link,
     * so the journey drives both launcher-link shapes: "Im Launcher starten"
     * (no server in the link) and "Im Launcher beitreten" (`?url=…`).
     */
    public const SERVER_TITLE_LINKED = 'Tiny Racer · Bühne 2';

    /** The `steam://` link the second server advertises (documentation address). */
    public const LINKED_CONNECT = 'steam://connect/203.0.113.6:27016';

    public function run(): void
    {
        // Same hard refusal as ScreenshotSeeder: a publicly documented password
        // must never end up in a production database.
        if (app()->environment('production')) {
            optional($this->command)->warn('GameSharingSmokeSeeder: skipped — refusing to run in the production environment.');

            return;
        }

        DB::transaction(function (): void {
            $event = Event::query()->updateOrCreate(
                ['slug' => self::EVENT_SLUG],
                [
                    'name' => 'Smoke LAN 2026',
                    'status' => EventStatus::Live,
                    'location' => 'Halle 3',
                    'starts_at' => now()->subHours(4),
                    'ends_at' => now()->addDays(2),
                    'max_participants' => 32,
                    'settings' => [],
                ],
            );

            $this->reset($event);

            $this->account(self::SHARER_EMAIL, 'Mira Teilnehmerin', Role::Participant, '910000000000000001');
            $this->account(self::ORGA_EMAIL, 'Oona Orga', Role::Orga, '910000000000000002');
            $this->account(self::PLAYER_EMAIL, 'Piet Mitspieler', Role::Participant, '910000000000000003');
            $this->account(self::HIDDEN_EMAIL, 'Hanna Versteckt', Role::Participant, '910000000000000004');
            $this->account(self::OUTSIDER_EMAIL, 'Nico Fremd', Role::Participant, '910000000000000005');

            $game = Game::query()->updateOrCreate(
                ['slug' => self::GAME_SLUG],
                ['name' => 'Tiny Racer', 'min_team_size' => 1, 'max_team_size' => 4],
            );

            $link = ServerLink::query()->firstOrNew([
                'event_id' => $event->id,
                'title' => self::SERVER_TITLE,
            ]);
            $link->forceFill([
                'game_id' => $game->id,
                'status' => ServerLinkStatus::Ready,
                'manual' => true,
                'permanent' => false,
                'source' => ServerSource::External,
                'join_info' => new JoinInfo(
                    address: '203.0.113.5',
                    port: 27015,
                    connectString: '203.0.113.5:27015',
                ),
            ])->save();

            $linked = ServerLink::query()->firstOrNew([
                'event_id' => $event->id,
                'title' => self::SERVER_TITLE_LINKED,
            ]);
            $linked->forceFill([
                'game_id' => $game->id,
                'status' => ServerLinkStatus::Ready,
                'manual' => true,
                'permanent' => false,
                'source' => ServerSource::External,
                'join_info' => new JoinInfo(
                    address: '203.0.113.6',
                    port: 27016,
                    connectString: self::LINKED_CONNECT,
                ),
            ])->save();

            $this->registrations($event);
            $this->presence($link);
            $this->launcherBuild();

            optional($this->command)->info(sprintf(
                'GameSharingSmokeSeeder: event "%s", game "%s", servers "%s" / "%s", logins %s / %s / %s (plus %s, %s).',
                $event->slug,
                $game->slug,
                self::SERVER_TITLE,
                self::SERVER_TITLE_LINKED,
                self::SHARER_EMAIL,
                self::ORGA_EMAIL,
                self::PLAYER_EMAIL,
                self::HIDDEN_EMAIL,
                self::OUTSIDER_EMAIL,
            ));
        });
    }

    /**
     * The participants of the event: the sharer, the player and one person who
     * switched "Standort teilen" off. The outsider deliberately stays out, so
     * the smoke can see that an account without a registration gets no
     * "Hier spielen" line at all (the event's people list is the audience).
     */
    private function registrations(Event $event): void
    {
        $hidden = User::query()->where('email', self::HIDDEN_EMAIL)->sole();
        $hidden->forceFill(['share_location' => false])->save();

        foreach ([self::SHARER_EMAIL, self::PLAYER_EMAIL, self::HIDDEN_EMAIL] as $email) {
            $user = User::query()->where('email', $email)->sole();

            EventRegistration::query()->updateOrCreate(
                ['event_id' => $event->id, 'user_id' => $user->id],
                ['ticket_type' => 'standard', 'status' => RegistrationStatus::Confirmed],
            );
        }
    }

    /**
     * Presence's read model: the sharer, the player and the hidden participant
     * are "on" the plain server, so a participant opening a server page sees
     * "Hier spielen". The snapshot normally lives 5 minutes and is rebuilt by
     * the scheduler; a fixture written once has to outlive a smoke run (and the
     * run after it), so it is cached for a day — same cache slot and shape the
     * store reads, just a longer TTL.
     */
    private function presence(ServerLink $link): void
    {
        $place = static function (User $user) use ($link): PersonLocation {
            return new PersonLocation($user->id, [], new GameLocation(
                GameLocation::SOURCE_QUERY,
                'Tiny Racer',
                self::SERVER_TITLE,
                '203.0.113.5',
                27015,
                $link->id,
                now()->subMinutes(12)->toIso8601String(),
            ));
        };

        $people = [];

        foreach ([self::SHARER_EMAIL, self::PLAYER_EMAIL, self::HIDDEN_EMAIL] as $email) {
            $user = User::query()->where('email', $email)->sole();
            $people[$user->id] = $place($user);
        }

        $snapshot = new LocationSnapshot(now()->toIso8601String(), $people);

        Cache::put('presence.locations.snapshot', $snapshot->toArray(), 86_400);
    }

    /**
     * A published launcher build, so the journey sees the surface a real LAN
     * sees: "Installieren" on the game page links to the one download page
     * (/app) and "Im Launcher öffnen" is the `lanomat://install` action.
     * Without a build the game page only offers the deep link as
     * "Installieren", and that half of the launcher-link notice would never be
     * driven.
     *
     * The bytes are a placeholder, not a runnable app — the smoke never
     * downloads them and never claims the launcher works. An existing manifest
     * (a real `scripts/ops/build-launcher.sh` run) is left untouched.
     */
    private function launcherBuild(): void
    {
        $configured = config('launcher.dist_path');
        $dir = is_string($configured) && $configured !== ''
            ? $configured
            : base_path('clients-dist/launcher');

        if (is_file($dir.'/manifest.json')) {
            return;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true)) {
            optional($this->command)->warn('GameSharingSmokeSeeder: could not create the launcher dist directory.');

            return;
        }

        // The name links the real build (`lanomat-launcher-<version>-linux-x86_64.tar.gz`,
        // see scripts/ops/build-launcher.sh), so the download page's install steps
        // ("Archiv entpacken: tar -xzf <name>") read the way they do for a real build.
        $name = 'lanomat-launcher-0.0.0-smoke-linux-x86_64.tar.gz';
        $bytes = str_repeat(
            "LANoMAT launcher placeholder for the game-sharing smoke test.\n",
            20000,
        );

        if (@file_put_contents($dir.'/'.$name, $bytes) === false) {
            optional($this->command)->warn('GameSharingSmokeSeeder: could not write the placeholder launcher build.');

            return;
        }

        @file_put_contents($dir.'/manifest.json', json_encode([
            'app_version' => '0.0.0-smoke',
            'commit' => '0000smoke',
            'built_at' => now()->toIso8601String(),
            'files' => [[
                'name' => $name,
                'os' => 'linux',
                'arch' => 'x86_64',
                'kind' => 'app',
                'variant' => 'archive',
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'signed' => null,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * Everything a previous smoke run left behind: the shared recipe (its file
     * rows cascade), the run's uploads on this event, and the game/server pair
     * (so the seeder is the one that owns them, not a run).
     */
    private function reset(Event $event): void
    {
        GameRecipe::query()->where('slug', self::GAME_SLUG)->delete();
        SharedFile::query()->where('event_id', $event->id)->delete();
        ServerLink::query()->where('event_id', $event->id)->delete();
        Game::query()->where('slug', self::GAME_SLUG)->delete();
    }

    private function account(string $email, string $name, Role $role, string $discordId): void
    {
        // `role` and `email_verified_at` are not fillable (they are assigned by
        // app code, never from request input), so the fixture writes them the
        // same way the app does: forceFill on the row.
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'email_verified_at' => now(),
            'discord_id' => $discordId,
        ])->save();
    }
}
