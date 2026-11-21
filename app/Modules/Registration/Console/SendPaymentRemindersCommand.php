<?php

namespace App\Modules\Registration\Console;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\PaymentReminderSent;
use App\Modules\Registration\Notifications\PaymentReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendPaymentRemindersCommand extends Command
{
    protected $signature = 'registration:payment-reminders';

    protected $description = 'Remind participants of unpaid tickets (after registration / before the event), once per kind, 09:00-21:00 display time.';

    private const QUIET_BEFORE_HOUR = 9;

    private const QUIET_FROM_HOUR = 21;

    public function handle(): int
    {
        $now = Carbon::now((string) config('lanomat.timezone', 'Europe/Berlin'));

        if ($now->hour < self::QUIET_BEFORE_HOUR || $now->hour >= self::QUIET_FROM_HOUR) {
            return self::SUCCESS;
        }

        /** @var array<int, EventPaymentSettings> $settingsByEvent */
        $settingsByEvent = [];

        EventRegistration::query()
            ->whereIn('status', [RegistrationStatus::Pending->value, RegistrationStatus::Confirmed->value])
            ->whereNull('paid_at')
            ->where('ticket_price_cents', '>', 0)
            ->whereHas('event', fn ($q) => $q->whereNotIn('status', [EventStatus::Finished->value, EventStatus::Archived->value]))
            ->with(['event', 'user'])
            ->chunkById(200, function ($registrations) use ($now, &$settingsByEvent): void {
                foreach ($registrations as $registration) {
                    $event = $registration->event;
                    if ($event === null) {
                        continue;
                    }

                    $settings = $settingsByEvent[$registration->event_id] ??= EventPaymentSettings::forEvent($event);
                    $this->process($registration, $event, $settings, $now);
                }
            });

        return self::SUCCESS;
    }

    private function process(EventRegistration $registration, Event $event, EventPaymentSettings $settings, Carbon $now): void
    {
        $sent = PaymentReminderSent::query()
            ->where('event_registration_id', $registration->id)
            ->pluck('kind')
            ->all();

        $afterDue = $settings->reminder_after_days > 0
            && ! in_array(PaymentReminderSent::KIND_AFTER_REGISTRATION, $sent, true)
            && $registration->created_at !== null
            && $now->greaterThanOrEqualTo($registration->created_at->copy()->addDays($settings->reminder_after_days));

        $startsAt = $event->starts_at;
        $beforeDue = $settings->reminder_before_event_days > 0
            && ! in_array(PaymentReminderSent::KIND_BEFORE_EVENT, $sent, true)
            && $startsAt !== null
            && $now->lessThan($startsAt)
            && $now->greaterThanOrEqualTo($startsAt->copy()->subDays($settings->reminder_before_event_days));

        if ($beforeDue) {
            if ($afterDue) {
                // Late registration: one message is enough; the other kind counts as done.
                $this->record($registration, PaymentReminderSent::KIND_AFTER_REGISTRATION);
            }
            $this->remind($registration, PaymentReminderSent::KIND_BEFORE_EVENT);
        } elseif ($afterDue) {
            $this->remind($registration, PaymentReminderSent::KIND_AFTER_REGISTRATION);
        }
    }

    private function remind(EventRegistration $registration, string $kind): void
    {
        $user = $registration->user;

        if ($user !== null && $this->record($registration, $kind)) {
            $user->notify(new PaymentReminder($registration, $kind));
        }
    }

    /** Claims the (registration, kind) slot; false when a concurrent run already took it. */
    private function record(EventRegistration $registration, string $kind): bool
    {
        return PaymentReminderSent::query()->insertOrIgnore([
            'event_registration_id' => $registration->id,
            'kind' => $kind,
            'sent_at' => now(),
        ]) === 1;
    }
}
