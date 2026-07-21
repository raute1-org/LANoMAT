<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\News\Models\NewsPost;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic, idempotent demo data for the README screenshot pipeline
 * (roadmap 7.6, issue #10).
 *
 * Deliberately NOT wired into DatabaseSeeder — this is a standalone, opt-in
 * seeder invoked explicitly (`php artisan db:seed --class=ScreenshotSeeder`).
 *
 * Domain rows are created DIRECTLY via factories rather than by driving the
 * real lifecycle actions (StartTournament, TransitionEventStatus, ...).
 * Those actions dispatch domain events that queue real provisioning jobs
 * (Discord channel/role creation, Mumble channel creation, Pelican server
 * boot, SSH host operations) — side effects that have no place in a seeder
 * meant to only populate screens with DB state for screenshots. Factories
 * bypass $fillable guards and lifecycle invariants on purpose here; that is
 * the intended, side-effect-free path for this seeder.
 *
 * Idempotency: `updateOrCreate` on the event's stable slug, and child rows
 * are only created when the event `wasRecentlyCreated` (or matched on their
 * own stable keys), so a second run does not duplicate anything.
 *
 * The seeded orga also gets a fixed local password (`ORGA_PASSWORD`) even
 * though production users are Discord-only — this is the login capture.mjs
 * uses to reach the `/admin` panel and the authenticated dashboard route
 * headlessly via Fortify's standard `/login` form, since Discord's real
 * OAuth handshake cannot be automated without live Discord credentials.
 */
class ScreenshotSeeder extends Seeder
{
    /**
     * Fixed login for capture.mjs. Not a secret: this only ever exists in
     * throwaway seeded demo databases used for screenshotting, never in
     * a real deployment (see the seeder-level docblock).
     */
    public const ORGA_EMAIL = 'screenshot-orga@example.test';

    public const ORGA_PASSWORD = 'screenshot-demo-password';

    public function run(): void
    {
        // Hard refusal: this seeder creates an orga login with a hardcoded,
        // publicly-documented password. Never let it touch a production
        // database, even by accident (e.g. a copy-pasted db:seed command).
        if (app()->environment('production')) {
            // optional(): $command is null (not set) when this seeder is
            // instantiated directly rather than run via `db:seed` — as it
            // deliberately is in its own tests and may be elsewhere.
            optional($this->command)->warn('ScreenshotSeeder: skipped — refusing to run in the production environment.');

            return;
        }

        DB::transaction(function (): void {
            $orga = User::query()->updateOrCreate(
                ['discord_id' => '900000000000000001'],
                [
                    'name' => 'Screenshot Orga',
                    'email' => self::ORGA_EMAIL,
                    'password' => Hash::make(self::ORGA_PASSWORD),
                    'role' => Role::Orga,
                    'email_verified_at' => now(),
                ],
            );

            $event = Event::query()->updateOrCreate(
                ['slug' => 'screenshot-demo'],
                [
                    'name' => 'LANoMAT Screenshot LAN 2026',
                    'status' => EventStatus::Live,
                    'location' => 'Halle 3, Messegelände',
                    'starts_at' => now()->subHours(6),
                    'ends_at' => now()->addDays(2),
                    'max_participants' => 64,
                    'settings' => [],
                ],
            );

            if (! $event->wasRecentlyCreated) {
                // Already seeded once — the children below are keyed off
                // this event and were created alongside it, so skip them.
                return;
            }

            $participants = User::factory()
                ->count(8)
                ->sequence(fn ($sequence) => ['name' => 'Teilnehmer '.($sequence->index + 1)])
                ->create();

            $registrations = $participants->map(
                fn (User $user) => EventRegistration::factory()->checkedIn()->create([
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                ]),
            );

            // Seating: a filled 4x4 grid, occupy the first 8 seats with the
            // checked-in participants.
            app(GenerateSeatGrid::class)->handle($event, rows: 4, cols: 4, labelPrefix: 'A');

            Seat::query()
                ->where('event_id', $event->id)
                ->orderBy('pos_y')->orderBy('pos_x')
                ->limit($registrations->count())
                ->get()
                ->each(function (Seat $seat, int $index) use ($registrations): void {
                    SeatAssignment::factory()->create([
                        'seat_id' => $seat->id,
                        'registration_id' => $this->at($registrations, $index)->id,
                    ]);
                });

            // Tournament: a live single-elimination bracket with 8 entries
            // and two round-1 matches ready to play.
            $tournament = Tournament::factory()->live()->singleElim()->create([
                'event_id' => $event->id,
                'name' => 'Counter-Strike 2 Cup',
                'team_size' => 1,
            ]);

            $entries = $participants->map(
                fn (User $user, int $index) => TournamentEntry::factory()->solo()->checkedIn()->create([
                    'tournament_id' => $tournament->id,
                    'user_id' => $user->id,
                    'display_name' => $user->name,
                    'seed' => $index + 1,
                ]),
            )->values();

            GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 1,
                'position' => 0,
                'entry1_id' => $this->at($entries, 0)->id,
                'entry2_id' => $this->at($entries, 1)->id,
                'status' => MatchStatus::Ready,
            ]);

            GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 1,
                'position' => 1,
                'entry1_id' => $this->at($entries, 2)->id,
                'entry2_id' => $this->at($entries, 3)->id,
                'status' => MatchStatus::Ready,
            ]);

            GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 1,
                'position' => 2,
                'entry1_id' => $this->at($entries, 4)->id,
                'entry2_id' => $this->at($entries, 5)->id,
                'status' => MatchStatus::Pending,
            ]);

            GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 1,
                'position' => 3,
                'entry1_id' => $this->at($entries, 6)->id,
                'entry2_id' => $this->at($entries, 7)->id,
                'status' => MatchStatus::Pending,
            ]);

            // Files: a couple of approved shared files.
            SharedFile::factory()->approved()->create([
                'event_id' => $event->id,
                'user_id' => $orga->id,
                'original_name' => 'lan-regeln.pdf',
                'mime' => 'application/pdf',
            ]);

            SharedFile::factory()->approved()->create([
                'event_id' => $event->id,
                'user_id' => $this->at($participants, 0)->id,
                'original_name' => 'team-logo.png',
                'mime' => 'image/png',
            ]);

            // Voting: an open poll with four options and a few votes.
            $poll = Poll::factory()->open()->withOptions(4)->create([
                'event_id' => $event->id,
                'question' => 'Welches Spiel soll die nächste Nacht-Runde eröffnen?',
            ]);

            $options = $poll->options()->orderBy('sort')->get();
            foreach ($participants->take(3) as $index => $voter) {
                PollVote::factory()->create([
                    'poll_id' => $poll->id,
                    'poll_option_id' => $this->at($options, $index % $options->count())->id,
                    'user_id' => $voter->id,
                ]);
            }

            // Catering: an open food order with a fixed, pinned menu. NOT the
            // factory default (`FoodOrderFactory::defaultMenu()` flips an
            // unseeded coin to decide between 2 or 3 options) — screenshots
            // must be identical across runs, so the menu is spelled out here.
            FoodOrder::factory()->open()->create([
                'event_id' => $event->id,
                'title' => 'Mitternachts-Pizza',
                'menu' => [
                    new MenuOption(key: 'pizza_margherita', name: 'Pizza Margherita', priceCents: 850),
                    new MenuOption(key: 'pizza_salami', name: 'Pizza Salami', priceCents: 950),
                    new MenuOption(key: 'salad', name: 'Salat', priceCents: 450),
                ],
            ]);

            // Schedule: a couple of literal, screenshot-friendly items.
            ScheduleItem::factory()->tournament()->create([
                'event_id' => $event->id,
                'title' => 'Counter-Strike 2 Cup — Runde 1',
                'starts_at' => now()->addHour(),
            ]);

            ScheduleItem::factory()->catering()->create([
                'event_id' => $event->id,
                'title' => 'Mitternachts-Pizza',
                'starts_at' => now()->addHours(4),
            ]);

            // M12 Gallery: approved highlights plus one still-pending upload,
            // so the (auth-gated) gallery page shows the moderation states.
            // Real JPEG bytes are written to the private disk (see
            // seedPhoto) so the <img> thumbnails actually render.
            $this->seedGalleryPhotos($event, $orga, $participants);

            // Beamer scenes so /screen/{event} rotates real content instead
            // of the idle "Bereit" placeholder (the client shows the first
            // enabled scene, then rotates).
            $this->seedBeamerScenes($event, $tournament);
        });

        // M12 surfaces live on their own events so the screenshot-demo
        // fixtures (and their test invariants) stay untouched: the recap page
        // needs a Finished event, the countdown/hype needs an upcoming one,
        // and news is global. Each block is independently idempotent (own
        // slug/title guard), mirroring the demo event above.
        $this->seedRecapEvent();
        $this->seedUpcomingEvent();
        $this->seedNews();
    }

    /**
     * Approved gallery highlights plus one still-pending upload for the
     * given event, so the gallery page shows both moderation states.
     *
     * @param  Collection<int, User>  $participants
     */
    private function seedGalleryPhotos(Event $event, User $orga, Collection $participants): void
    {
        $captions = [
            'Aufbau am Freitagabend',
            'Der Turnier-Showdown',
            'Vollbesetzte Halle',
            'Mitternachts-Pizza-Nachschub',
        ];

        foreach ($captions as $index => $caption) {
            $this->seedPhoto($event, $orga, $caption, highlight: true, index: $index);
        }

        // A participant's still-pending upload — visible to the orga (policy:
        // isOrga sees everything) so the "wird geprüft" state shows.
        $this->seedPhoto($event, $this->at($participants, 0), 'Wartet auf Freigabe', highlight: false, index: 99);
    }

    /**
     * A single gallery photo row plus its real (placeholder) JPEG bytes on
     * the private `local` disk, so the served thumbnail actually renders in
     * the screenshot rather than as a broken <img>.
     */
    private function seedPhoto(Event $event, User $uploader, string $caption, bool $highlight, int $index): void
    {
        $factory = $highlight
            ? EventPhoto::factory()->highlight()
            : EventPhoto::factory();

        $photo = $factory->create([
            'event_id' => $event->id,
            'uploaded_by' => $uploader->id,
            'caption' => $caption,
        ]);

        $bytes = $this->placeholderJpeg($index);
        Storage::disk('local')->put($photo->path, $bytes);
        Storage::disk('local')->put($photo->thumb_path, $bytes);
    }

    /**
     * A small solid-colour JPEG placeholder, hue varied by index so the
     * gallery grid looks populated. Returns an empty string if GD is
     * unavailable (the row still exists; the <img> just renders empty) —
     * GD is present in both the app image and the CI/host PHP builds.
     */
    private function placeholderJpeg(int $index): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }

        $palette = [
            [239, 68, 68], [59, 130, 246], [34, 197, 94], [234, 179, 8],
            [168, 85, 247], [236, 72, 153], [20, 184, 166], [249, 115, 22],
        ];
        [$r, $g, $b] = $palette[$index % count($palette)];

        $image = imagecreatetruecolor(640, 360);
        $fill = imagecolorallocate($image, $r, $g, $b);

        if ($fill === false) {
            imagedestroy($image);

            return '';
        }

        imagefilledrectangle($image, 0, 0, 640, 360, $fill);

        ob_start();
        imagejpeg($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * A realistic rotation of enabled beamer scenes for the live event, so
     * /screen/{event} shows glanceable content (presence headcount first,
     * then the live bracket, seatmap, gallery, schedule).
     */
    private function seedBeamerScenes(Event $event, Tournament $tournament): void
    {
        $scenes = [
            [SceneType::Presence, new SceneConfig],
            [SceneType::Bracket, new SceneConfig(tournamentId: $tournament->id)],
            [SceneType::Seatmap, new SceneConfig],
            [SceneType::Gallery, new SceneConfig],
            [SceneType::Schedule, new SceneConfig],
        ];

        foreach ($scenes as $sort => [$type, $config]) {
            InfoscreenScene::factory()->create([
                'event_id' => $event->id,
                'type' => $type,
                'config' => $config,
                'sort' => $sort,
                'enabled' => true,
            ]);
        }
    }

    /**
     * A separate Finished event driving the public recap page + beamer scene:
     * a crowned tournament (podium), gallery highlights, a closed MVP poll
     * with a clear winner, and a jukebox play history.
     */
    private function seedRecapEvent(): void
    {
        $event = Event::query()->updateOrCreate(
            ['slug' => 'screenshot-recap'],
            [
                'name' => 'LANoMAT Winter LAN 2025',
                'status' => EventStatus::Finished,
                'location' => 'Bürgerhaus, Großer Saal',
                'starts_at' => now()->subDays(40),
                'ends_at' => now()->subDays(38),
                'max_participants' => 48,
                'settings' => [],
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return;
        }

        $orga = $this->seededOrga();

        $players = User::factory()
            ->count(6)
            ->sequence(fn ($sequence) => ['name' => 'Winter-Gast '.($sequence->index + 1)])
            ->create();

        $players->each(fn (User $user) => EventRegistration::factory()->checkedIn()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]));

        // A finished single-elim tournament with a crowned winner -> podium.
        $tournament = Tournament::factory()->singleElim()->create([
            'event_id' => $event->id,
            'name' => 'Valorant Winter Cup',
            'team_size' => 1,
            'status' => TournamentStatus::Finished,
        ]);

        $entries = $players->map(fn (User $user, int $index) => TournamentEntry::factory()->solo()->checkedIn()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'display_name' => $user->name,
            'seed' => $index + 1,
        ]))->values();

        // winner_entry_id is a state field, not mass-assignable on the model.
        $tournament->forceFill(['winner_entry_id' => $this->at($entries, 0)->id])->save();

        // A few completed matches -> the recap "matches played" count.
        for ($position = 0; $position < 3; $position++) {
            GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 1,
                'position' => $position,
                'entry1_id' => $this->at($entries, $position * 2)->id,
                'entry2_id' => $this->at($entries, $position * 2 + 1)->id,
                'status' => MatchStatus::Completed,
            ]);
        }

        // Gallery highlights -> the recap top photos (public thumb route).
        foreach (['Siegerehrung', 'Der große Saal', 'Best of Winter'] as $index => $caption) {
            $this->seedPhoto($event, $orga, $caption, highlight: true, index: 10 + $index);
        }

        // MVP-of-the-night: a CLOSED mvp poll with participant-named options
        // and a 3/2/1 vote split, so MvpPollQuery::winner resolves a name.
        $poll = Poll::factory()->mvp()->closed()->create([
            'event_id' => $event->id,
            'question' => 'Wer war Spieler:in des Abends?',
        ]);

        $options = $players->take(4)->values()->map(function (User $user, int $index) use ($poll): PollOption {
            $option = PollOption::factory()->create([
                'poll_id' => $poll->id,
                'label' => $user->name,
                'sort' => $index,
            ]);
            // subject_user_id is the winner->user linkage; not mass-assignable.
            $option->forceFill(['subject_user_id' => $user->id])->save();

            return $option;
        });

        $voterIndex = 0;
        foreach ([3, 2, 1] as $optionIndex => $voteCount) {
            for ($vote = 0; $vote < $voteCount; $vote++) {
                PollVote::factory()->create([
                    'poll_id' => $poll->id,
                    'poll_option_id' => $this->at($options, $optionIndex)->id,
                    'user_id' => $this->at($players, $voterIndex)->id,
                ]);
                $voterIndex++;
            }
        }

        // Jukebox play history -> the recap "songs played" count.
        JukeboxItem::factory()->count(37)->create([
            'event_id' => $event->id,
            'added_by' => $this->at($players, 0)->id,
            'status' => QueueItemStatus::Played,
        ]);

        // The recap beamer scene -> /screen/screenshot-recap shows the
        // post-LAN recap (podium, top photos, MVP) instead of "Bereit".
        InfoscreenScene::factory()->create([
            'event_id' => $event->id,
            'type' => SceneType::Recap,
            'config' => new SceneConfig,
            'sort' => 0,
            'enabled' => true,
        ]);
    }

    /**
     * A separate upcoming (Registration) event with arrival info and a live
     * registration count, driving the pre-LAN countdown/hype on its public
     * event page.
     */
    private function seedUpcomingEvent(): void
    {
        $event = Event::query()->updateOrCreate(
            ['slug' => 'screenshot-upcoming'],
            [
                'name' => 'LANoMAT Summer LAN 2026',
                'status' => EventStatus::Registration,
                'location' => 'Messehalle 3, Stuttgart',
                'starts_at' => now()->addDays(24)->setTime(18, 0),
                'ends_at' => now()->addDays(26)->setTime(12, 0),
                'max_participants' => 80,
                'arrival_info' => 'Einlass ab 16 Uhr. Parkplätze auf dem Südgelände, '
                    .'Strom und ein 10-GbE-Uplink sind an jedem Platz vorbereitet.',
                'settings' => [],
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return;
        }

        User::factory()
            ->count(23)
            ->create()
            ->each(fn (User $user) => EventRegistration::factory()->create([
                'event_id' => $event->id,
                'user_id' => $user->id,
            ]));
    }

    /**
     * A few published global news posts for the homepage "Neuigkeiten" block.
     * Idempotent on the (stable) title; authorship + publish date are state
     * fields set via forceFill, mirroring CreateNewsPost.
     */
    private function seedNews(): void
    {
        $orga = $this->seededOrga();

        $posts = [
            [
                'title' => 'Anmeldung für die Summer LAN 2026 ist offen',
                'days' => 1,
                'body' => 'Sichert euch früh einen Platz — die letzten beiden LANs waren '
                    ."Wochen vorher ausverkauft.\n\nWer im Team anreist, trägt sich am besten "
                    .'gemeinsam ein, dann setzen wir euch nebeneinander.',
            ],
            [
                'title' => 'Neuer 10-GbE-Uplink im Turniersaal',
                'days' => 4,
                'body' => 'Der komplette Turniersaal hängt jetzt an einem eigenen 10-GbE-Uplink. '
                    .'Für Downloads gibt es weiterhin den LanCache — Steam-Titel laden nach dem '
                    .'ersten Rechner mit LAN-Speed.',
            ],
            [
                'title' => 'Rückblick: Winter LAN 2025 war ausverkauft',
                'days' => 9,
                'body' => '48 Plätze, ein volles Turnierwochenende und die längste Jukebox-Nacht '
                    ."bisher.\n\nDie schönsten Fotos und die Spieler:in des Abends findet ihr im Recap.",
            ],
        ];

        foreach ($posts as $data) {
            $post = NewsPost::query()->updateOrCreate(
                ['title' => $data['title']],
                ['body' => $data['body']],
            );

            if ($post->wasRecentlyCreated) {
                $post->forceFill([
                    'author_id' => $orga->id,
                    'published_at' => now()->subDays($data['days']),
                ])->save();
            }
        }
    }

    /**
     * The orga created by the demo block above; every M12 block runs after
     * it, so it always exists by the time these are called.
     */
    private function seededOrga(): User
    {
        return User::query()->where('discord_id', '900000000000000001')->firstOrFail();
    }

    /**
     * Typed, non-null element access into a freshly-created collection whose
     * size we control above — used instead of `$collection[$i]` so phpstan
     * (level 8) sees a non-nullable model instead of `Model|null`.
     *
     * @template TModel of object
     *
     * @param  Collection<int, TModel>  $collection
     * @return TModel
     */
    private function at(Collection $collection, int $index): object
    {
        $item = $collection->get($index);

        if ($item === null) {
            throw new \OutOfBoundsException("No element at index {$index}.");
        }

        return $item;
    }
}
