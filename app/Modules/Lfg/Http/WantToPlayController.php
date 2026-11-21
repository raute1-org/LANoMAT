<?php

namespace App\Modules\Lfg\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Actions\ToggleWantToPlay;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WantToPlayController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function toggle(Request $request, Event $event, ToggleWantToPlay $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $data = $request->validate(['game' => ['required', 'string', 'max:80']]);

        // Authorization lives in the Action, per direction: `create` when the signal is switched
        // on, `delete` on the existing post when it is switched off.
        $action->handle($this->authUser($request), $event, $data['game']);

        return back();
    }
}
