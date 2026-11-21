<?php

namespace App\Modules\Registration\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\TicketType;

/** Guarantees an event has at least one ticket type: a free, active "Standard". */
class EnsureDefaultTicketType
{
    public static function for(Event $event): TicketType
    {
        $existing = TicketType::query()->where('event_id', $event->id)->orderBy('sort')->orderBy('id')->first();

        return $existing ?? TicketType::query()->create([
            'event_id' => $event->id,
            'key' => 'standard',
            'name' => 'Standard',
            'price_cents' => 0,
            'sort' => 0,
            'active' => true,
        ]);
    }
}
