<?php

namespace App\Modules\Registration\Events;

use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Events\Dispatchable;

class RegistrationCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly EventRegistration $registration,
    ) {}
}
