<?php

namespace App\Modules\Registration\Http;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Http\Requests\CheckInRequest;
use App\Modules\Registration\Support\TicketLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CheckInController
{
    public function show(Event $event): Response
    {
        return Inertia::render('Orga/CheckIn', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'labels' => trans('registration.checkin'),
            'cameraLabels' => trans('registration.checkin.camera'),
        ]);
    }

    public function store(CheckInRequest $request, Event $event, CheckInRegistration $action): RedirectResponse
    {
        try {
            $registration = $action->handle($event, $request->validated()['qr_token']);
        } catch (CheckInException $e) {
            $message = trans($e->translationKey);

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);
            // `id` makes identical consecutive results distinguishable for the
            // camera scanner's result panel.
            Inertia::flash('checkin', ['id' => (string) Str::uuid(), 'status' => 'error', 'message' => $message]);

            return back();
        }

        $participant = $registration->user()->firstOrFail();

        $message = trans('registration.checkin.done', ['name' => $participant->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        Inertia::flash('checkin', [
            'id' => (string) Str::uuid(),
            'status' => 'success',
            'message' => $message,
            'name' => $participant->name,
            'ticket' => TicketLabel::for($registration->ticket_type),
        ]);

        return back();
    }
}
