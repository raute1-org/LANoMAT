<?php

declare(strict_types=1);

namespace App\Modules\Events\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Events\Support\HomeChatData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The data behind the always-visible chat dock (`GlobalChat.vue`). The start
 * page and the event pages get the same payload as an Inertia prop; the dock
 * fetches it on first open so every other page stays free of the queries.
 *
 * Auth-gated in `routes/web.php`; `HomeChatData` itself stays viewer-agnostic
 * and is called with `guest: false` here (the route only exists with a user).
 */
class HomeChatController extends Controller
{
    public function __invoke(Request $request, CurrentEvent $current, HomeChatData $chat): JsonResponse
    {
        abort_unless($request->user() !== null, 401);

        return response()->json(
            $chat->forEvent($current->get()),
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }
}
