<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http\Features;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Shared plumbing of the event-feature controllers of the native client API (polls, catering,
 * files). The bearer middleware sets only the request's user resolver, never the default guard,
 * so authorization always goes through `Gate::forUser($user)`, never `$this->authorize()`.
 */
abstract class NativeFeatureController extends Controller
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** A draft event does not exist for participants, exactly like in the browser. */
    protected function requireVisible(?Event $event): Event
    {
        abort_if($event === null || ! $event->isPubliclyVisible(), 404);

        return $event;
    }

    protected function authorizeFor(User $user, string $ability, mixed $arguments): void
    {
        abort_if(Gate::forUser($user)->denies($ability, $arguments), 403);
    }

    /**
     * A refused business rule as JSON: the German browser message plus a stable machine code
     * (the last segment of the translation key, e.g. `already_voted`).
     *
     * @param  array<string, string|int>  $parameters
     */
    protected function domainError(string $translationKey, array $parameters = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'code' => Str::afterLast($translationKey, '.'),
            'message' => trans($translationKey, $parameters),
        ], $status);
    }
}
