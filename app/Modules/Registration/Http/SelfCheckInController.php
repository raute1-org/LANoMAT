<?php

namespace App\Modules\Registration\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Actions\SelfCheckIn;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventCheckinToken;
use App\Modules\Registration\Support\MyRegistration;
use App\Modules\Registration\Support\SelfCheckInWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Participant-side self check-in behind the ONE on-site QR code. The token maps
 * to exactly one event; anything unknown (or rotated) is a generic 404.
 */
class SelfCheckInController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function show(Request $request, string $token): Response
    {
        $event = $this->resolveEvent($token);
        $user = $this->authUser($request);
        $registration = MyRegistration::find($event, $user);
        $window = SelfCheckInWindow::state($event);

        $state = match (true) {
            $registration === null => 'not_registered',
            $registration->checked_in_at !== null => 'already',
            $window === SelfCheckInWindow::OPEN => 'ready',
            default => $window,
        };

        // Late arrivals who never registered: the window (not the registration
        // rule) still has to be open for a check-in to make sense afterwards,
        // but registering is decided purely by RegistrationAvailability.
        return Inertia::render('Checkin/Show', [
            'state' => $state,
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'participant' => ['name' => $user->name, 'avatarUrl' => $user->avatar_url],
            'checkedInAt' => $registration?->checked_in_at?->toIso8601String(),
            'opensAt' => $window === SelfCheckInWindow::NOT_OPEN
                ? SelfCheckInWindow::opensAt($event)?->toIso8601String()
                : null,
            ...RegistrationAvailability::summary($event),
            'ticketType' => RegisterForEvent::allowedTickets($event)[0],
            'labels' => trans('registration.self_checkin'),
        ]);
    }

    public function store(Request $request, string $token, SelfCheckIn $action): RedirectResponse
    {
        $event = $this->resolveEvent($token);
        $user = $this->authUser($request);
        $registration = MyRegistration::find($event, $user);

        // Not allowed (no registration, window closed, ...): no error page, the
        // GET re-renders the matching friendly state.
        if ($registration !== null && Gate::forUser($user)->allows('selfCheckIn', $registration)) {
            try {
                $action->handle($event, $registration);
            } catch (CheckInException) {
                // Closed window / inactive registration: the GET shows the state.
            }
        }

        return redirect()->route('checkin.show', ['token' => $token]);
    }

    private function resolveEvent(string $token): Event
    {
        $event = EventCheckinToken::resolveEvent($token);

        abort_if($event === null || $event->status === EventStatus::Draft, 404);

        return $event;
    }
}
