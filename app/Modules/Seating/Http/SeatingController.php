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
        $seats = Seat::query()
            ->where('event_id', $event->id)
            ->with('assignment.registration.user')
            ->orderBy('pos_y')
            ->orderBy('pos_x')
            ->get()
            ->map(fn (Seat $seat) => [
                'id' => $seat->id,
                'label' => $seat->label,
                'x' => $seat->pos_x,
                'y' => $seat->pos_y,
                'occupant' => $seat->assignment?->registration?->user?->name,
            ])
            ->all();

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
            'labels' => trans('seating.page'),
        ]);
    }

    public function claim(Request $request, Event $event, Seat $seat, ClaimSeat $action): RedirectResponse
    {
        $registration = $this->requireRegistration($request, $event);

        $this->authorize('claim-seat', $registration);

        try {
            $action->handle($seat, $registration);
        } catch (SeatException $e) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => trans($e->translationKey),
            ]);
        }

        return back();
    }

    public function release(Request $request, Event $event, ReleaseSeat $action): RedirectResponse
    {
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
