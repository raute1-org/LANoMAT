<?php

declare(strict_types=1);

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Probes the model provider the AI assistant is configured for. Reads the
 * assistant's and the SDK's config directly instead of importing the Assistant
 * module (Preflight is core and must not depend on an optional module).
 *
 * - `anthropic`: GET /models with the API key. Skipped without a key; an
 *   unreachable API is only a warning (the venue can switch to ollama).
 * - `openai`: GET /models with the bearer key, same rules as `anthropic`; additionally
 *   a configured model missing from the account's model list is only a warning (an
 *   account may reach a model through an alias).
 * - `ollama`: GET /api/tags; down when the server is unreachable or the
 *   configured model is not pulled, since there is no fallback.
 * Neither call produces a model run, so the probe costs nothing.
 */
class AssistantProviderCheck implements HealthCheck
{
    public function key(): string
    {
        return 'assistant_provider';
    }

    public function label(): string
    {
        return __('preflight.checks.assistant_provider');
    }

    public function run(): HealthResult
    {
        return match ((string) config('assistant.provider')) {
            'anthropic' => $this->anthropic(),
            'openai' => $this->openai(),
            'ollama' => $this->ollama(),
            default => HealthResult::skipped(__('preflight.messages.not_configured')),
        };
    }

    private function anthropic(): HealthResult
    {
        $key = config('ai.providers.anthropic.key');

        if (blank($key)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $response = $this->listModels(
            (string) config('ai.providers.anthropic.url', 'https://api.anthropic.com/v1'),
            ['x-api-key' => (string) $key, 'anthropic-version' => '2023-06-01'],
        );

        return $this->cloudResult($response) ?? HealthResult::ok();
    }

    private function openai(): HealthResult
    {
        $key = config('ai.providers.openai.key');

        if (blank($key)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $response = $this->listModels(
            (string) config('ai.providers.openai.url', 'https://api.openai.com/v1'),
            ['Authorization' => 'Bearer '.$key],
        );

        $failure = $this->cloudResult($response);

        if ($failure !== null) {
            return $failure;
        }

        $model = (string) config('assistant.model');
        $ids = collect((array) $response?->json('data'))
            ->map(fn (mixed $m): string => is_array($m) ? (string) ($m['id'] ?? '') : '')
            ->filter();

        return $model === '' || $ids->contains($model)
            ? HealthResult::ok()
            : HealthResult::warn(__('preflight.messages.assistant_openai_model_missing', ['model' => $model]));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function listModels(string $baseUrl, array $headers): ?Response
    {
        try {
            return Http::timeout(3)->withoutRedirecting()->acceptJson()
                ->withHeaders($headers)
                ->get(rtrim($baseUrl, '/').'/models');
        } catch (Throwable) {
            return null;
        }
    }

    /** Null when the response is a 2xx; otherwise the warn/down result shared by the cloud providers. */
    private function cloudResult(?Response $response): ?HealthResult
    {
        return match (true) {
            $response === null => HealthResult::warn(__('preflight.messages.assistant_unreachable')),
            $response->successful() => null,
            in_array($response->status(), [401, 403], true) => HealthResult::down(__('preflight.messages.assistant_key_rejected')),
            default => HealthResult::warn(__('preflight.messages.assistant_unreachable')),
        };
    }

    private function ollama(): HealthResult
    {
        $url = config('ai.providers.ollama.url');
        $model = config('assistant.ollama_model');

        if (blank($url) || blank($model)) {
            return HealthResult::down(__('preflight.messages.assistant_ollama_unconfigured'));
        }

        try {
            $response = Http::timeout(3)->withoutRedirecting()->acceptJson()->get(rtrim((string) $url, '/').'/api/tags');
        } catch (Throwable) {
            return HealthResult::down(__('preflight.messages.assistant_unreachable'));
        }

        if ($response->failed()) {
            return HealthResult::down(__('preflight.messages.assistant_unreachable'));
        }

        $models = collect((array) $response->json('models'))
            ->map(fn (mixed $m): string => is_array($m) ? (string) ($m['name'] ?? $m['model'] ?? '') : '')
            ->filter();

        // `llama3.1` matches the pulled `llama3.1:latest`; an explicit tag must match exactly.
        $wanted = (string) $model;
        $found = $models->contains(fn (string $name): bool => $name === $wanted || (! str_contains($wanted, ':') && str_starts_with($name, $wanted.':')));

        return $found ? HealthResult::ok() : HealthResult::down(__('preflight.messages.assistant_model_missing', ['model' => $wanted]));
    }
}
