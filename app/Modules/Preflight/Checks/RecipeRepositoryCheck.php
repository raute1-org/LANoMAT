<?php

declare(strict_types=1);

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether the recipe pull request link to the git catalogue works: the token
 * may read the repository and — the part that fails silently otherwise — may
 * push to it. A missing catalogue link must never turn the event-day check
 * red, so every failure here is a warning, never "down".
 *
 * Preflight must not import GameRecipes (architecture ratchet), so this reads
 * `config('recipes.pull_requests')` and talks to the GitHub REST API itself,
 * with the same call shape and API version as `GitHubRecipeRepository`.
 */
final class RecipeRepositoryCheck implements HealthCheck
{
    /** GitHub REST API version spoken by the repository client. */
    private const string API_VERSION = '2026-03-10';

    public function key(): string
    {
        return 'recipe_repository';
    }

    public function label(): string
    {
        return __('preflight.checks.recipe_repository');
    }

    public function run(): HealthResult
    {
        if (! (bool) config('recipes.pull_requests.enabled')) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $repository = trim((string) config('recipes.pull_requests.repository'));
        $token = trim((string) config('recipes.pull_requests.token'));

        if ($repository === '' || $token === '') {
            return HealthResult::warn(__('preflight.messages.recipe_repository_incomplete'));
        }

        $apiUrl = rtrim((string) (config('recipes.pull_requests.api_url') ?: 'https://api.github.com'), '/');

        try {
            $response = Http::withToken($token)
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => self::API_VERSION])
                ->timeout(3)
                ->withoutRedirecting()
                ->get($apiUrl.'/repos/'.$repository);
        } catch (Throwable) {
            return HealthResult::warn(__('preflight.messages.recipe_repository_unreachable'));
        }

        if ($response->status() === 200) {
            return data_get($response->json(), 'permissions.push') === true
                ? HealthResult::ok(__('preflight.messages.recipe_repository_ok'))
                : HealthResult::warn(__('preflight.messages.recipe_repository_no_push'));
        }

        return HealthResult::warn(match ($response->status()) {
            401 => __('preflight.messages.recipe_repository_unauthorized'),
            403 => __('preflight.messages.recipe_repository_forbidden'),
            404 => __('preflight.messages.recipe_repository_not_found'),
            default => __('preflight.messages.recipe_repository_http', ['status' => $response->status()]),
        });
    }
}
