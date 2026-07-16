<?php

namespace App\Modules\Voting\Filament\Resources\Polls\Pages;

use App\Modules\Voting\Actions\ClosePoll;
use App\Modules\Voting\Actions\OpenPoll;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Filament\Resources\Polls\PollResource;
use App\Modules\Voting\Models\Poll;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPoll extends EditRecord
{
    protected static string $resource = PollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label(__('polls.admin.actions.open'))
                ->authorize('open')
                // Only offered from Draft, mirroring OpenPoll's own guard,
                // so the button reflects reality instead of being a dead
                // click that always ends in a caught exception.
                ->visible(fn (Poll $record) => $record->status === PollStatus::Draft)
                ->requiresConfirmation()
                ->action(function (Poll $record): void {
                    try {
                        app(OpenPoll::class)->handle($record);
                    } catch (VotingException $exception) {
                        Notification::make()
                            ->title(__($exception->translationKey))
                            ->danger()
                            ->send();

                        return;
                    }

                    // The action locks and saves a *fresh* instance
                    // internally (see OpenPoll), so `$record` here is still
                    // the pre-transition object in memory. Refresh it before
                    // refreshFormData(['status']) re-fills the form,
                    // otherwise the header still shows the old status until
                    // a manual page reload.
                    $record->refresh();

                    Notification::make()
                        ->title(__('polls.admin.actions.opened'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            Action::make('close')
                ->label(__('polls.admin.actions.close'))
                ->authorize('close')
                // Only offered from Open, mirroring ClosePoll's own guard.
                ->visible(fn (Poll $record) => $record->status === PollStatus::Open)
                ->requiresConfirmation()
                ->action(function (Poll $record): void {
                    try {
                        app(ClosePoll::class)->handle($record);
                    } catch (VotingException $exception) {
                        Notification::make()
                            ->title(__($exception->translationKey))
                            ->danger()
                            ->send();

                        return;
                    }

                    // See the `open` action above: the action mutates a
                    // fresh instance internally, so `$record` must be
                    // refreshed before refreshFormData(['status']).
                    $record->refresh();

                    Notification::make()
                        ->title(__('polls.admin.actions.closed'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make()
                ->authorize('update'),
        ];
    }
}
