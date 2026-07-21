<?php

namespace App\Modules\Events\Filament\Resources\Events\Schemas;

use App\Modules\Events\Enums\EventStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('events.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('location')
                    ->label(__('events.fields.location'))
                    ->maxLength(255),
                Textarea::make('arrival_info')
                    ->label(__('events.fields.arrival_info'))
                    ->rows(3)
                    ->columnSpanFull(),
                DateTimePicker::make('starts_at')
                    ->label(__('events.fields.starts_at')),
                DateTimePicker::make('ends_at')
                    ->label(__('events.fields.ends_at')),
                TextInput::make('max_participants')
                    ->label(__('events.fields.max_participants'))
                    ->numeric(),
                // Status is changed only via the transition action buttons on the
                // edit page, so it is shown read-only here (no free editing that
                // could bypass the allowed-transitions map).
                Select::make('status')
                    ->label(__('events.fields.status'))
                    ->options(fn () => collect(EventStatus::cases())
                        ->mapWithKeys(fn (EventStatus $status) => [$status->value => $status->label()])
                        ->all())
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
