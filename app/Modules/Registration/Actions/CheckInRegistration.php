<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Support\Carbon;

class CheckInRegistration
{
    public function handle(Event $event, string $qrToken): EventRegistration
    {
        $registration = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('qr_token', $qrToken)
            ->first();

        if ($registration === null) {
            throw CheckInException::unknownToken();
        }

        if ($registration->status === RegistrationStatus::Cancelled) {
            throw CheckInException::notConfirmed();
        }

        if ($registration->checked_in_at !== null) {
            throw CheckInException::alreadyCheckedIn();
        }

        $registration->checked_in_at = Carbon::now();
        $registration->save();

        PresenceUpdated::dispatch($registration->event_id);

        return $registration;
    }
}
