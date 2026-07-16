<?php

namespace App\Modules\Seating\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Actions\ReleaseSeat;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Support\SeatProjection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeatingController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * Public "who sits where" map — readable without authentication.
     */
    public function index(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $seats = SeatProjection::forEvent($event);

        $user = $request->user();
        $myRegistration = $user === null ? null : $this->activeRegistration($event, $user->id);

        $mySeatId = $myRegistration === null ? null : Seat::query()
            ->whereHas('assignment', fn ($q) => $q->where('registration_id', $myRegistration->id))
            ->value('id');

        return Inertia::render('Event/Seating', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'seats' => $seats,
            'mySeatId' => $mySeatId,
            'canClaim' => $myRegistration !== null,
            'canPing' => $user !== null,
            'labels' => trans('seating.page'),
            'orgaPingLabels' => trans('infoscreen.orga_ping'),
        ]);
    }

    public function claim(Request $request, Event $event, Seat $seat, ClaimSeat $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $registration = $this->requireRegistration($request, $event);

        $this->authorize('claim-seat', $registration);

        try {
            $action->handle($seat, $registration);
        } catch (SeatException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        return back();
    }

    public function release(Request $request, Event $event, ReleaseSeat $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $registration = $this->requireRegistration($request, $event);

        $this->authorize('claim-seat', $registration);

        $action->handle($registration);

        return back();
    }

    private function requireRegistration(Request $request, Event $event): EventRegistration
    {
        $registration = $this->activeRegistration($event, $this->authUser($request)->id);

        abort_if($registration === null, 403);

        return $registration;
    }

    private function activeRegistration(Event $event, int $userId): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->first();
    }
}
