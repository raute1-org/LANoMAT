<?php

namespace App\Modules\Registration\Http;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Http\Requests\CheckInRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CheckInController
{
    public function show(Event $event): Response
    {
        return Inertia::render('Orga/CheckIn', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'labels' => trans('registration.checkin'),
        ]);
    }

    public function store(CheckInRequest $request, Event $event, CheckInRegistration $action): RedirectResponse
    {
        try {
            $registration = $action->handle($event, $request->validated()['qr_token']);
        } catch (CheckInException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        $participant = $registration->user()->firstOrFail();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('registration.checkin.done', ['name' => $participant->name]),
        ]);

        return back();
    }
}
