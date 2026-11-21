<?php

namespace App\Modules\Registration\Filament\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Support\PaymentLink;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/** Header action on the event edit page: payment link, note and reminder lead times. */
class EditPaymentSettingsAction
{
    public static function make(Event $event): Action
    {
        return Action::make('editPaymentSettings')
            ->label(__('registration.admin.payment.action'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('gray')
            ->authorize(fn (): bool => Gate::allows('update', EventPaymentSettings::forEvent($event)))
            ->modalHeading(__('registration.admin.payment.heading'))
            ->fillForm(function () use ($event): array {
                $settings = EventPaymentSettings::forEvent($event);

                return [
                    'payment_link' => $settings->payment_link,
                    'payment_note' => $settings->payment_note,
                    'reminder_after_days' => $settings->reminder_after_days,
                    'reminder_before_event_days' => $settings->reminder_before_event_days,
                ];
            })
            ->schema([
                TextInput::make('payment_link')
                    ->label(__('registration.admin.payment.link'))
                    ->helperText(__('registration.admin.payment.link_help'))
                    ->maxLength(PaymentLink::MAX_LENGTH)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && $value !== '' && ! PaymentLink::isValid($value)) {
                            $fail(__('registration.admin.payment.link_invalid'));
                        }
                    }),
                Textarea::make('payment_note')
                    ->label(__('registration.admin.payment.note'))
                    ->maxLength(160)
                    ->rows(2),
                TextInput::make('reminder_after_days')
                    ->label(__('registration.admin.payment.after_days'))
                    ->required()->numeric()->integer()->minValue(0)->maxValue(60),
                TextInput::make('reminder_before_event_days')
                    ->label(__('registration.admin.payment.before_days'))
                    ->required()->numeric()->integer()->minValue(0)->maxValue(60),
            ])
            ->action(function (array $data) use ($event): void {
                $link = is_string($data['payment_link'] ?? null) && $data['payment_link'] !== '' ? $data['payment_link'] : null;
                $note = is_string($data['payment_note'] ?? null) && trim($data['payment_note']) !== '' ? trim($data['payment_note']) : null;

                EventPaymentSettings::query()->updateOrCreate(
                    ['event_id' => $event->id],
                    [
                        'payment_link' => $link,
                        'payment_note' => $note,
                        'reminder_after_days' => (int) $data['reminder_after_days'],
                        'reminder_before_event_days' => (int) $data['reminder_before_event_days'],
                    ],
                );

                Notification::make()->title(__('registration.admin.payment.saved'))->success()->send();
            });
    }
}
