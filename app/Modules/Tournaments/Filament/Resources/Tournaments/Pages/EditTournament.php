<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Filament\Resources\Tournaments\TournamentResource;
use App\Modules\Tournaments\Models\Tournament;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label(__('tournaments.admin.actions.start'))
                ->authorize('manage')
                // Only startable from Enrollment/CheckIn and never once
                // already Live/Finished — mirrors StartTournament's own
                // guard, so the button reflects reality instead of being a
                // dead click that always ends in a caught exception.
                ->visible(fn (Tournament $record) => in_array(
                    $record->status,
                    [TournamentStatus::Enrollment, TournamentStatus::CheckIn],
                    true,
                ))
                ->requiresConfirmation()
                ->action(function (Tournament $record): void {
                    try {
                        app(StartTournament::class)->handle($record);
                    } catch (TournamentException $exception) {
                        Notification::make()
                            ->title(__($exception->translationKey))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('tournaments.admin.actions.started'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make()
                ->authorize('manage'),
        ];
    }
}
