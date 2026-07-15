<?php

namespace App\Modules\Voting\Filament\Resources\Polls\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PollForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('polls.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                TextInput::make('question')
                    ->label(__('polls.fields.question'))
                    ->required()
                    ->maxLength(255),
                DateTimePicker::make('closes_at')
                    ->label(__('polls.fields.closes_at')),
                // A typed Repeater bound to the poll_options HasMany via
                // ->relationship(), mirroring the Catering menu Repeater's
                // "typed, not KeyValue" reasoning: each row maps 1:1 onto a
                // PollOption row (label, sort). Using ->relationship() here
                // (rather than the catering module's manual
                // handleRecordCreation/Update dance) lets Filament persist
                // the child rows directly to poll_options, since
                // PollOption itself has no extra domain invariants beyond
                // plain mass-assignment (unlike FoodOrder's MenuCast value
                // objects).
                Repeater::make('options')
                    ->relationship()
                    ->label(__('polls.fields.options'))
                    ->schema([
                        TextInput::make('label')
                            ->label(__('polls.fields.option_label'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sort')
                            ->label(__('polls.fields.option_sort'))
                            ->numeric()
                            ->integer()
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->addActionLabel(__('polls.fields.option_add'))
                    ->reorderable(false)
                    ->defaultItems(0),
            ]);
    }
}
