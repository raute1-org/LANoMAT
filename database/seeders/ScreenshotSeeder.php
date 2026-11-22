<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Mcp\Models\McpDeviceCode;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\News\Models\NewsPost;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Support\LocationSnapshot;
use App\Modules\Presence\Support\LocationSnapshotStore;
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

    /**
     * The pairing code the docs capture visits (`/mcp/connect?code=DEMO-2345`)
     * and the bearer token the terminal capture uses for the real CLI
     * (`lanomat-agent install … --dry-run`). Both only exist in throwaway
     * demo databases, exactly like ORGA_PASSWORD; the token is never printed
     * in a screenshot and never written outside the capture's temp HOME.
     */
    public const MCP_PAIRING_CODE = 'DEMO-2345';

    public const MCP_AGENT_TOKEN = 'lmcp_screenshotAgentToken000000000000000000000000000';

    /**
     * Recipes shown as the published catalogue on the games page. The YAML
     * files stay the authority everywhere else; this list only makes the
     * seeded library look like a real LAN catalogue.
     *
     * @var list<string>
     */
    private const LIBRARY_SLUGS = [
        'unreal-tournament-2004',
        'ridge-racer-type-4',
        'counter-strike-1-6',
        'f-zero-x-vr',
        'crash-team-racing',
        'bloodborne',
        'super-mario-sunshine',
        'zelda-ocarina-of-time-3d',
    ];

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

            // The orga is a participant too: the dashboard screenshot then
            // shows a ticket and a seat instead of the empty "not registered"
            // state, and the gallery page offers the upload card.
            $orgaRegistration = EventRegistration::factory()->checkedIn()->create([
                'event_id' => $event->id,
                'user_id' => $orga->id,
            ]);

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

            $orgaSeat = Seat::query()
                ->where('event_id', $event->id)
                ->whereDoesntHave('assignment')
                ->orderBy('pos_y')
                ->orderBy('pos_x')
                ->first();

            if ($orgaSeat instanceof Seat) {
                SeatAssignment::factory()->create([
                    'seat_id' => $orgaSeat->id,
                    'registration_id' => $orgaRegistration->id,
                ]);
            }

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

            // A real 8-entry single-elimination bracket, not just a round-1
            // list: two played matches with scores, two in progress, the
            // resulting semi-finals and the final. State fields bypass the
            // fillable guard exactly like the other fixtures.
            $roundOne = [];

            foreach ([[0, 1], [2, 3], [4, 5], [6, 7]] as $position => $pair) {
                $roundOne[$position] = GameMatch::factory()->create([
                    'tournament_id' => $tournament->id,
                    'round' => 1,
                    'position' => $position,
                    'entry1_id' => $this->at($entries, $pair[0])->id,
                    'entry2_id' => $this->at($entries, $pair[1])->id,
                ]);
            }

            $roundOne[0]->forceFill([
                'status' => MatchStatus::Completed,
                'score1' => 13,
                'score2' => 7,
                'winner_entry_id' => $this->at($entries, 0)->id,
            ])->save();

            $roundOne[1]->forceFill([
                'status' => MatchStatus::Completed,
                'score1' => 9,
                'score2' => 13,
                'winner_entry_id' => $this->at($entries, 3)->id,
            ])->save();

            $roundOne[2]->forceFill(['status' => MatchStatus::Live])->save();
            $roundOne[3]->forceFill(['status' => MatchStatus::Ready])->save();

            $semiOne = GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 2,
                'position' => 0,
                'entry1_id' => $this->at($entries, 0)->id,
                'entry2_id' => $this->at($entries, 3)->id,
            ]);
            $semiOne->forceFill(['status' => MatchStatus::Ready])->save();

            $semiTwo = GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 2,
                'position' => 1,
            ]);

            $final = GameMatch::factory()->create([
                'tournament_id' => $tournament->id,
                'round' => 3,
                'position' => 0,
            ]);

            foreach ([[0, $semiOne, 1], [1, $semiOne, 2], [2, $semiTwo, 1], [3, $semiTwo, 2]] as [$position, $next, $slot]) {
                $roundOne[$position]->forceFill(['next_match_id' => $next->id, 'next_slot' => $slot])->save();
            }

            $semiOne->forceFill(['next_match_id' => $final->id, 'next_slot' => 1])->save();
            $semiTwo->forceFill(['next_match_id' => $final->id, 'next_slot' => 2])->save();

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
        $this->seedGameLibrary();
        $this->seedSharedGames();
        $this->seedPresence();
        $this->seedMcpPairing();
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
     * The participant games library (/games + /games/{slug}): the bundled
     * recipe catalogue, published in a screenshot-sized slice, with LAN copies
     * pinned for two titles so the "Nur LAN-Spiele" filter has something to
     * filter, plus one ready server.
     */
    private function seedGameLibrary(): void
    {
        // The YAML files are the authority; run the real sync instead of
        // duplicating recipe content in the seeder.
        app(SyncRecipes::class)->handle((string) config('recipes.source'));

        // Published AND on the lineup: the shipped `LAUNCHER_LINEUP` names two
        // games, so a recipe the player surfaces should show has to carry the
        // panel's `in_lineup` flag — how a real orga adds a game to the list.
        GameRecipe::query()
            ->whereIn('slug', self::LIBRARY_SLUGS)
            ->update([
                'status' => RecipeStatus::Published->value,
                'in_lineup' => true,
            ]);

        $event = Event::query()->where('slug', 'screenshot-demo')->first();

        if (! $event instanceof Event) {
            return;
        }

        $orga = $this->seededOrga();

        $ut = $this->pinLanCopy(
            'unreal-tournament-2004',
            $event,
            $orga,
            'UT2004-DVD1.iso',
            4_500_000_000,
            'event-'.$event->id.'/screenshot-ut2004.iso',
        );

        // The patch and one avatar make the agent's dry-run plan complete
        // (no "missing required files" at the end of the terminal capture).
        $this->pinLanCopy(
            'unreal-tournament-2004',
            $event,
            $orga,
            'OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2',
            42_000_000,
            'event-'.$event->id.'/screenshot-ut2004-patch.tar.bz2',
            'patch',
        );

        // The whole avatar shelf, so the agent's dry-run finds no missing
        // optional file (the installer currently reports every missing file,
        // optional or not, as a blocker).
        foreach ([
            ['VRM avatars (multi-select from the LAN file host)', 'avatar-library.vrm', 63_000_000],
            ['VRM avatar · Ayla (agile scout)', 'alyx.vrm', 18_000_000],
            ['VRM avatar · Brutus (heavy assault)', 'brutus.vrm', 21_000_000],
            ['VRM avatar · Kestrel (shock rifle)', 'kestrel.vrm', 19_000_000],
            ['VRM avatar · Mako (arena duelist)', 'mako.vrm', 24_000_000],
            ['VRM avatar · Sable (night ops)', 'sable.vrm', 20_000_000],
        ] as [$label, $file, $bytes]) {
            $this->pinLanCopy(
                'unreal-tournament-2004',
                $event,
                $orga,
                $file,
                $bytes,
                'event-'.$event->id.'/screenshot-'.$file,
                'avatar',
                $label,
            );
        }

        $this->pinLanCopy(
            'ridge-racer-type-4',
            $event,
            $orga,
            'Ridge Racer Type 4 (USA).chd',
            357_817_282,
            'event-'.$event->id.'/screenshot-r4.chd',
        );

        if ($ut !== null) {
            $game = Game::query()->updateOrCreate(
                ['slug' => 'unreal-tournament-2004'],
                ['name' => 'Unreal Tournament 2004', 'min_team_size' => 1, 'max_team_size' => 16],
            );

            $link = ServerLink::query()->firstOrNew([
                'event_id' => $event->id,
                'title' => 'UT2004 · DM-Antalus',
            ]);
            $link->forceFill([
                'game_id' => $game->id,
                'status' => ServerLinkStatus::Ready,
                'manual' => true,
                'permanent' => false,
                'join_info' => new JoinInfo(address: 'lan.example', port: 7777, connectString: 'unreal://lan.example:7777'),
                // A recent A2S answer, so the row shows its map and players
                // rather than an empty "–" next to a green dot.
                'query_info' => [
                    'name' => 'UT2004 · DM-Antalus',
                    'map' => 'DM-Antalus',
                    'players' => 3,
                    'max_players' => 16,
                    'player_list' => [
                        ['name' => 'Teilnehmer 1', 'score' => 21, 'seconds' => 1840],
                        ['name' => 'Teilnehmer 2', 'score' => 14, 'seconds' => 1710],
                        ['name' => 'Screenshot Orga', 'score' => 9, 'seconds' => 1620],
                    ],
                ],
                'queried_at' => now()->subMinutes(1),
            ])->save();
        }
    }

    /**
     * An approved shared file on the demo event, pinned to the recipe's `iso`
     * requirement, so the game page and the files page agree that the game is
     * on the LAN. Returns the File row (only used as an existence guard) or
     * null when the recipe is not installed in this checkout.
     */
    private function pinLanCopy(
        string $slug,
        Event $event,
        User $orga,
        string $name,
        int $sizeBytes,
        string $path,
        string $role = 'iso',
        ?string $label = null,
    ): ?SharedFile {
        $recipe = GameRecipe::query()->where('slug', $slug)->first();

        if (! $recipe instanceof GameRecipe) {
            return null;
        }

        // SharedFile's fillable list deliberately excludes disk/path/visibility
        // (only UploadSharedFile writes them); updateOrCreate would drop them
        // and insert a row with a null path. forceFill is the seeder path.
        $shared = SharedFile::query()->firstOrNew([
            'event_id' => $event->id,
            'original_name' => $name,
        ]);
        $shared->forceFill([
            'user_id' => $orga->id,
            'disk' => 'local',
            'path' => $path,
            'size_bytes' => $sizeBytes,
            'mime' => 'application/octet-stream',
            'visibility' => FileVisibility::Approved,
            'reviewed_by' => $orga->id,
            'reviewed_at' => now(),
        ])->save();

        // Pin the exact requirement when a label is given (the avatar role
        // has one row per avatar); otherwise the first row of the role.
        $entry = GameRecipeFile::query()
            ->where('game_recipe_id', $recipe->id)
            ->where('role', $role)
            ->when($label !== null, fn ($query) => $query->where('label', $label))
            ->orderBy('sort')
            ->first();

        $entry?->forceFill(['shared_file_id' => $shared->id])->save();

        return $shared;
    }

    /**
     * The shared-game surfaces (game sharing, Nov 2026): three games the demo
     * orga shared on `/games/share`, the archives behind them, and the two
     * published ones as games with an event server of their own.
     *
     * One draft still waits for the review queue (its archive is pending), one
     * is published with its archive approved and on the LAN, and one is
     * published although its archive is gone — so the library, the server
     * browser's `lanReady` line, the files page's "Als Spiel teilen" and the
     * panel's file/PR states each have a row to show. Written directly
     * (firstOrNew + forceFill) instead of through `ShareGame`/`PublishRecipe`:
     * those dispatch domain events that queue pull-request and Discord jobs,
     * which a seeder must not start (see the class docblock).
     */
    private function seedSharedGames(): void
    {
        $event = Event::query()->where('slug', 'screenshot-demo')->first();

        if (! $event instanceof Event) {
            return;
        }

        $orga = $this->seededOrga();

        // A mistyped upload: a real archive whose name the share page refuses
        // (the colon), so the list can say why it is not selectable instead of
        // only disabling the row. Seeded first, so it sits below the two
        // usable archives.
        $this->seedShareArchive($event, $orga, 'Bunker Bash (alt): Version 1.zip', 121_000_000, FileVisibility::Pending);

        // 1. Published, archive gone: the upload the base row pinned was
        //    deleted later, so the row has no file ("Datei fehlt"), the server
        //    card says the files are not on the LAN, and the catalogue proposal
        //    was refused with a reason. Seeded first, so the share list (newest
        //    update first) shows it last.
        $pixel = $this->seedContribution(
            orga: $orga,
            slug: 'pixel-rally-99',
            title: 'Pixel Rally 99',
            summary: 'Kurze Rennen im geteilten Bildschirm, vier Strecken im Retro-Look.',
            archive: 'pixel-rally-99.zip',
            executable: 'PixelRally.exe',
            status: RecipeStatus::Published,
            inLineup: true,
        );

        $gone = $this->seedShareArchive($event, $orga, 'pixel-rally-99.zip', 74_000_000, FileVisibility::Approved);
        $this->pinContributionArchive($pixel, $gone);

        $pixel->forceFill([
            'pr_number' => null,
            'pr_url' => null,
            'pr_branch' => 'recipes/pixel-rally-99',
            'pr_state' => PullRequestState::Blocked,
            // The list shows the first finding in one line: keep a short,
            // real catalogue message so the queue reads without a cut-off.
            'pr_error' => 'SKILL.md front matter description is missing.',
            'pr_synced_at' => now()->subHours(2),
        ])->save();

        // The orga deleted the upload after the pin: the foreign key clears the
        // pin, exactly like removing the file on the files page does.
        $gone->delete();

        $this->seedSharedGameServer(
            event: $event,
            slug: 'pixel-rally-99',
            name: 'Pixel Rally 99',
            title: 'Pixel Rally · Werkstatt',
            map: 'pixel_rally_99',
            address: '203.0.113.21',
            port: 27016,
            status: ServerLinkStatus::NotResponding,
            players: 0,
        );

        // 2. Published, archive approved: installable from the LAN, with the
        //    pull request the orga's publish opened.
        $bunker = $this->seedContribution(
            orga: $orga,
            slug: 'bunker-bash-deluxe',
            title: 'Bunker Bash Deluxe',
            summary: 'Vier Spieler, ein Bunker, zu viele Granaten. Läuft nach dem Entpacken.',
            archive: 'bunker-bash-deluxe.tar.bz2',
            executable: 'BunkerBash.exe',
            status: RecipeStatus::Published,
            inLineup: true,
        );

        $this->pinContributionArchive(
            $bunker,
            $this->seedShareArchive($event, $orga, 'bunker-bash-deluxe.tar.bz2', 128_000_000, FileVisibility::Approved),
        );

        $bunker->forceFill([
            'pr_number' => 42,
            'pr_url' => 'https://github.com/example/lanomat/pull/42',
            'pr_branch' => 'recipes/bunker-bash-deluxe',
            'pr_state' => PullRequestState::Open,
            'pr_error' => null,
            'pr_synced_at' => now()->subMinutes(25),
        ])->save();

        $this->seedSharedGameServer(
            event: $event,
            slug: 'bunker-bash-deluxe',
            name: 'Bunker Bash Deluxe',
            title: 'Bunker Bash · Kesselhalle',
            map: 'bunker_halle',
            address: '203.0.113.20',
            port: 27015,
            status: ServerLinkStatus::Ready,
            players: 2,
            // A join URL the launcher may open after the start: the deep link
            // then names the server (`?url=`), which the website calls
            // "Im Launcher beitreten". Without it the link is the bare
            // "Im Launcher starten".
            joinUrl: 'https://lan.example/play/bunker-bash-deluxe',
        );

        // 3. Waiting for review: the pending archive is pinned to the draft.
        //    Seeded last, so the list opens on the game that still needs the
        //    orga, and its archive is the first row of the file picker.
        $neon = $this->seedContribution(
            orga: $orga,
            slug: 'neon-drift-arena',
            title: 'Neon Drift Arena',
            summary: 'Arcade-Driften auf vier Neon-Strecken — entpacken und sofort starten.',
            archive: 'neon-drift-arena.zip',
            executable: 'NeonDrift.exe',
            status: RecipeStatus::Draft,
            inLineup: false,
        );

        $this->pinContributionArchive(
            $neon,
            $this->seedShareArchive($event, $orga, 'neon-drift-arena.zip', 96_000_000, FileVisibility::Pending),
        );
    }

    /**
     * One shared game as `ShareGame`/`ContributeRecipe` would leave it: a
     * portable recipe whose single required base is the archive, one step that
     * checks the start file, a Windows launch target and a deliberately short
     * SKILL.md (the catalogue preview explains what still blocks it).
     *
     * The base file row starts unpinned; {@see pinContributionArchive()} pins
     * the archive the caller seeded.
     */
    private function seedContribution(
        User $orga,
        string $slug,
        string $title,
        string $summary,
        string $archive,
        string $executable,
        RecipeStatus $status,
        bool $inLineup,
    ): GameRecipe {
        $published = $status === RecipeStatus::Published;

        $recipe = GameRecipe::query()->firstOrNew(['slug' => $slug]);

        $recipe->fill([
            'title' => $title,
            'aliases' => null,
            'version' => '1.0',
            'status' => $status,
            'in_lineup' => $inLineup,
            'source' => GameRecipe::SOURCE_CONTRIBUTION,
            'source_ref' => 'web',
            'platforms' => ['windows', 'linux'],
            'summary' => $summary,
            'description' => $title.' läuft sofort nach dem Entpacken. Der Upload stammt von '
                .'einem Teilnehmer der LAN und wurde von der Orga geprüft.',
            'homepage' => null,
            'license' => 'Freeware, vom Teilnehmer bereitgestellt.',
            'skill_markdown' => $this->contributionSkill($slug, $title, $archive, $executable),
            'requirements' => [[
                'role' => RecipeFileRole::Base->value,
                'label' => 'Archiv',
                'os' => 'windows',
                'match' => [$archive],
                'required' => true,
                'notes' => 'Läuft unter Windows 10 oder neuer; unter Linux über Wine.',
                'tags' => ['arcade', 'splitscreen'],
            ]],
            'steps' => [[
                'id' => 'unpack',
                'title' => 'Archiv entpacken',
                'os' => 'any',
                'optional' => false,
                'detail' => 'Entpacke das Archiv in den Zielordner und prüfe die Startdatei.',
                'verify' => [['type' => 'file_exists', 'path' => '{{target}}/'.$executable]],
            ]],
            'metadata' => [
                'portable' => true,
                'players' => '2-8',
                'multiplayer' => true,
                'launch_targets' => [[
                    'id' => 'windows',
                    'label' => 'Windows',
                    'os' => 'windows',
                    'command' => '{{target}}/'.$executable,
                ]],
            ],
            'contributor_user_id' => $orga->id,
            'reviewed_by' => $published ? $orga->id : null,
            'reviewed_at' => $published ? now() : null,
        ])->save();

        // One file row per requirement, the way SyncRecipeFilesFromRequirements
        // keeps them: role + label is the stable key, the pin is a column.
        $file = GameRecipeFile::query()->firstOrNew([
            'game_recipe_id' => $recipe->id,
            'role' => RecipeFileRole::Base->value,
            'label' => 'Archiv',
        ]);

        $file->fill([
            'match_patterns' => [$archive],
            'required' => true,
            'sort' => 0,
            // The same human facts SyncRecipeFilesFromRequirements stores in the
            // row's metadata, so the panel and the exported YAML agree.
            'metadata' => [
                'os' => 'windows',
                'notes' => 'Läuft unter Windows 10 oder neuer; unter Linux über Wine.',
                'tags' => ['arcade', 'splitscreen'],
            ],
            'shared_file_id' => null,
        ])->save();

        return $recipe;
    }

    /** Pins the archive to the recipe's required base row (the install plan's file). */
    private function pinContributionArchive(GameRecipe $recipe, SharedFile $archive): void
    {
        $file = GameRecipeFile::query()
            ->where('game_recipe_id', $recipe->id)
            ->where('role', RecipeFileRole::Base->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        $file?->forceFill(['shared_file_id' => $archive->id])->save();
    }

    /**
     * An archive the demo orga uploaded to the event's file host. Created with
     * the other own files, but after them, so the newest-first lists (share
     * page, files page) open on the archives the pictures are about.
     */
    private function seedShareArchive(
        Event $event,
        User $orga,
        string $name,
        int $sizeBytes,
        FileVisibility $visibility,
    ): SharedFile {
        $shared = SharedFile::query()->firstOrNew([
            'event_id' => $event->id,
            'original_name' => $name,
        ]);

        $approved = $visibility === FileVisibility::Approved;

        $shared->forceFill([
            'user_id' => $orga->id,
            'disk' => 'local',
            'path' => 'event-'.$event->id.'/screenshot-'.$name,
            'size_bytes' => $sizeBytes,
            'mime' => 'application/octet-stream',
            'visibility' => $visibility,
            'reviewed_by' => $approved ? $orga->id : null,
            'reviewed_at' => $approved ? now() : null,
        ])->save();

        return $shared;
    }

    /** The shared game's short SKILL.md — honest, but far below the catalogue's 60 lines. */
    private function contributionSkill(string $slug, string $title, string $archive, string $executable): string
    {
        return implode("\n", [
            '---',
            'name: '.$slug,
            'description: Install '.$title.' from the LAN archive.',
            '---',
            '',
            '# '.$title,
            '',
            '## Install',
            '',
            '1. Download `'.$archive.'` from the LAN file host.',
            '2. Unpack it into the install folder.',
            '3. Start `'.$executable.'`.',
            '',
        ]);
    }

    /**
     * One published shared game as a server row: the game identity its recipe
     * matches (exact slug) plus the event-level server players join.
     */
    private function seedSharedGameServer(
        Event $event,
        string $slug,
        string $name,
        string $title,
        string $map,
        string $address,
        int $port,
        ServerLinkStatus $status,
        int $players,
        ?string $joinUrl = null,
    ): ServerLink {
        $game = Game::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'min_team_size' => 1, 'max_team_size' => 8],
        );

        $link = ServerLink::query()->firstOrNew([
            'event_id' => $event->id,
            'title' => $title,
        ]);

        $link->forceFill([
            'game_id' => $game->id,
            'status' => $status,
            'manual' => true,
            'permanent' => false,
            'join_info' => new JoinInfo(
                address: $address,
                port: $port,
                // A join URL the launcher can open, or the plain
                // `address:port` the player copies.
                connectString: $joinUrl ?? $address.':'.$port,
            ),
            'query_info' => [
                'name' => $title,
                'map' => $map,
                'players' => $players,
                'max_players' => 16,
                // The A2S player list a game server answers with; the same two
                // names Presence places on the Bunker Bash server.
                'player_list' => $players === 2
                    ? [
                        ['name' => 'Teilnehmer 4', 'score' => 12, 'seconds' => 940],
                        ['name' => 'Teilnehmer 5', 'score' => 7, 'seconds' => 880],
                    ]
                    : [],
            ],
            // A fresh answer, so the page shows it as a live row rather than a
            // server whose last answer is hours old.
            'queried_at' => now()->subMinutes(1),
        ])->save();

        return $link;
    }

    /**
     * Who the demo LAN places on which server, written through Presence's own
     * read model (`LocationSnapshotStore`) — not into another module's tables.
     * The server pages fill their "Hier spielen" line from this snapshot: three
     * accounts on the UT2004 server (the orga included), two on the Bunker Bash
     * server, and `Teilnehmer 3` opted out with `share_location = false`, so
     * that person is in neither line nor count.
     *
     * The snapshot lives in the cache for {@see LocationSnapshotStore::TTL_SECONDS}
     * (five minutes) and no scheduler runs next to the capture, so seed right
     * before capturing.
     */
    private function seedPresence(): void
    {
        $ut = ServerLink::query()->where('title', 'UT2004 · DM-Antalus')->first();
        $bunker = ServerLink::query()->where('title', 'Bunker Bash · Kesselhalle')->first();

        if (! $ut instanceof ServerLink || ! $bunker instanceof ServerLink) {
            return;
        }

        $orga = $this->seededOrga();

        $participants = User::query()
            ->whereIn('name', [
                'Teilnehmer 1', 'Teilnehmer 2', 'Teilnehmer 3', 'Teilnehmer 4', 'Teilnehmer 5',
            ])
            ->orderBy('id')
            ->get()
            ->keyBy('name');

        $hidden = $participants->get('Teilnehmer 3');

        if ($hidden instanceof User) {
            $hidden->forceFill(['share_location' => false])->save();
        }

        $utAddress = $ut->join_info->address ?? 'lan.example';
        $utPort = $ut->join_info->port ?? 7777;
        $bunkerAddress = $bunker->join_info->address ?? '203.0.113.20';
        $bunkerPort = $bunker->join_info->port ?? 27015;

        $place = static function (int $userId, int $minutes, string $game, string $title, string $address, int $port, int $linkId): PersonLocation {
            return new PersonLocation($userId, [], new GameLocation(
                GameLocation::SOURCE_QUERY,
                $game,
                $title,
                $address,
                $port,
                $linkId,
                now()->subMinutes($minutes)->toIso8601String(),
            ));
        };

        $people = [
            $orga->id => $place($orga->id, 37, 'Unreal Tournament 2004', 'UT2004 · DM-Antalus', $utAddress, $utPort, $ut->id),
        ];

        foreach (['Teilnehmer 1', 'Teilnehmer 2', 'Teilnehmer 3'] as $index => $name) {
            $user = $participants->get($name);

            if ($user instanceof User) {
                $people[$user->id] = $place($user->id, 18 + $index * 7, 'Unreal Tournament 2004', 'UT2004 · DM-Antalus', $utAddress, $utPort, $ut->id);
            }
        }

        foreach (['Teilnehmer 4', 'Teilnehmer 5'] as $index => $name) {
            $user = $participants->get($name);

            if ($user instanceof User) {
                $people[$user->id] = $place($user->id, 9 + $index * 4, 'Bunker Bash Deluxe', 'Bunker Bash · Kesselhalle', $bunkerAddress, $bunkerPort, $bunker->id);
            }
        }

        app(LocationSnapshotStore::class)->put(new LocationSnapshot(
            now()->toIso8601String(),
            $people,
        ));
    }

    /**
     * The browser end of the agent pairing flow: one pending device code with
     * the documented DEMO-2345 user code and two connected agents with honest
     * timestamps (created before last use, both inside the token TTL). The
     * docs captures need exactly this state, and the terminal capture needs a
     * working bearer token.
     */
    private function seedMcpPairing(): void
    {
        $orga = $this->seededOrga();

        McpDeviceCode::query()
            ->whereIn('user_code_hash', [
                McpCodes::hash('DEMO2345'),
                McpCodes::hash('DEMO-2345'),
            ])
            ->delete();

        $device = new McpDeviceCode;
        $device->forceFill([
            'device_code_hash' => McpCodes::hash('screenshot-demo-device'),
            'user_code_hash' => McpCodes::hash('DEMO2345'),
            'device_name' => 'pi · demo-laptop',
            'status' => McpDeviceCode::PENDING,
            'interval' => 5,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ])->save();

        $agents = [
            ['name' => 'pi · demo-laptop', 'token' => self::MCP_AGENT_TOKEN, 'created_days' => 3, 'used_minutes' => 12],
            ['name' => 'Claude Code · workshop-laptop', 'token' => self::MCP_AGENT_TOKEN.'-workshop', 'created_days' => 9, 'used_minutes' => 2 * 24 * 60],
        ];

        foreach ($agents as $agent) {
            $token = McpToken::query()
                ->where('user_id', $orga->id)
                ->where('name', $agent['name'])
                ->first() ?? new McpToken;

            $token->forceFill([
                'user_id' => $orga->id,
                'name' => $agent['name'],
                'token_hash' => McpCodes::hash($agent['token']),
                'abilities' => null,
                'last_used_at' => now()->subMinutes($agent['used_minutes']),
                'expires_at' => now()->addDays(90),
                'revoked_at' => null,
                'created_at' => now()->subDays($agent['created_days']),
                'updated_at' => now()->subMinutes($agent['used_minutes']),
            ])->save();
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
