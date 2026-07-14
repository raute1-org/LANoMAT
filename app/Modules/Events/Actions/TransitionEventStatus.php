<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use DomainException;

class TransitionEventStatus
{
    public function handle(Event $event, EventStatus $to): Event
    {
        $from = $event->status;

        if (! $from->canTransitionTo($to)) {
            throw new DomainException(
                "Illegal event status transition from {$from->value} to {$to->value}."
            );
        }

        $event->status = $to;
        $event->save();

        EventStatusChanged::dispatch($event, $from, $to);

        return $event;
    }
}
