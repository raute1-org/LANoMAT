<?php

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Support\ArgumentsHash;
use App\Modules\Assistant\Tools\Write\TournamentAdvanceTool;
use App\Modules\Assistant\Tools\Write\TournamentCreateTool;
use App\Modules\Assistant\Tools\Write\TournamentUpdateTool;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function createArgs(Event $event, array $overrides = []): array
{
    return array_merge([
        'event_id' => $event->id, 'name' => 'CS2 5v5', 'format' => 'single_elimination', 'team_size' => 5,
        'starts_at' => '2026-12-05 14:00', 'max_entries' => 16,
    ], $overrides);
}

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
    $this->event = Event::factory()->create();
});

it('asks for approval with a preview of the stored arguments', function () {
    $tool = new TournamentCreateTool($this->orga);
    $args = createArgs($this->event, ['checkin_opens_at' => '2026-12-05 13:30', 'checkin_closes_at' => '2026-12-05 13:55']);

    $approval = approvalFor($tool, $args);
    $preview = $tool->preview($args)->toArray();

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and(collect($preview['rows'])->pluck('value')->all())->toContain('CS2 5v5', '16', '2026-12-05 14:00', '2026-12-05 13:30');
});

it('creates the draft only after approval and converts Berlin time to UTC', function () {
    $out = runApproved(TournamentCreateTool::class, $this->orga, createArgs($this->event));

    $tournament = Tournament::query()->where('name', 'CS2 5v5')->firstOrFail();
    expect(json_decode($out, true))->toMatchArray(['created' => true, 'tournament_id' => $tournament->id])
        ->and($tournament->status)->toBe(TournamentStatus::Draft)
        ->and($tournament->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-05 13:00')
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Executed);
});

it('does nothing without an approved row, with a different hash, or for another user', function () {
    $args = createArgs($this->event);
    $tool = new TournamentCreateTool($this->orga);

    // no row at all
    expect($tool->handle(new Request($args, 'call_1')))->toBe(__('assistant.not_approved'));

    // approved, but for different arguments (a card showing something else)
    AssistantAction::factory()->create([
        'user_id' => $this->orga->id, 'tool' => 'tournament_create', 'tool_call_id' => 'call_1',
        'arguments_hash' => ArgumentsHash::of('tournament_create', createArgs($this->event, ['name' => 'Other'])),
    ])->forceFill(['status' => AssistantActionStatus::Approved])->save();
    expect($tool->handle(new Request($args, 'call_1')))->toBe(__('assistant.not_approved'));

    // approved for someone else
    $other = User::factory()->orga()->create();
    AssistantAction::factory()->create([
        'user_id' => $other->id, 'tool' => 'tournament_create', 'tool_call_id' => 'call_2', 'arguments_hash' => ArgumentsHash::of('tournament_create', $args),
    ])->forceFill(['status' => AssistantActionStatus::Approved])->save();
    expect($tool->handle(new Request($args, 'call_2')))->toBe(__('assistant.not_approved'))
        ->and(Tournament::query()->count())->toBe(0);
});

it('can only be executed once per approval', function () {
    $args = createArgs($this->event);
    runApproved(TournamentCreateTool::class, $this->orga, $args);

    expect((string) (new TournamentCreateTool($this->orga))->handle(new Request($args, 'call_1')))->toBe(__('assistant.not_approved'))
        ->and(Tournament::query()->count())->toBe(1);
});

it('skips the card and explains why when the arguments cannot work', function () {
    $tool = new TournamentCreateTool($this->orga);

    expect(approvalFor($tool, createArgs($this->event, ['event_id' => 99999])))->toBeNull()
        ->and((string) $tool->handle(new Request(createArgs($this->event, ['event_id' => 99999]), 'c')))->toStartWith('Not found')
        ->and((string) $tool->handle(new Request(createArgs($this->event, ['starts_at' => 'gestern früh']), 'c')))->toContain('starts_at')
        ->and((string) $tool->handle(new Request(createArgs($this->event, ['team_size' => 99]), 'c')))->toContain('team_size');
});

it('refuses a participant', function () {
    $participant = User::factory()->create();

    expect(runApproved(TournamentCreateTool::class, $participant, createArgs($this->event)))->toStartWith('Forbidden')
        ->and(Tournament::query()->count())->toBe(0);
});

it('updates a draft and shows old to new in the preview', function () {
    $tournament = Tournament::factory()->create(['name' => 'Alt', 'max_entries' => 8]);
    $args = ['tournament_id' => $tournament->id, 'max_entries' => 16, 'game_id' => Game::factory()->create()->id];

    $preview = (new TournamentUpdateTool($this->orga))->preview($args)->toArray();
    $out = runApproved(TournamentUpdateTool::class, $this->orga, $args);

    expect(collect($preview['rows'])->pluck('value')->all())->toContain('8 → 16')
        ->and(json_decode($out, true))->toMatchArray(['updated' => true])
        ->and($tournament->fresh()->max_entries)->toBe(16);
});

it('refuses to update a live tournament without a card', function () {
    $tournament = Tournament::factory()->live()->create();

    expect(approvalFor(new TournamentUpdateTool($this->orga), ['tournament_id' => $tournament->id, 'name' => 'x']))->toBeNull()
        ->and((string) (new TournamentUpdateTool($this->orga))->handle(new Request(['tournament_id' => $tournament->id, 'name' => 'x'], 'c')))
        ->toBe(__('tournaments.errors.not_editable'));
});

it('answers open_enrollment with missing setup fields with the translated check, not a ref id', function () {
    $tournament = Tournament::factory()->create();
    $tool = new TournamentAdvanceTool($this->orga);
    $args = ['tournament_id' => $tournament->id, 'step' => 'open_enrollment'];

    $out = (string) $tool->handle(new Request($args, 'c'));

    expect(approvalFor($tool, $args))->toBeNull()
        ->and($out)->toContain('fehlen noch')->not->toContain('ref ')
        ->and($tournament->fresh()->status)->toBe(TournamentStatus::Draft);
});

it('advances a complete tournament and marks start as dangerous', function () {
    $tournament = Tournament::factory()->create([
        'game_id' => Game::factory()->create()->id, 'max_entries' => 8,
        'checkin_opens_at' => now()->addDay(), 'checkin_closes_at' => now()->addDay()->addHour(),
    ]);
    $args = ['tournament_id' => $tournament->id, 'step' => 'announce'];

    $out = runApproved(TournamentAdvanceTool::class, $this->orga, $args);

    expect(json_decode($out, true)['status'])->toBe('announced')
        ->and((new TournamentAdvanceTool($this->orga))->preview(['tournament_id' => $tournament->id, 'step' => 'start'])->danger)->toBeTrue()
        ->and((new TournamentAdvanceTool($this->orga))->preview($args)->danger)->toBeFalse();
});

it('marks the audit row failed when the action throws', function () {
    $tournament = Tournament::factory()->live()->create();
    $args = ['tournament_id' => $tournament->id, 'step' => 'reopen_draft'];

    $out = runApproved(TournamentAdvanceTool::class, $this->orga, $args);

    expect($out)->toBe(__('tournaments.errors.cannot_return_to_draft'))
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Failed);
});
