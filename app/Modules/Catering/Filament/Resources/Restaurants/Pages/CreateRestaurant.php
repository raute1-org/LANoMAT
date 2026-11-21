<?php

namespace App\Modules\Catering\Filament\Resources\Restaurants\Pages;

use App\Modules\Catering\Filament\Resources\Restaurants\RestaurantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRestaurant extends CreateRecord
{
    protected static string $resource = RestaurantResource::class;
}
