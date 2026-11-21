<?php

namespace App\Modules\Catering\Filament\Resources\Restaurants\Pages;

use App\Modules\Catering\Filament\Resources\Restaurants\RestaurantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRestaurant extends EditRecord
{
    protected static string $resource = RestaurantResource::class;

    /**
     * The menu is edited in its own table (every change is saved at once through
     * UpdateRestaurantMenu), so the typed MenuOption objects never enter the form state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['menu']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        // The policy only allows it while no round uses the restaurant.
        return [DeleteAction::make()];
    }
}
