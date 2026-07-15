<?php

namespace App\Modules\Schedule\Filament\Resources\ScheduleItems;

use App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages\CreateScheduleItem;
use App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages\EditScheduleItem;
use App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages\ListScheduleItems;
use App\Modules\Schedule\Filament\Resources\ScheduleItems\Schemas\ScheduleItemForm;
use App\Modules\Schedule\Filament\Resources\ScheduleItems\Tables\ScheduleItemsTable;
use App\Modules\Schedule\Models\ScheduleItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScheduleItemResource extends Resource
{
    protected static ?string $model = ScheduleItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationGroup(): ?string
    {
        return __('schedule.admin.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('schedule.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('schedule.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduleItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleItems::route('/'),
            'create' => CreateScheduleItem::route('/create'),
            'edit' => EditScheduleItem::route('/{record}/edit'),
        ];
    }
}
