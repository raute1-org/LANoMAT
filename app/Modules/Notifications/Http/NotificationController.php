<?php

namespace App\Modules\Notifications\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * Mark a notification as read. Scoped through the authenticated user's
     * own notifications relation — a notification ID belonging to another
     * user 404s rather than ever being resolved via a raw lookup, since the
     * ID is client-supplied and must never be trusted on its own.
     */
    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $model = $this->authUser($request)
            ->notifications()
            ->findOrFail($notification);

        $model->markAsRead();

        return back();
    }
}
