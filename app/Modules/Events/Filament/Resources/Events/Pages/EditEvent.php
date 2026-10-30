<?php

namespace App\Modules\Events\Filament\Resources\Events\Pages;

use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Filament\Resources\Events\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\RotateEventCheckinToken;
use App\Modules\Registration\Models\EventCheckinToken;
use App\Modules\Registration\Support\CheckinUrl;
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

    private function currentEvent(): Event
    {
        /** @var Event $event */
        $event = $this->getRecord();

        return $event;
    }

    protected function getHeaderActions(): array
    {
        /** @var Event $event */
        $event = $this->getRecord();

        // One action per possible target status, shown only while the CURRENT
        // record allows it. The list is built once per mount, so visibility
        // must be evaluated lazily against the live record: otherwise the
        // header stays stale after a transition and the next click is lost.
        // A DomainException (e.g. a concurrent transition by another orga) is
        // surfaced as a notification instead of a 500.
        $transitions = collect(EventStatus::cases())
            ->reject(fn (EventStatus $to) => $to === EventStatus::Draft)
            ->map(fn (EventStatus $to) => Action::make('transition_'.$to->value)
                ->label(__('events.transition.'.$to->value))
                ->visible(fn () => in_array($to, $this->currentEvent()->status->allowedTransitions(), true))
                ->requiresConfirmation()
                ->action(function () use ($to) {
                    try {
                        app(TransitionEventStatus::class)->handle($this->currentEvent(), $to);
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

                    $this->currentEvent()->refresh();
                    $this->refreshFormData(['status']);
                }))
            ->values()
            ->all();

        $qrActions = [
            Action::make('checkinQr')
                ->label(__('registration.self_checkin.admin.show'))
                ->icon(Heroicon::OutlinedQrCode)
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('update', $event) ?? false)
                ->modalHeading(__('registration.self_checkin.admin.heading', ['event' => $event->name]))
                ->modalContent(function () use ($event) {
                    $url = CheckinUrl::for(EventCheckinToken::forEvent($event)->token);

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
