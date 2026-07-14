<?php

namespace App\Modules\Seating\Filament\Resources\Seats;

use App\Modules\Seating\Filament\Resources\Seats\Pages\CreateSeat;
use App\Modules\Seating\Filament\Resources\Seats\Pages\EditSeat;
use App\Modules\Seating\Filament\Resources\Seats\Pages\ListSeats;
use App\Modules\Seating\Filament\Resources\Seats\Schemas\SeatForm;
use App\Modules\Seating\Filament\Resources\Seats\Tables\SeatsTable;
use App\Modules\Seating\Models\Seat;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SeatResource extends Resource
{
    protected static ?string $model = Seat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    public static function getModelLabel(): string
    {
        return __('seating.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seating.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return SeatForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SeatsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeats::route('/'),
            'create' => CreateSeat::route('/create'),
            'edit' => EditSeat::route('/{record}/edit'),
        ];
    }
}
