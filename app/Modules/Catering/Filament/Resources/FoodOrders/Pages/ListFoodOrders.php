<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Pages;

use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFoodOrders extends ListRecords
{
    protected static string $resource = FoodOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
