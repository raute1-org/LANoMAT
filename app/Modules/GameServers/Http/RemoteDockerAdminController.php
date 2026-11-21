<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\GameServers\Actions\ControlRemoteDockerPower;
use App\Modules\GameServers\Actions\ReadRemoteLogs;
use App\Modules\GameServers\Actions\SendRemoteConsoleCommand;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Power, console and logs of a server that runs as a Docker container on a remote host,
 * for the participant page. Thin on purpose: each route authorizes `configure` on the
 * server before it validates anything (a participant gets 403, not 422), and the Actions
 * authorize again, validate the command and build the shell line.
 */
class RemoteDockerAdminController extends Controller
{
    public function power(Request $request, ServerLink $serverLink, ControlRemoteDockerPower $action): RedirectResponse
    {
        Gate::authorize('configure', $serverLink);

        $input = $request->validate([
            'action' => ['required', Rule::in([PowerAction::Start->value, PowerAction::Stop->value, PowerAction::Restart->value])],
        ]);

        try {
            $action->handle($this->user($request), $serverLink, PowerAction::from($input['action']));
            Inertia::flash('toast', ['type' => 'success', 'message' => trans('gameservers.remote.power_sent')]);
        } catch (GameServerException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);
        }

        return back();
    }

    public function command(Request $request, ServerLink $serverLink, SendRemoteConsoleCommand $action): JsonResponse
    {
        Gate::authorize('configure', $serverLink);

        $input = $request->validate(['command' => ['required', 'string', 'max:256']]);

        try {
            return response()->json(['output' => $action->handle($this->user($request), $serverLink, $input['command'])]);
        } catch (GameServerException $e) {
            return response()->json(['message' => trans($e->translationKey)], 422);
        }
    }

    public function logs(Request $request, ServerLink $serverLink, ReadRemoteLogs $action): JsonResponse
    {
        Gate::authorize('configure', $serverLink);

        $input = $request->validate(['lines' => ['nullable', 'integer', 'min:1', 'max:500']]);

        try {
            return response()->json(['output' => $action->handle($this->user($request), $serverLink, (int) ($input['lines'] ?? ReadRemoteLogs::DEFAULT_LINES))]);
        } catch (GameServerException $e) {
            return response()->json(['message' => trans($e->translationKey)], 422);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
