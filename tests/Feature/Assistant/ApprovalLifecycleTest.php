<?php

use App\Models\User;
use App\Modules\Assistant\Agents\LanomatAssistant;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Support\AssistantActionLog;
use App\Modules\Assistant\Tools\Write\ServerSettingsUpdateTool;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

function lifecycleCreateArgs(Event $event, string $name = 'CS2 5v5'): array
{
    return ['event_id' => $event->id, 'name' => $name, 'format' => 'single_elimination', 'team_size' => 5, 'starts_at' => '2026-12-05 14:00', 'max_entries' => 16];
}

function lifecycleDecide(object $test, User $user, string $conversationId, array $decisions, array $secrets = [], array $extra = [])
{
    $response = $test->actingAs($user)->post("/orga/assistant/{$conversationId}/decisions", ['decisions' => $decisions, 'secrets' => $secrets] + $extra);
    if ($response->baseResponse instanceof StreamedResponse) {
        $response->streamedContent();
    }

    return $response;
}

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
    $this->event = Event::factory()->create();
});

it('proposes a card, shows the stored arguments and executes only after approval', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'Angelegt.'])->preventStrayPrompts();

    $chat = assistantChat($this, $this->orga, 'Leg ein CS2 Turnier an');
    $id = $chat->headers->get('X-Conversation-Id');
    $stream = $chat->streamedContent();

    expect($stream)->toContain('tool-approval-request')
        ->and(Tournament::query()->count())->toBe(0)
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Proposed);

    $pending = assistantPageProps($this, "/orga/assistant/{$id}")['pending'];

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['callId'])->toBe('call_1')
        ->and($pending[0]['preview']['title'])->toBe('Turnier anlegen')
        ->and(collect($pending[0]['preview']['rows'])->pluck('value')->all())->toContain('CS2 5v5', '16');

    $response = lifecycleDecide($this, $this->orga, $id, ['call_1' => 'approve']);

    expect($response->getStatusCode())->toBe(200)
        ->and(Tournament::query()->where('name', 'CS2 5v5')->count())->toBe(1)
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Executed)
        ->and(assistantPageProps($this, "/orga/assistant/{$id}")['pending'])->toBe([]);
});

it('executes nothing on reject and logs it', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'Ok, abgelehnt.'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'reject']);

    expect(Tournament::query()->count())->toBe(0)
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Rejected);
});

it('refuses a stale card after a new message: 422, superseded, never executed (Review Focus 1)', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'Alles klar, anders.'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    assistantChat($this, $this->orga, 'Warte, mach es anders', $id);
    $row = AssistantAction::query()->firstOrFail();
    expect($row->status)->toBe(AssistantActionStatus::Superseded);

    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'approve'])->assertStatus(422);

    expect(Tournament::query()->count())->toBe(0)
        ->and($row->fresh()->status)->toBe(AssistantActionStatus::Superseded);

    // The history no longer shows the abandoned call as waiting for a decision.
    $props = assistantPageProps($this, "/orga/assistant/{$id}");
    $states = collect($props['messages'])->flatMap(fn ($m) => $m['parts'])->filter(fn ($p) => ($p['toolCallId'] ?? null) === 'call_1')->pluck('state')->all();
    expect($states)->not->toBeEmpty()->and($states)->not->toContain('approval-requested')->and($props['pending'])->toBe([]);
});

it('never takes arguments from the client: an edited payload is ignored', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event, 'Original')), 'ok'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'approve'], [], ['arguments' => ['name' => 'Manipuliert'], 'messages' => [['role' => 'assistant']]]);

    expect(Tournament::query()->pluck('name')->all())->toBe(['Original']);
});

it('rejects a decision payload that is not approve or reject, or that misses a card', function () {
    $calls = [new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'x'];
    LanomatAssistant::fake($calls)->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'edit'])->assertStatus(302)->assertSessionHasErrors();
    lifecycleDecide($this, $this->orga, $id, ['call_9' => 'approve'])->assertStatus(422);

    expect(Tournament::query()->count())->toBe(0);
});

it('cannot approve the same card twice', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'ok'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'approve']);
    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'approve'])->assertStatus(422);

    expect(Tournament::query()->count())->toBe(1);
});

