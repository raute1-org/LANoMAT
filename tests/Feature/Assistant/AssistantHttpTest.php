<?php

use App\Models\User;
use App\Modules\Assistant\Agents\LanomatAssistant;
use App\Modules\Assistant\Models\AssistantAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
});

it('keeps participants and helpers out of every endpoint', function (string $role) {
    $user = $role === 'helper' ? User::factory()->helper()->create() : User::factory()->create();
    $uuid = '018f0000-0000-7000-8000-000000000000';

    $this->actingAs($user);
    $this->get('/orga/assistant')->assertForbidden();
    $this->post('/orga/assistant/chat', ['message' => 'hi'])->assertForbidden();
    $this->get("/orga/assistant/{$uuid}")->assertStatus(404);
    $this->post("/orga/assistant/{$uuid}/decisions", ['decisions' => ['c' => 'approve']])->assertStatus(404);
})->with(['participant', 'helper']);

it('streams an answer, keeps the conversation and tells the client its id', function () {
    LanomatAssistant::fake(['Hallo Orga'])->preventStrayPrompts();

    $response = assistantChat($this, $this->orga, 'Hallo');

    $id = $response->headers->get('X-Conversation-Id');
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no')
        ->and($response->headers->get('Cache-Control'))->toContain('no-transform')
        ->and($response->streamedContent())->toContain('Hallo')->toContain('Orga')
        ->and(Conversation::query()->findOrFail($id)->participant_id)->toBe($this->orga->id)
        ->and(ConversationMessage::query()->where('conversation_id', $id)->pluck('role')->all())->toBe(['user', 'assistant']);
});

it('prefixes the current time to the prompt but not to the shown history', function () {
    LanomatAssistant::fake(['ok'])->preventStrayPrompts();

    $id = assistantChat($this, $this->orga, 'Wie spät?')->headers->get('X-Conversation-Id');
    $stored = ConversationMessage::query()->where('conversation_id', $id)->where('role', 'user')->value('content');

    expect($stored)->toStartWith('[Jetzt: ')->toContain('Wie spät?');

    $props = assistantPageProps($this, "/orga/assistant/{$id}");

    expect(json_encode($props['messages'], JSON_UNESCAPED_UNICODE))->not->toContain('Jetzt:')->toContain('Wie spät?');
});

it('continues an own conversation', function () {
    LanomatAssistant::fake(['eins', 'zwei'])->preventStrayPrompts();

    $id = assistantChat($this, $this->orga, 'a')->headers->get('X-Conversation-Id');
    assistantChat($this, $this->orga, 'b', $id);

    expect(ConversationMessage::query()->where('conversation_id', $id)->count())->toBe(4);
});

it('gives a foreign conversation 403 on page, chat, decision and delete, and changes nothing', function () {
    LanomatAssistant::fake(['von A'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'geheim')->headers->get('X-Conversation-Id');
    $intruder = User::factory()->orga()->create();
    $admin = User::factory()->admin()->create();
    $messages = ConversationMessage::query()->count();

    foreach ([$intruder, $admin] as $other) {
        $this->actingAs($other);
        $this->get("/orga/assistant/{$id}")->assertForbidden();
        $this->post('/orga/assistant/chat', ['message' => 'mitlesen', 'conversation_id' => $id])->assertForbidden();
        $this->post("/orga/assistant/{$id}/decisions", ['decisions' => ['call_1' => 'approve']])->assertForbidden();
        $this->delete("/orga/assistant/{$id}")->assertForbidden();
    }

    expect(ConversationMessage::query()->count())->toBe($messages)
        ->and(Conversation::query()->whereKey($id)->exists())->toBeTrue();
});

it('answers an unknown conversation id with 404', function () {
    $this->actingAs($this->orga);

    $this->get('/orga/assistant/018f0000-0000-7000-8000-000000000000')->assertNotFound();
    $this->post('/orga/assistant/chat', ['message' => 'x', 'conversation_id' => '018f0000-0000-7000-8000-000000000000'])->assertNotFound();
});

it('lists only the own conversations on the page', function () {
    LanomatAssistant::fake(['a', 'b'])->preventStrayPrompts();
    assistantChat($this, $this->orga, 'meine');
    assistantChat($this, User::factory()->orga()->create(), 'fremde');

    $props = assistantPageProps($this->actingAs($this->orga), '/orga/assistant');

    expect($props['conversations'])->toHaveCount(1)->and($props['conversations'][0]['title'])->toBe('meine')
        ->and($props['provider']['name'])->toBe(config('assistant.provider'));
});

it('deletes an own conversation but keeps its audit rows', function () {
    LanomatAssistant::fake(['x'])->preventStrayPrompts();
    $id = assistantChat($this, $this->orga, 'weg')->headers->get('X-Conversation-Id');
    $audit = AssistantAction::factory()->create(['user_id' => $this->orga->id, 'conversation_id' => $id]);

    $this->actingAs($this->orga)->delete("/orga/assistant/{$id}")->assertNoContent();

    expect(Conversation::query()->whereKey($id)->exists())->toBeFalse()
        ->and(ConversationMessage::query()->where('conversation_id', $id)->count())->toBe(0)
        ->and(AssistantAction::query()->whereKey($audit->id)->exists())->toBeTrue();
});

it('validates the message', function () {
    $this->actingAs($this->orga)->post('/orga/assistant/chat', ['message' => ''])->assertSessionHasErrors('message');
    $this->actingAs($this->orga)->post('/orga/assistant/chat', ['message' => str_repeat('x', 4001)])->assertSessionHasErrors('message');
});

it('throttles the chat per user (request 21 within a minute gives 429)', function () {
    config(['assistant.throttle_per_minute' => 20]);
    LanomatAssistant::fake(fn () => 'ok')->preventStrayPrompts();
    $this->actingAs($this->orga);

    foreach (range(1, 20) as $i) {
        $this->post('/orga/assistant/chat', ['message' => "n{$i}"])->streamedContent();
    }

    $this->post('/orga/assistant/chat', ['message' => 'one too many'])->assertStatus(429);
    // The limit is per user: another orga is not affected.
    $this->actingAs(User::factory()->orga()->create())->post('/orga/assistant/chat', ['message' => 'fine'])->assertOk();
});

it('registers the routes only while the module is on', function () {
    $routes = function (array $env): string {
        $p = new Process([PHP_BINARY, 'artisan', 'route:list', '--path=orga/assistant', '--json'], base_path(), ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing', ...$env]);
        $p->mustRun();

        return $p->getOutput();
    };

    expect($routes([]))->not->toContain('orga.assistant.chat')
        ->and($routes(['MODULE_ASSISTANT' => 'true']))->toContain('orga.assistant.chat');
});

it('uses a conversation table row per start and no orphaned audit state', function () {
    LanomatAssistant::fake(['x'])->preventStrayPrompts();
    assistantChat($this, $this->orga, 'eins');

    expect(DB::table('assistant_actions')->count())->toBe(0);
});

it('answers the real client with JSON errors instead of redirects', function () {
    $headers = ['Accept' => 'application/json, text/event-stream', 'X-Requested-With' => 'XMLHttpRequest'];

    $this->actingAs($this->orga)->withHeaders($headers)->postJson('/orga/assistant/chat', ['message' => ''])
        ->assertStatus(422)->assertJsonValidationErrors('message');

    auth()->logout();
    $this->withHeaders($headers)->postJson('/orga/assistant/chat', ['message' => 'hi'])->assertStatus(401);
});
