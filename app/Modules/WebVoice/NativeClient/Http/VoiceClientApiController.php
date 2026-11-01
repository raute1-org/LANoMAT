<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\Actions\IssueJoinToken;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Queries\VisibleChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bearer-authenticated JSON API of the native desktop client (v1). Voice only: there is no chat
 * API in v1. Room visibility and join rules are the browser's (WebVoiceChannelPolicy).
 */
class VoiceClientApiController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'identity' => 'u'.$user->id,
        ]);
    }

    public function rooms(Request $request, VisibleChannels $visible): JsonResponse
    {
        return response()->json([
            'rooms' => $visible->for($this->user($request))->map(fn (WebVoiceChannel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'description' => $channel->description,
                'kind' => $channel->kind->value,
            ])->values(),
        ]);
    }

    public function token(Request $request, WebVoiceChannel $room, IssueJoinToken $issue): JsonResponse
    {
        $user = $this->user($request);

        // A room the caller may not see is indistinguishable from one that does not exist.
        if (Gate::forUser($user)->denies('view', $room)) {
            abort(404);
        }

        try {
            return response()->json($issue->handle($user, $room));
        } catch (WebVoiceUnavailable $e) {
            report($e);

            return response()->json(['message' => __('web_voice.errors.unavailable')], 503);
        }
    }

    /** Client tuning only: the public LiveKit URL and the audio defaults; never a secret. */
    public function config(): JsonResponse
    {
        $url = (string) config('webvoice.url');

        return response()->json([
            'api_version' => 1,
            'livekit_url' => $url === '' ? null : $url,
            'voice_ready' => $url !== '',
            'audio' => (array) config('webvoice.audio'),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
