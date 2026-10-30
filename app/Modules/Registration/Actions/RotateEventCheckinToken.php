<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventCheckinToken;

/** Replaces the event's self check-in secret; every printed QR code stops working. */
class RotateEventCheckinToken
{
    public function handle(Event $event): EventCheckinToken
    {
        $row = EventCheckinToken::forEvent($event);
        $row->token = EventCheckinToken::generate();
        $row->rotated_at = now();
        $row->save();

        return $row;
    }
}
