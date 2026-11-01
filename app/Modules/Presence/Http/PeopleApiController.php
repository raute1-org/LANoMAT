<?php

declare(strict_types=1);

namespace App\Modules\Presence\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Presence\Support\PeopleDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Where is everybody" for the native client (bearer API v1). A draft event is a 404, a person who
 * is not a participant of the event is refused by `EventPolicy::viewPeople`. Which people and
 * locations a viewer gets is decided in {@see PeopleDirectory}.
 */
class PeopleApiController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(PeopleIndexRequest $request, Event $event, PeopleDirectory $people): JsonResponse
    {
        $viewer = $this->authUser($request);

        abort_unless($event->isPubliclyVisible(), 404);
        Gate::forUser($viewer)->authorize('viewPeople', $event);

        return response()->json($people->list($viewer, $event, [
            'team' => $request->boolean('team'),
            'friends' => $request->boolean('friends'),
            'q' => $request->validated('q'),
            'page' => $request->validated('page'),
            'per_page' => $request->validated('per_page'),
        ]));
    }

    /**
     * `{user}` is a plain id on purpose: route model binding would answer an unknown user with a
     * message naming the model, which an opted-out user's id would not produce.
     */
    public function follow(Request $request, Event $event, string $user, PeopleDirectory $people): JsonResponse
    {
        $viewer = $this->authUser($request);

        abort_unless($event->isPubliclyVisible(), 404);
        Gate::forUser($viewer)->authorize('viewPeople', $event);

        $targets = ctype_digit($user) ? $people->follow($viewer, $event, (int) $user) : null;

        abort_if($targets === null, 404);

        return response()->json($targets);
    }
}
