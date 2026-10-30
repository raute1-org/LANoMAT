<?php

namespace App\Modules\Registration\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Notifications\Notifications\RegistrationConfirmed;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Actions\UpdateArrivalPlan;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Http\Requests\RegisterRequest;
use App\Modules\Registration\Http\Requests\UpdateArrivalPlanRequest;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\ArrivalPlanView;
use App\Modules\Registration\Support\QrCode;
use App\Modules\Registration\Support\TicketLabel;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function show(Request $request, Event $event, QrCode $qr): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $user = $this->authUser($request);
        $registration = $this->activeRegistration($event, $user->id);

        return Inertia::render('Event/Register', [
            'event' => ['name' => $event->name, 'slug' => $event->slug, 'status' => $event->status->value],
            ...RegistrationAvailability::summary($event),
            'tickets' => $this->tickets($event),
            'ticketLabels' => TicketLabel::map($this->tickets($event)),
            'registration' => $registration === null ? null : [
                'ticketType' => $registration->ticket_type,
                'ticketLabel' => TicketLabel::for($registration->ticket_type),
                'status' => $registration->status->value,
                'paid' => $registration->paid_at !== null,
                'checkedIn' => $registration->checked_in_at !== null,
                'qrSvg' => $qr->svg($registration->qr_token),
                'arrival' => ArrivalPlanView::summary($registration, $user),
            ],
            'arrivalOptions' => ArrivalPlanView::options($event),
            'labels' => trans('registration.page'),
            'arrivalLabels' => trans('registration.arrival'),
            'statusLabels' => trans('registration.status'),
        ]);
    }

    public function store(RegisterRequest $request, Event $event, RegisterForEvent $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $this->authorize('create', [EventRegistration::class, $event]);

        try {
            $action->handle($event, $this->authUser($request), $request->validated()['ticket_type'], ArrivalPlan::fromValidated($request->validated()));
        } catch (RegistrationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        $this->authUser($request)->notify(new RegistrationConfirmed($event->name));

        return back();
    }

    public function updateArrival(UpdateArrivalPlanRequest $request, Event $event, UpdateArrivalPlan $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $registration = $this->activeRegistration($event, $this->authUser($request)->id);
        abort_if($registration === null, 404);

        $this->authorize('updateArrivalPlan', $registration);

        try {
            $action->handle($registration, ArrivalPlan::fromValidated($request->validated()));
        } catch (RegistrationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('registration.arrival.saved')]);

        return back();
    }

    public function destroy(Request $request, Event $event, CancelRegistration $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $registration = $this->activeRegistration($event, $this->authUser($request)->id);

        if ($registration !== null) {
            $this->authorize('cancel', $registration);
            $action->handle($registration);
        }

        return back();
    }

    private function activeRegistration(Event $event, int $userId): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function tickets(Event $event): array
    {
        return RegisterForEvent::allowedTickets($event);
    }
}
