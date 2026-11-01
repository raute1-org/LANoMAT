<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\NativeClient\Support\NowFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The native client's first screen: what matters for the viewer right now, most urgent first. */
class NowFeedController extends Controller
{
    public function show(Request $request, Event $event, NowFeed $feed): JsonResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        /** @var User $user */
        $user = $request->user();

        return response()->json($feed->for($user, $event));
    }
}
