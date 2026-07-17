<?php

namespace Tests\Feature;

use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use Database\Seeders\ScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenshotSeederTest extends TestCase
{
    use RefreshDatabase;

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
