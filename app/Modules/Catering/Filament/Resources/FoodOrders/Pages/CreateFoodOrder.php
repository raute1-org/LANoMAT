<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Pages;

use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFoodOrder extends CreateRecord
{
    protected static string $resource = FoodOrderResource::class;

    /**
     * `menu` is deliberately not in FoodOrder::$fillable (see the model's
     * comment), so it cannot be set via the default mass-assignment
     * constructor. The admin Filament layer is already gated by
     * FoodOrderPolicy::create, so it explicitly assigns menu afterwards,
     * going through MenuCast exactly as any other write would.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $menu = $data['menu'] ?? null;
        unset($data['menu']);

        /** @var FoodOrder $record */
        $record = new FoodOrder($data);
        $record->menu = $menu ?? [];
        $record->save();

        return $record;
    }
}
