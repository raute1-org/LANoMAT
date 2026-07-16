<?php

use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts a winner SceneOverride on the event channel for a decisive finals match', function () {
    Event::fake([SceneOverride::class]);

    $tournament = Tournament::factory()->create([
        'name' => 'Valorant Cup',
    ]);

    $champion = TournamentEntry::factory()->team()->for($tournament)->create([
        'display_name' => 'Team Alpha',
    ]);
    $runnerUp = TournamentEntry::factory()->team()->for($tournament)->create([
        'display_name' => 'Team Beta',
    ]);

    $match = GameMatch::factory()->for($tournament)->create([
        'bracket' => Bracket::Finals->value,
        'entry1_id' => $champion->id,
        'entry2_id' => $runnerUp->id,
        'next_match_id' => null,
        'status' => MatchStatus::Completed,
        'winner_entry_id' => $champion->id,
    ]);

    $tournament->update([
        'status' => TournamentStatus::Finished,
        'winner_entry_id' => $champion->id,
    ]);

    event(new MatchCompleted($match));

    Event::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($tournament, $champion) {
        return $dispatched->eventId === $tournament->event_id
            && $dispatched->scene['type'] === 'winner'
            && $dispatched->scene['data']['winner'] === $champion->display_name;
    });

    Event::assertDispatchedTimes(SceneOverride::class, 1);
});

it('does nothing for a non-final completed match', function () {
    Event::fake([SceneOverride::class]);

    $tournament = Tournament::factory()->create();

    $entry1 = TournamentEntry::factory()->team()->for($tournament)->create();
    $entry2 = TournamentEntry::factory()->team()->for($tournament)->create();

    $nextMatch = GameMatch::factory()->for($tournament)->create([
        'bracket' => Bracket::Winners->value,
        'round' => 2,
    ]);

    $match = GameMatch::factory()->for($tournament)->create([
        'bracket' => Bracket::Winners->value,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'next_match_id' => $nextMatch->id,
        'status' => MatchStatus::Completed,
        'winner_entry_id' => $entry1->id,
    ]);

    event(new MatchCompleted($match));

    Event::assertNotDispatched(SceneOverride::class);
});
