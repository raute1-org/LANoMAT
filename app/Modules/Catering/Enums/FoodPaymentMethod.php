<?php

namespace App\Modules\Catering\Enums;

enum FoodPaymentMethod: string
{
    case Cash = 'cash';
    case Paypal = 'paypal';
}
