<?php

namespace App\Modules\Infoscreen\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\PingOrga;
use App\Modules\Infoscreen\Exceptions\InfoscreenException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The "Orga rufen" participant button (Task 13): any authenticated user can
 * ping everyone with an orga-or-above role for the given event's context.
 * No ticket system — just a notification (bell + Discord DM mirror). The
 * route is throttled (see routes/web.php) to prevent spam since there is no
 * other rate limit on this action.
 */
class OrgaPingController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function store(Request $request, Event $event, PingOrga $action): RedirectResponse
    {
        $validated = $request->validate([
            'words' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $action->handle($event, $this->authUser($request), $validated['words'] ?? null);
        } catch (InfoscreenException $e) {
            // The Action re-validates the word count (per the M4
            // multi-entry-point lesson) since a FormRequest can only check
            // string length, not word count. Surface it the same way as any
            // other `words` validation failure.
            throw ValidationException::withMessages(['words' => trans($e->translationKey)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('infoscreen.orga_ping.sent')]);

        return back();
    }
}
