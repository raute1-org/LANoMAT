<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\Actions\DeleteMessage;
use App\Modules\WebVoice\Actions\IssueJoinToken;
use App\Modules\WebVoice\Actions\IssueLatencyTestTokens;
use App\Modules\WebVoice\Actions\MuteParticipant;
use App\Modules\WebVoice\Actions\PostMessage;
use App\Modules\WebVoice\Actions\RemoveParticipant;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Modules\WebVoice\Queries\ChannelMessages;
use App\Modules\WebVoice\Queries\VisibleChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Thin HTTP layer: every decision is in the policy and the actions. */
class WebVoiceController extends Controller
{
    public function index(Request $request, VisibleChannels $visible): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('WebVoice/Index', [
            'channels' => $visible->for($user)->map(fn (WebVoiceChannel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'description' => $channel->description,
                'kind' => $channel->kind->value,
            ])->values(),
            'me' => ['id' => $user->id, 'name' => $user->name, 'identity' => 'u'.$user->id],
            'canModerate' => $user->isOrga(),
            // Connection diagnostics and the latency self-test (orga and admin).
            'canMeasure' => $user->can('latencyTest', WebVoiceChannel::class),
            'voiceReady' => config('webvoice.url') !== null && config('webvoice.url') !== ''
                && (string) config('webvoice.api_key') !== '' && (string) config('webvoice.api_secret') !== '',
            'chat' => [
                'maxLength' => (int) config('webvoice.chat.max_length'),
                'history' => (int) config('webvoice.chat.history'),
            ],
            'labels' => trans('web_voice.page'),
        ]);
    }

    public function token(Request $request, WebVoiceChannel $channel, IssueJoinToken $issue): JsonResponse
    {
        try {
            return response()->json($issue->handle($this->user($request), $channel));
        } catch (WebVoiceUnavailable $e) {
            report($e);

            return response()->json(['message' => __('web_voice.errors.unavailable')], 503);
        }
    }

    public function latencyTestToken(Request $request, IssueLatencyTestTokens $issue): JsonResponse
    {
        try {
            return response()->json($issue->handle($this->user($request)));
        } catch (WebVoiceUnavailable $e) {
            report($e);

            return response()->json(['message' => __('web_voice.errors.unavailable')], 503);
        }
    }

    public function messages(Request $request, WebVoiceChannel $channel, ChannelMessages $history): JsonResponse
    {
        $before = $request->integer('before') ?: null;

        return response()->json(['messages' => $history->page($this->user($request), $channel, $before)]);
    }

    public function post(PostMessageRequest $request, WebVoiceChannel $channel, PostMessage $post): JsonResponse
    {
        $message = $post->handle($this->user($request), $channel, $request->validated('body'));

        return response()->json(['message' => $message->toPayload()], 201);
    }

    public function destroyMessage(Request $request, WebVoiceMessage $message, DeleteMessage $delete): JsonResponse
    {
        $delete->handle($this->user($request), $message);

        return response()->json(['ok' => true]);
    }

    public function mute(Request $request, WebVoiceChannel $channel, User $user, MuteParticipant $mute): JsonResponse
    {
        return response()->json(['live' => $mute->handle($this->user($request), $channel, $user, true)]);
    }

    public function unmute(Request $request, WebVoiceChannel $channel, User $user, MuteParticipant $mute): JsonResponse
    {
        return response()->json(['live' => $mute->handle($this->user($request), $channel, $user, false)]);
    }

    public function remove(Request $request, WebVoiceChannel $channel, User $user, RemoveParticipant $remove): JsonResponse
    {
        try {
            $remove->handle($this->user($request), $channel, $user);
        } catch (WebVoiceUnavailable $e) {
            report($e);

            return response()->json(['message' => __('web_voice.errors.unavailable')], 503);
        }

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
