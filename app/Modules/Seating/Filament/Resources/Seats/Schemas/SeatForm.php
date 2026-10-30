<?php

namespace App\Modules\Seating\Filament\Resources\Seats\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class SeatForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('seating.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                TextInput::make('label')
                    ->label(__('seating.fields.label'))
                    ->required()
                    ->maxLength(255)
                    // seats_event_id_label_unique: surface a form error instead of a 500.
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('event_id', $get('event_id')),
                    )
                    ->validationMessages(['unique' => __('seating.validation.label_taken')]),
                TextInput::make('pos_x')
                    ->label(__('seating.fields.pos_x'))
                    ->numeric()
                    ->required(),
                TextInput::make('pos_y')
                    ->label(__('seating.fields.pos_y'))
                    ->numeric()
                    ->required(),
                TextInput::make('meta.switch_port')
                    ->label(__('seating.fields.switch_port')),
                TextInput::make('meta.ip')
                    ->label(__('seating.fields.ip'))
                    ->ip(),
            ]);
    }
}
