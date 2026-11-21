<?php

namespace App\Modules\Registration\Notifications;

use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\PaymentReminderSent;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\TicketLabel;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use App\Support\Money\Euro;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Reminds a participant of an unpaid ticket. Bell is the source of truth; the Discord DM mirrors
 * only per the `payment` category preference. The message links "Meine Anmeldung" and never
 * carries the raw payment link, so a changed link cannot go stale in old messages.
 */
class PaymentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly EventRegistration $registration,
        public readonly string $kind,
    ) {
        $this->category = 'payment';
    }

    /** The reminder is queued: re-check the registration at send time (paid / cancelled meanwhile). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $registration = EventRegistration::query()->find($this->registration->id);

        return $registration !== null
            && $registration->paid_at === null
            && $registration->status !== RegistrationStatus::Cancelled
            && (int) $registration->ticket_price_cents > 0;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(Modules::class)->enabled(Module::Discord) ? ['database', DiscordChannel::class] : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('registration.notifications.payment_reminder.title'),
            'body' => $this->body(),
        ];
    }

    public function toDiscord(object $notifiable): string
    {
        return $this->body();
    }

    private function body(): string
    {
        $event = $this->registration->event()->firstOrFail();
        $ticket = TicketType::findForEvent($event, $this->registration->ticket_type);
        $key = $this->kind === PaymentReminderSent::KIND_BEFORE_EVENT ? 'body_before_event' : 'body_after_registration';

        return __('registration.notifications.payment_reminder.'.$key, [
            'event' => $event->name,
            'ticket' => $ticket->name ?? TicketLabel::for($this->registration->ticket_type),
            'amount' => Euro::format((int) $this->registration->ticket_price_cents),
            'url' => route('events.register', $event),
        ]);
    }
}
