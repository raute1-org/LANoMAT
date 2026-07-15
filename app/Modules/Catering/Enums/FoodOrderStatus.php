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
}
