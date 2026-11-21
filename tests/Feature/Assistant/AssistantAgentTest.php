<?php

use App\Models\User;
use App\Modules\Assistant\Agents\LanomatAssistant;
use App\Modules\Assistant\Support\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds the tool set for its actor only', function () {
    $orga = User::factory()->orga()->create();
    $agent = LanomatAssistant::make($orga);
    $names = collect($agent->tools())->map->name()->all();

    expect($names)->toBe(collect(ToolRegistry::forUser($orga))->map->name()->all())
        ->and(collect(LanomatAssistant::make(User::factory()->create())->tools())->all())->toBe([]);
});

it('takes the step budget from the configuration', function () {
    config(['assistant.max_steps' => 7]);

    expect(LanomatAssistant::make(User::factory()->orga()->create())->maxSteps())->toBe(7);
});

it('keeps the system prompt static and free of per-turn data', function () {
    $prompt = LanomatAssistant::make(User::factory()->orga()->create())->instructions();

    expect($prompt)->toContain('Europe/Berlin')->not->toContain('2026')->not->toMatch('/\d{2}:\d{2}/');
});

it('uses claude by default with the configured model', function () {
    config(['assistant.provider' => 'anthropic', 'assistant.model' => 'claude-opus-5-5', 'assistant.failover' => []]);

    expect(LanomatAssistant::providerArguments())->toBe(['anthropic', 'claude-opus-5-5']);
});

it('switches to ollama with its own model and keeps the same tool set', function () {
    config(['assistant.provider' => 'ollama', 'assistant.ollama_model' => 'qwen3:8b', 'assistant.model' => 'claude-opus-5-5', 'assistant.failover' => []]);
    $orga = User::factory()->orga()->create();
    $seen = [];

    LanomatAssistant::fake(function (string $prompt, $attachments, $provider, string $model) use (&$seen): string {
        $seen = [$provider->name(), $model];

        return 'ok';
    })->preventStrayPrompts();

    expect(LanomatAssistant::providerArguments())->toBe(['ollama', 'qwen3:8b']);

    $this->actingAs($orga)->post('/orga/assistant/chat', ['message' => 'Hallo'])->streamedContent();

    expect($seen)->toBe(['ollama', 'qwen3:8b'])
        ->and(collect(LanomatAssistant::make($orga)->tools())->map->name()->all())->toBe(collect(ToolRegistry::forUser($orga))->map->name()->all());
});

it('builds a failover list only when providers are configured', function () {
    config(['assistant.provider' => 'anthropic', 'assistant.model' => 'claude-opus-5-5', 'assistant.failover' => ['openai']]);

    expect(LanomatAssistant::providerArguments())->toBe([['anthropic' => 'claude-opus-5-5', 'openai' => null], null]);
});

it('treats an empty ollama model as unset', function () {
    config(['assistant.provider' => 'ollama', 'assistant.ollama_model' => '', 'assistant.failover' => []]);

    expect(LanomatAssistant::providerArguments())->toBe(['ollama', null]);
});
