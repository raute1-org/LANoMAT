<?php

namespace App\Modules\Events\Filament\Resources\Events\Pages;

use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Filament\Resources\Events\EventResource;
use App\Modules\Events\Models\Event;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Event $event */
        $event = $this->getRecord();

        // The action list below is built from allowedTransitions(), so a
        // DomainException should never fire in normal operation. It is still
        // caught defensively (e.g. a concurrent transition by another orga
        // between page load and click) and surfaced as a notification instead
        // of a 500.
        $transitions = collect($event->status->allowedTransitions())
            ->map(fn (EventStatus $to) => Action::make('transition_'.$to->value)
                ->label(__('events.transition.'.$to->value))
                ->requiresConfirmation()
                ->action(function () use ($event, $to) {
                    try {
                        app(TransitionEventStatus::class)->handle($event, $to);
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('events.transition.done', ['status' => $to->label()]))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }))
            ->all();

        return [
            ...$transitions,
            DeleteAction::make(),
        ];
    }
}
