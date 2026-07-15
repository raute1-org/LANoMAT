<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FoodOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('catering.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                TextInput::make('title')
                    ->label(__('catering.fields.title'))
                    ->required()
                    ->maxLength(255),
                DateTimePicker::make('opens_at')
                    ->label(__('catering.fields.opens_at')),
                DateTimePicker::make('closes_at')
                    ->label(__('catering.fields.closes_at')),
                // A typed Repeater, deliberately not a KeyValue field: each
                // row maps 1:1 onto the MenuCast/MenuOption array shape
                // {key,name,price_cents}, with price_cents constrained to a
                // non-negative integer at the form layer (see roadmap
                // insight #9 — Filament's KeyValue mangles jsonb types, and
                // MenuOption::fromArray itself does not reject negative
                // prices, so the guard belongs here).
                Repeater::make('menu')
                    ->label(__('catering.fields.menu'))
                    ->schema([
                        TextInput::make('key')
                            ->label(__('catering.fields.menu_key'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label(__('catering.fields.menu_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('price_cents')
                            ->label(__('catering.fields.menu_price_cents'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ])
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->addActionLabel(__('catering.fields.menu_add'))
                    ->reorderable(false)
                    ->defaultItems(0),
            ]);
    }
}
