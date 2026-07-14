<?php

namespace App\Modules\Registration\Enums;

enum RegistrationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('registration.status.'.$this->value);
    }
}
