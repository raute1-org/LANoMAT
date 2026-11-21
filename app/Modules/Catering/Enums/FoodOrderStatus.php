<?php

namespace App\Modules\Catering\Enums;

enum FoodOrderStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return __('catering.status.'.$this->value);
    }

    /** Filament badge colour: draft is quiet, open is the live one. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'success',
            self::Closed => 'warning',
        };
    }
}
