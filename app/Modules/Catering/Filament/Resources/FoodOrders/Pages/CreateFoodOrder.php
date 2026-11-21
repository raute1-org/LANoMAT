<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Pages;

use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFoodOrder extends CreateRecord
{
    protected static string $resource = FoodOrderResource::class;
}
