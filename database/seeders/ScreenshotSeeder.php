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

        GameRecipe::query()
            ->whereIn('slug', self::LIBRARY_SLUGS)
            ->update(['status' => RecipeStatus::Published->value]);

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
