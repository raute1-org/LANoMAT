<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

/**
 * @return array{GameMatch, User, User}
 */
function reportedMatch(): array
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);
    $tournament = app(StartTournament::class)->handle($tournament)->fresh();

    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $reporter = User::find(TournamentEntry::find($match->entry1_id)->user_id);
    $opponent = User::find(TournamentEntry::find($match->entry2_id)->user_id);

    test()->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])->assertRedirect();

    return [$match->fresh(), $reporter, $opponent];
}

it('answers a stale lock_version on confirm with a friendly redirect instead of a 500', function () {
    [$match, , $opponent] = reportedMatch();
    // Someone else (override / concurrent confirm) moved the row on.
    $stale = $match->lock_version;
    GameMatch::whereKey($match->id)->increment('lock_version');

    $response = $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $stale]);

    $response->assertRedirect();
    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $response->assertSessionHas('inertia.flash_data.toast.message', 'Das Ergebnis wurde inzwischen geändert. Seite neu geladen.');

    expect($match->fresh()->status->value)->toBe('reported')
        ->and(MatchReport::where('match_id', $match->id)->where('status', 'pending')->exists())->toBeTrue();
});

it('does not change state on a second confirm of an already confirmed result', function () {
    [$match, , $opponent] = reportedMatch();
    $lock = $match->lock_version;

    $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $lock])->assertRedirect();
    $after = $match->fresh();
    expect($after->status->value)->toBe('completed');

    $second = $this->actingAs($opponent)->post("/matches/{$match->id}/confirm", ['lock_version' => $lock]);
    expect($second->getStatusCode())->toBeIn([302, 404]);
    expect($match->fresh()->lock_version)->toBe($after->lock_version);
});

it('rejects out-of-range and negative scores with a validation error', function (mixed $score1, mixed $score2) {
    [$match, $reporter] = reportedMatch();
    MatchReport::where('match_id', $match->id)->delete();
    $match->update(['status' => 'ready']);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => $score1, 'score2' => $score2])
        ->assertInvalid();

    expect(MatchReport::where('match_id', $match->id)->exists())->toBeFalse();
})->with([
    'overflow' => [2147483648, 1],
    'overflow second' => [1, 2147483648],
    'above cap' => [10000, 1],
    'negative' => [-1, 3],
]);

it('rejects an overflowing lock_version on confirm', function () {
    [$match, , $opponent] = reportedMatch();

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => 2147483648])
        ->assertInvalid(['lock_version']);
});
