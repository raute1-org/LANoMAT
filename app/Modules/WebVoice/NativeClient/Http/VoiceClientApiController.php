<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\Actions\IssueJoinToken;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Queries\VisibleChannels;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bearer-authenticated JSON API of the native desktop client (v1). Voice and config only; chat,
 * realtime auth and notifications have their own controllers. Room visibility and join rules are the browser's (WebVoiceChannelPolicy).
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
            // Which optional modules this deployment runs, so the client can hide what the event does not use.
            'modules' => $this->modules(),
            'realtime' => $this->realtime(),
        ]);
    }

    /** @return array<string, bool> keyed like the MODULE_<KEY> overrides, e.g. `game_servers` */
    private function modules(): array
    {
        $modules = app(Modules::class);

        return collect(Module::cases())->mapWithKeys(fn (Module $module): array => [$module->key() => $modules->enabled($module)])->all();
    }

    /**
     * Where a Pusher-protocol client connects for live chat. Public connection data only: the app
     * key is what browsers receive too; the secret never leaves the server. Null when Reverb is
     * not configured (clients then fall back to polling the REST endpoints).
     *
     * @return array{driver: string, key: string, host: string, port: int, scheme: string, auth_endpoint: string}|null
     */
    private function realtime(): ?array
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        $options = (array) config('broadcasting.connections.reverb.options');
        $host = (string) ($options['host'] ?? '');

        if ($key === '' || $host === '' || config('broadcasting.default') !== 'reverb') {
            return null;
        }

        return [
            'driver' => 'reverb',
            'key' => $key,
            'host' => $host,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => (string) ($options['scheme'] ?? 'https'),
            'auth_endpoint' => route('voice-client.v1.broadcasting.auth', absolute: false),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
