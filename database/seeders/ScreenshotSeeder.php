<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

            // Catering: an open food order with the default pizza menu.
            FoodOrder::factory()->open()->create([
                'event_id' => $event->id,
                'title' => 'Mitternachts-Pizza',
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
        });
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