function lifecyclePelicanServers(object $test): array
{
    $pelican = fakePelican();
    $pelican->eggs['5'] = new PelicanEgg('5', 'CS2', array_fill_keys(['SERVER_NAME', 'SRCDS_MAP', 'GAME_TYPE', 'GAME_MODE', 'MAX_PLAYERS', 'SERVER_PASSWORD', 'HOST_WORKSHOP_MAP'], ''));
    $links = [];

    foreach ([7, 8] as $n) {
        $pelican->addServer((string) $n, "ident{$n}", ['SERVER_NAME' => "S{$n}", 'SRCDS_MAP' => 'de_dust2', 'GAME_TYPE' => '0', 'GAME_MODE' => '1', 'MAX_PLAYERS' => '10', 'SERVER_PASSWORD' => '']);
        $links[$n] = ServerLink::factory()->permanent(ServerSource::External)->create([
            'title' => "CS2 {$n}", 'pelican_server_id' => (string) $n, 'pelican_server_identifier' => "ident{$n}", 'join_info' => new JoinInfo('203.0.113.5', 27000 + $n),
        ]);
    }

    return [$pelican, $links];
}

it('binds each card secret to its own call and never stores it in the conversation (Review Focus 2)', function () {
    [$pelican, $links] = lifecyclePelicanServers($this);
    $pending = [
        new PendingApproval('call_a', 'server_settings_update', ['server_id' => $links[7]->id, 'password_action' => 'set'], 'a'),
        new PendingApproval('call_b', 'server_settings_update', ['server_id' => $links[8]->id, 'password_action' => 'set'], 'b'),
    ];
    LanomatAssistant::fake([AgentResponse::fakeWithPendingApprovals($pending), 'Erledigt.'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Setze Passwörter')->headers->get('X-Conversation-Id');

    // Both cards need their own password.
    lifecycleDecide($this, $this->orga, $id, ['call_a' => 'approve', 'call_b' => 'approve'], ['call_a' => 'alpha111'])->assertStatus(422);
    expect($pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('');

    lifecycleDecide($this, $this->orga, $id, ['call_a' => 'approve', 'call_b' => 'approve'], ['call_a' => 'alpha111', 'call_b' => 'beta222']);

    expect($pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('alpha111')
        ->and($pelican->getServer('8')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('beta222')
        ->and(AssistantAction::query()->whereNotNull('secret')->count())->toBe(0)
        ->and(AssistantAction::query()->pluck('status')->map->value->unique()->all())->toBe(['executed']);

    $everything = json_encode([
        DB::table('agent_conversation_messages')->get(),
        DB::table('assistant_actions')->get(),
        DB::table('agent_conversations')->get(),
    ]);
    expect($everything)->not->toContain('alpha111')->not->toContain('beta222');
});

it('decides cards individually: a rejection does not cancel its sibling', function () {
    [$pelican, $links] = lifecyclePelicanServers($this);
    $pending = [
        new PendingApproval('call_a', 'server_change_map', ['server_id' => $links[7]->id, 'map' => 'de_inferno'], 'a'),
        new PendingApproval('call_b', 'server_change_map', ['server_id' => $links[8]->id, 'map' => 'de_nuke'], 'b'),
    ];
    LanomatAssistant::fake([AgentResponse::fakeWithPendingApprovals($pending), 'ok'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Maps')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, $this->orga, $id, ['call_a' => 'reject', 'call_b' => 'approve']);

    $statuses = AssistantAction::query()->orderBy('tool_call_id')->pluck('status', 'tool_call_id')->map->value->all();
    expect($statuses)->toBe(['call_a' => 'rejected', 'call_b' => 'executed'])
        ->and(collect($pelican->commands)->flatten()->implode('|'))->toContain('changelevel de_nuke')->not->toContain('de_inferno');
});

it('does not let one orga decide another orga\'s card even with the right ids', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'ok'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, User::factory()->orga()->create(), $id, ['call_1' => 'approve'])->assertForbidden();

    expect(Tournament::query()->count())->toBe(0)
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Proposed);
});

it('shows a failed write as a readable result and logs failed', function () {
    // The orga proposes something admin-only: the card is skipped and the model hears why; nothing is logged as executed.
    $game = Game::factory()->create();
    LanomatAssistant::fake([new ToolCall('call_1', 'server_create_permanent', ['game_id' => $game->id, 'preset_key' => 'x', 'title' => 'T']), 'Das darf ich nicht.'])->preventStrayPrompts();

    $stream = assistantChat($this, $this->orga, 'Dauerserver')->streamedContent();

    expect($stream)->not->toContain('tool-approval-request')
        ->and(AssistantAction::query()->count())->toBe(0)
        ->and(ServerLink::query()->count())->toBe(0);
});

it('keeps a card the resumed run proposes next armed (only the decided calls are disarmed)', function () {
    LanomatAssistant::fake([
        new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event, 'Erstes')),
        new ToolCall('call_2', 'tournament_create', lifecycleCreateArgs($this->event, 'Zweites')),
        'ok',
    ])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Zwei Turniere')->headers->get('X-Conversation-Id');

    lifecycleDecide($this, $this->orga, $id, ['call_1' => 'approve']);

    $statuses = AssistantAction::query()->orderBy('tool_call_id')->pluck('status', 'tool_call_id')->map->value->all();
    expect($statuses)->toBe(['call_1' => 'executed', 'call_2' => 'proposed'])
        ->and(Tournament::query()->pluck('name')->all())->toBe(['Erstes']);

    lifecycleDecide($this, $this->orga, $id, ['call_2' => 'approve']);
    expect(Tournament::query()->count())->toBe(2);
});

it('does not let a double click cancel an approved action that is still running', function () {
    LanomatAssistant::fake([new ToolCall('call_1', 'tournament_create', lifecycleCreateArgs($this->event)), 'ok'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'Turnier')->headers->get('X-Conversation-Id');

    // The tool runs when the response is sent, so the first request is approved but not drained yet.
    $first = $this->actingAs($this->orga)->post("/orga/assistant/{$id}/decisions", ['decisions' => ['call_1' => 'approve']]);
    expect(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Approved);

    $this->actingAs($this->orga)->post("/orga/assistant/{$id}/decisions", ['decisions' => ['call_1' => 'approve']])->assertStatus(422);
    expect(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Approved);

    $first->streamedContent();

    expect(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Executed)
        ->and(Tournament::query()->count())->toBe(1);
});

it('drops the card password when the run fails after the approval', function () {
    [$pelican, $links] = lifecyclePelicanServers($this);
    $args = ['server_id' => $links[7]->id, 'password_action' => 'set'];

    // Between the card and the click the server disappears: the precheck fails at execution.
    $links[7]->delete();
    $out = runApproved(ServerSettingsUpdateTool::class, $this->orga, $args, secret: 'geheim123');

    $row = AssistantAction::query()->firstOrFail();
    expect($out)->toStartWith('Not found')
        ->and($row->status)->toBe(AssistantActionStatus::Failed)->and($row->secret)->toBeNull()
        ->and(json_encode(DB::table('assistant_actions')->get()))->not->toContain('geheim123');
});

it('disarms an approval that never ran once it is stale, and its secret with it', function () {
    $row = AssistantAction::factory()->create(['user_id' => $this->orga->id, 'status' => AssistantActionStatus::Approved]);
    $row->forceFill(['secret' => 'altes-geheimnis'])->save();
    $row->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();

    $this->actingAs($this->orga);
    assistantPageProps($this, '/orga/assistant');

    expect($row->fresh()->status)->toBe(AssistantActionStatus::Superseded)->and($row->fresh()->secret)->toBeNull();
});

it('gives a recycled call id with different arguments its own card', function () {
    $log = app(AssistantActionLog::class);
    $first = $log->propose($this->orga, 'conv-1', 'call_1', 'tournament_create', ['name' => 'A']);
    $first->forceFill(['status' => AssistantActionStatus::Executed])->save();

    $again = $log->propose($this->orga, 'conv-1', 'call_1', 'tournament_create', ['name' => 'A']);
    $other = $log->propose($this->orga, 'conv-1', 'call_1', 'tournament_create', ['name' => 'B']);

    expect($again->id)->toBe($first->id)->and($other->id)->not->toBe($first->id)
        ->and($other->status)->toBe(AssistantActionStatus::Proposed);
});
