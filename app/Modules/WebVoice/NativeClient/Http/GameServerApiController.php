<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\WebVoice\NativeClient\Support\NativeServerDto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Game servers for the native client (bearer API). The lists are the participant web pages'
 * own projections ({@see ServerListProjection}); the event list is additionally narrowed to
 * the viewer's own match server. Routes exist only while the GameServers module is on.
 */
class GameServerApiController extends Controller
{
    public function event(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $rows = ServerListProjection::forEventViewedBy($event, $this->user($request));

        return response()->json([
            'servers' => array_map(NativeServerDto::from(...), $rows),
        ]);
    }

    public function permanent(Request $request): JsonResponse
    {
        abort_if(Gate::forUser($this->user($request))->denies('viewPermanent', ServerLink::class), 403);

        return response()->json([
            'servers' => ServerListProjection::permanentLinks()
                ->map(fn (ServerLink $link): array => NativeServerDto::from(ServerListProjection::permanentDto($link)))
                ->values(),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
