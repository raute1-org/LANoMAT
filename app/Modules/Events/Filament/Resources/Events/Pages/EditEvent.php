<?php

namespace App\Modules\Events\Filament\Resources\Events\Pages;

use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Filament\Resources\Events\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\RotateEventCheckinToken;
use App\Modules\Registration\Models\EventCheckinToken;
use App\Modules\Registration\Support\QrCode;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

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

        $qrActions = [
            Action::make('checkinQr')
                ->label(__('registration.self_checkin.admin.show'))
                ->icon(Heroicon::OutlinedQrCode)
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('update', $event) ?? false)
                ->modalHeading(__('registration.self_checkin.admin.heading', ['event' => $event->name]))
                ->modalContent(function () use ($event) {
                    $url = route('checkin.show', ['token' => EventCheckinToken::forEvent($event)->token]);

                    return view('filament.checkin-qr', ['svg' => app(QrCode::class)->svg($url), 'url' => $url]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('registration.self_checkin.admin.close'))
                ->extraModalFooterActions([
                    Action::make('checkinQrPrint')
                        ->label(__('registration.self_checkin.admin.print'))
                        ->icon(Heroicon::OutlinedPrinter)
                        ->url(route('orga.checkin.print', ['event' => $event->slug]), shouldOpenInNewTab: true),
                ]),
            Action::make('rotateCheckinToken')
                ->label(__('registration.self_checkin.admin.rotate'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('update', $event) ?? false)
                ->requiresConfirmation()
                ->modalHeading(__('registration.self_checkin.admin.rotate_heading'))
                ->modalDescription(__('registration.self_checkin.admin.rotate_description'))
                ->action(function () use ($event): void {
                    app(RotateEventCheckinToken::class)->handle($event);

                    Notification::make()
                        ->title(__('registration.self_checkin.admin.rotated'))
                        ->success()
                        ->send();
                }),
        ];

        $arrivalAction = Action::make('arrivalOverview')
            ->label(__('registration.admin.arrival_overview'))
            ->icon(Heroicon::OutlinedChartBar)
            ->color('gray')
            ->authorize(fn () => auth()->user()?->can('view', $event) ?? false)
            ->url(EventResource::getUrl('arrival', ['record' => $event]));

        return [
            $arrivalAction,
            ...$qrActions,
            ...$transitions,
            DeleteAction::make(),
        ];
    }
}
