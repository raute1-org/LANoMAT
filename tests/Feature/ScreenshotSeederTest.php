<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Support\LocationSnapshotStore;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use Database\Seeders\ScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreenshotSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder writes real (placeholder) JPEG bytes for the gallery
        // photos; keep that off the real disk in tests.
        Storage::fake('local');
    }

    public function test_running_the_seeder_twice_is_idempotent(): void
    {
        (new ScreenshotSeeder)->run();
        (new ScreenshotSeeder)->run();

        $this->assertSame(1, Event::query()->where('slug', 'screenshot-demo')->count());
    }

    public function test_seeder_produces_a_live_tournament_with_a_ready_match(): void
    {
        (new ScreenshotSeeder)->run();

        $event = Event::query()->where('slug', 'screenshot-demo')->firstOrFail();

        $tournament = Tournament::query()->where('event_id', $event->id)->first();

        $this->assertNotNull($tournament);
        $this->assertSame(TournamentStatus::Live, $tournament->status);

        $readyMatches = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('status', MatchStatus::Ready)
            ->count();

        $this->assertGreaterThanOrEqual(1, $readyMatches);
    }

    public function test_seeder_produces_an_approved_shared_file(): void
    {
        (new ScreenshotSeeder)->run();

        $event = Event::query()->where('slug', 'screenshot-demo')->firstOrFail();

        $approvedFiles = SharedFile::query()
            ->where('event_id', $event->id)
            ->where('visibility', FileVisibility::Approved)
            ->count();

        $this->assertGreaterThanOrEqual(1, $approvedFiles);
    }

    public function test_seeder_produces_an_open_poll(): void
    {
        (new ScreenshotSeeder)->run();

        $event = Event::query()->where('slug', 'screenshot-demo')->firstOrFail();

        $poll = Poll::query()->where('event_id', $event->id)->where('status', PollStatus::Open)->first();

        $this->assertNotNull($poll);
        $this->assertGreaterThanOrEqual(2, $poll->options()->count());
    }

    public function test_running_the_seeder_twice_does_not_duplicate_child_rows(): void
    {
        (new ScreenshotSeeder)->run();
        (new ScreenshotSeeder)->run();

        $event = Event::query()->where('slug', 'screenshot-demo')->firstOrFail();

        $this->assertSame(1, Tournament::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, Poll::query()->where('event_id', $event->id)->count());
    }

    public function test_seeder_produces_a_food_order_with_a_deterministic_pinned_menu(): void
    {
        (new ScreenshotSeeder)->run();

        $event = Event::query()->where('slug', 'screenshot-demo')->firstOrFail();

        $foodOrder = FoodOrder::query()->where('event_id', $event->id)->firstOrFail();

        // The factory default (`FoodOrderFactory::defaultMenu()`) flips an
        // unseeded coin between 2 and 3 options — a regression to that
        // default would make this count flaky (2 or 3) instead of fixed.
        $this->assertCount(3, $foodOrder->menu);
        $this->assertSame(
            ['pizza_margherita', 'pizza_salami', 'salad'],
            array_map(fn ($option) => $option->key, $foodOrder->menu),
        );
    }

    public function test_seeder_produces_the_three_shared_game_states(): void
    {
        (new ScreenshotSeeder)->run();

        $draft = GameRecipe::query()->where('slug', 'neon-drift-arena')->firstOrFail();

        // Waiting for the review queue: pending archive, no pull request yet.
        $this->assertSame(RecipeStatus::Draft, $draft->status);
        $this->assertSame(FileVisibility::Pending, $draft->files()->first()?->sharedFile?->visibility);
        $this->assertNull($draft->pr_state);

        $published = GameRecipe::query()->where('slug', 'bunker-bash-deluxe')->firstOrFail();

        // Publishable install: an approved archive on the LAN and its PR open.
        $this->assertSame(RecipeStatus::Published, $published->status);
        $this->assertSame(FileVisibility::Approved, $published->files()->first()?->sharedFile?->visibility);
        $this->assertSame(PullRequestState::Open, $published->pr_state);

        $gone = GameRecipe::query()->where('slug', 'pixel-rally-99')->firstOrFail();

        // The archive vanished: no pin, a blocked proposal with its reason.
        $this->assertNull($gone->files()->first()?->shared_file_id);
        $this->assertSame(PullRequestState::Blocked, $gone->pr_state);
        $this->assertNotNull($gone->pr_error);
    }

    public function test_running_the_seeder_twice_keeps_one_row_per_shared_game(): void
    {
        (new ScreenshotSeeder)->run();
        (new ScreenshotSeeder)->run();

        $this->assertSame(3, GameRecipe::query()
            ->whereIn('slug', ['neon-drift-arena', 'bunker-bash-deluxe', 'pixel-rally-99'])
            ->count());

        $this->assertSame(2, ServerLink::query()
            ->whereIn('title', ['Bunker Bash · Kesselhalle', 'Pixel Rally · Werkstatt'])
            ->count());
    }

    public function test_seeder_places_participants_on_a_server_and_hides_one_location(): void
    {
        (new ScreenshotSeeder)->run();

        $snapshot = app(LocationSnapshotStore::class)->get();

        $this->assertNotNull($snapshot);

        $bunker = ServerLink::query()->where('title', 'Bunker Bash · Kesselhalle')->firstOrFail();

        $located = array_values(array_filter(
            $snapshot->people,
            static fn (PersonLocation $person): bool => $person->game?->linkId === $bunker->id,
        ));

        // Two accounts on the Bunker Bash server; the orga plays UT2004.
        $this->assertCount(2, $located);

        $hidden = User::query()->where('name', 'Teilnehmer 3')->firstOrFail();

        // Opted out, but still located: Presence's visibility rule hides them.
        $this->assertFalse($hidden->share_location);
        $this->assertArrayHasKey($hidden->id, $snapshot->people);
    }

    public function test_seeder_is_a_no_op_in_the_production_environment(): void
    {
        $this->app['env'] = 'production';

        try {
            (new ScreenshotSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, Event::query()->where('slug', 'screenshot-demo')->count());
    }
}
