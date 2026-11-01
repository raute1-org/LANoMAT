<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\Actions\DeleteMessage;
use App\Modules\WebVoice\Actions\PostMessage;
use App\Modules\WebVoice\Http\PostMessageRequest;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Modules\WebVoice\Queries\ChannelMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Text chat for the native client (bearer API v1). A thin shell over the browser's own
 * query and actions (ChannelMessages, PostMessage, DeleteMessage), so visibility, length,
 * rate limit, archive and moderation rules are the browser's. A room the caller may not see
 * is a 404 (no enumeration), exactly like the other native routes.
 */
class VoiceClientChatController extends Controller
{
    public function index(Request $request, WebVoiceChannel $room, ChannelMessages $history): JsonResponse
    {
        $user = $this->user($request);
        $this->mustSee($user, $room);

        $before = $request->integer('before') ?: null;
        $messages = $history->page($user, $room, $before);
        $limit = (int) config('webvoice.chat.history');

        return response()->json([
            'messages' => $messages,
            // Pass as `before` to fetch the next older page; null when history is exhausted.
            'next_before' => $messages !== [] && count($messages) >= $limit ? $messages[0]['id'] : null,
        ]);
    }

    public function store(PostMessageRequest $request, WebVoiceChannel $room, PostMessage $post): JsonResponse
    {
        $user = $this->user($request);
        $this->mustSee($user, $room);

        $message = $post->handle($user, $room, $request->validated('body'));

        return response()->json(['message' => $message->toPayload()], 201);
    }

    public function destroy(Request $request, WebVoiceMessage $message, DeleteMessage $delete): JsonResponse
    {
        $user = $this->user($request);
        $room = $message->channel()->firstOrFail();
        $this->mustSee($user, $room);

        $delete->handle($user, $message);

        return response()->json(['ok' => true]);
    }

    private function mustSee(User $user, WebVoiceChannel $room): void
    {
        if (Gate::forUser($user)->denies('view', $room)) {
            abort(404);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
