<?php

namespace App\Modules\Events\Events;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Events\Dispatchable;

class EventStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Event $event,
        public readonly EventStatus $from,
        public readonly EventStatus $to,
    ) {}
}
