<?php

namespace App\Modules\Seating\Filament\Resources\Seats\Pages;

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Seating\Filament\Resources\Seats\SeatResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListSeats extends ListRecords
{
    protected static string $resource = SeatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_grid')
                ->label(__('seating.grid.action'))
                ->schema([
                    Select::make('event_id')
                        ->label(__('seating.grid.event'))
                        ->options(fn () => Event::query()->pluck('name', 'id'))
                        ->required(),
                    TextInput::make('rows')
                        ->label(__('seating.grid.rows'))
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(GenerateSeatGrid::MAX_ROWS),
                    TextInput::make('cols')
                        ->label(__('seating.grid.cols'))
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(GenerateSeatGrid::MAX_COLS)
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if ((int) $get('rows') * (int) $value > GenerateSeatGrid::MAX_SEATS) {
                                $fail(__('seating.grid.too_many', ['max' => GenerateSeatGrid::MAX_SEATS]));
                            }
                        }),
                    TextInput::make('prefix')
                        ->label(__('seating.grid.prefix'))
                        ->default('A')
                        ->required()
                        ->maxLength(GenerateSeatGrid::MAX_PREFIX_LENGTH)
                        ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state)),
                ])
                ->action(function (array $data): void {
                    $event = Event::query()->findOrFail((int) $data['event_id']);
                    $count = app(GenerateSeatGrid::class)->handle(
                        $event,
                        (int) $data['rows'],
                        (int) $data['cols'],
                        $data['prefix'],
                    );

                    Notification::make()
                        ->title(__('seating.grid.done', ['count' => $count]))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
