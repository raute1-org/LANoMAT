<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\GameServers\Actions\ChangeServerMap;
use App\Modules\GameServers\Actions\ReloadMap;
use App\Modules\GameServers\Actions\RestartRound;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Admin shortcuts on the participant server page: restart the round, change or reload
 * the map. Thin on purpose: the Actions authorize `configure` on the server and build
 * the console command from allowlisted values.
 */
class PermanentServerAdminController extends Controller
{
    public function restartRound(Request $request, ServerLink $serverLink, RestartRound $action): RedirectResponse
    {
        return $this->run($request, fn (User $user) => $action->handle($user, $serverLink), 'gameservers.settings.restart_round.sent');
    }

    public function reloadMap(Request $request, ServerLink $serverLink, ReloadMap $action): RedirectResponse
    {
        return $this->run($request, fn (User $user) => $action->handle($user, $serverLink), 'gameservers.settings.reload.sent');
    }

    public function changeMap(Request $request, ServerLink $serverLink, ChangeServerMap $action): RedirectResponse
    {
        $input = $request->validate([
            'map' => ['nullable', 'string', 'max:64'],
            'workshop_id' => ['nullable', 'string', 'max:12'],
        ]);

        return $this->run(
            $request,
            fn (User $user) => $action->handle($user, $serverLink, $input['map'] ?? null, $input['workshop_id'] ?? null),
            'gameservers.settings.map.changed',
        );
    }

    /**
     * @param  callable(User): void  $operation
     */
    private function run(Request $request, callable $operation, string $successKey): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $operation($user);
            Inertia::flash('toast', ['type' => 'success', 'message' => trans($successKey)]);
        } catch (ValidationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($e->errors())->flatten()->first()]);
        } catch (GameServerException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);
        }

        return back();
    }
}
