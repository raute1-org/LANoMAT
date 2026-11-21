<?php

use App\Models\User;
use App\Modules\Assistant\Agents\LanomatAssistant;
use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

it('renders the assistant page for an orga with the server-built props', function () {
    $this->actingAs(User::factory()->orga()->create())
        ->get('/orga/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orga/Assistant')
            ->where('conversations', [])
            ->where('conversationId', null)
            ->where('messages', [])
            ->where('pending', [])
            ->has('provider.name'));
});

it('keeps the page closed to participants and helpers', function (string $role) {
    $user = $role === 'helper' ? User::factory()->helper()->create() : User::factory()->create();

    $this->actingAs($user)->get('/orga/assistant')->assertForbidden();
})->with(['participant', 'helper']);

it('exposes a pending card preview built by the server, not model text', function () {
    $orga = User::factory()->orga()->create();
    $event = Event::factory()->create();
    LanomatAssistant::fake([
        new ToolCall('call_1', 'tournament_create', [
            'event_id' => $event->id, 'name' => 'Server Cup', 'format' => 'single_elimination', 'team_size' => 1, 'starts_at' => '2026-12-05 14:00',
        ]),
    ])->preventStrayPrompts();

    $id = assistantChat($this, $orga, 'Turnier')->headers->get('X-Conversation-Id');

    $this->actingAs($orga)->get("/orga/assistant/{$id}")->assertInertia(fn (Assert $page) => $page
        ->component('Orga/Assistant')
        ->where('pending.0.callId', 'call_1')
        ->where('pending.0.preview.title', 'Turnier anlegen')
        ->where('pending.0.decidable', true)
        ->where('pending.0.preview.needsSecret', false));

    expect(AssistantAction::query()->count())->toBe(1);
});
