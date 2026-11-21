<?php

use App\Modules\Preflight\Checks\AssistantProviderCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('skips anthropic without a key', function () {
    config(['assistant.provider' => 'anthropic', 'ai.providers.anthropic.key' => null]);

    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('skipped');
});

it('is ok when anthropic answers and sends the key without running a model', function () {
    config(['assistant.provider' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test-placeholder']);
    Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);

    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('ok');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.anthropic.com/v1/models' && $r->hasHeader('x-api-key', 'sk-test-placeholder') && $r->method() === 'GET');
});

it('is down on a rejected key and only warns when anthropic is unreachable', function () {
    config(['assistant.provider' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test-placeholder']);

    Http::fake(['api.anthropic.com/*' => Http::response('', 401)]);
    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('down');

    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('offline')]);
    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('warn');
});

it('is ok for ollama when the configured model is pulled', function (string $configured) {
    config(['assistant.provider' => 'ollama', 'assistant.ollama_model' => $configured, 'ai.providers.ollama.url' => 'http://ollama.internal:11434']);
    Http::fake(['ollama.internal:11434/api/tags' => Http::response(['models' => [['name' => 'llama3.1:latest'], ['name' => 'qwen3:8b']]], 200)]);

    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('ok');
})->with(['llama3.1', 'llama3.1:latest', 'qwen3:8b']);

it('is down for ollama with a missing model, an unreachable server or no configuration', function () {
    config(['assistant.provider' => 'ollama', 'assistant.ollama_model' => 'qwen3:32b', 'ai.providers.ollama.url' => 'http://ollama.internal:11434']);

    Http::fake(['ollama.internal:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]], 200)]);
    $missing = app(AssistantProviderCheck::class)->run();
    expect($missing->status->value)->toBe('down')->and($missing->message)->toContain('qwen3:32b');

    Http::fake(['ollama.internal:11434/api/tags' => fn () => throw new ConnectionException('offline')]);
    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('down');

    config(['assistant.ollama_model' => null]);
    expect(app(AssistantProviderCheck::class)->run()->status->value)->toBe('down');
});

it('is registered with the preflight run only while the module is on', function () {
    $keys = collect(app()->tagged('preflight.checks'))->map(fn ($c) => $c::class);

    expect($keys)->toContain(AssistantProviderCheck::class);
});
