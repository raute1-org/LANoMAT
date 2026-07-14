<?php

namespace App\Modules\Events\Filament\Resources\Events;

use App\Modules\Events\Filament\Resources\Events\Pages\CreateEvent;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Filament\Resources\Events\Pages\ListEvents;
use App\Modules\Events\Filament\Resources\Events\Schemas\EventForm;
use App\Modules\Events\Filament\Resources\Events\Tables\EventsTable;
use App\Modules\Events\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getModelLabel(): string
    {
        return __('events.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('events.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
