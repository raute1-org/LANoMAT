<?php

namespace App\Modules\Seating\Listeners;

use App\Modules\Registration\Events\RegistrationCancelled;
use App\Modules\Seating\Actions\ReleaseSeat;

class ReleaseSeatOnCancellation
{
    public function __construct(
        private readonly ReleaseSeat $releaseSeat,
    ) {}

    public function handle(RegistrationCancelled $event): void
    {
        $this->releaseSeat->handle($event->registration);
    }
}
