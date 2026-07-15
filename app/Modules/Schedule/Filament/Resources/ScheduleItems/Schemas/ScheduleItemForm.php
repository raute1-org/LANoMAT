<?php

namespace App\Modules\Schedule\Filament\Resources\ScheduleItems\Schemas;

use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ScheduleItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('schedule.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                Select::make('type')
                    ->label(__('schedule.fields.type'))
                    ->options(collect(ScheduleItemType::cases())
                        ->mapWithKeys(fn (ScheduleItemType $type) => [$type->value => $type->label()])
                        ->all())
                    ->required(),
                TextInput::make('title')
                    ->label(__('schedule.fields.title'))
                    ->required()
                    ->maxLength(255)
                    // Tournament-owned rows have their title kept in sync by
                    // SyncTournamentScheduleItem on every tournament save, so
                    // editing it here would be silently overwritten.
                    ->disabled(fn (?ScheduleItem $record) => $record?->ref_type !== null),
                Textarea::make('description')
                    ->label(__('schedule.fields.description')),
                DateTimePicker::make('starts_at')
                    ->label(__('schedule.fields.starts_at'))
                    ->required()
                    ->disabled(fn (?ScheduleItem $record) => $record?->ref_type !== null),
                DateTimePicker::make('ends_at')
                    ->label(__('schedule.fields.ends_at')),
                TextInput::make('location')
                    ->label(__('schedule.fields.location'))
                    ->maxLength(255),
                TextInput::make('sort')
                    ->label(__('schedule.fields.sort'))
                    ->numeric(),
            ]);
    }
}
