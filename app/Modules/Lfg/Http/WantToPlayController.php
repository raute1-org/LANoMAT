<?php

namespace App\Modules\Lfg\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Actions\ToggleWantToPlay;
use App\Modules\Lfg\Models\LfgPost;
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
        $this->authorize('create', LfgPost::class);

        $data = $request->validate(['game' => ['required', 'string', 'max:80']]);

        $action->handle($this->authUser($request), $event, $data['game']);

        return back();
    }
}
