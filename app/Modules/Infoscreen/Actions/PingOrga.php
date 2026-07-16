<?php

namespace App\Modules\Infoscreen\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Exceptions\InfoscreenException;
use App\Modules\Infoscreen\Notifications\OrgaPinged;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use Illuminate\Support\Facades\Notification;

/**
 * The "Orga rufen" participant action: any authenticated participant can
 * call for help, no ticket system behind it — just a ping to everyone with
 * an orga-or-above role, carrying the caller's seat (resolved via the M2
 * seat assignment, nullable if unseated) and up to three optional words.
 *
 * `$words` is validated here (not only in the controller's FormRequest) per
 * the M4 multi-entry-point lesson — this action must never persist an
 * arbitrarily long message regardless of caller.
 */
class PingOrga
{
    private const MAX_WORDS = 3;

    private const MAX_LENGTH = 40;

    public function handle(Event $event, User $caller, ?string $words): void
    {
        $words = $this->validateWords($words);

        $seatLabel = $this->seatLabelFor($event, $caller);

        $recipients = User::query()
            ->whereIn('role', [Role::Orga, Role::Admin, Role::Helper])
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new OrgaPinged($caller, $seatLabel, $words));
        }
    }

    private function validateWords(?string $words): ?string
    {
        $words = trim((string) $words);

        if ($words === '') {
            return null;
        }

        if (mb_strlen($words) > self::MAX_LENGTH) {
            throw InfoscreenException::orgaPingWordsTooLong();
        }

        $wordCount = count(preg_split('/\s+/', $words, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($wordCount > self::MAX_WORDS) {
            throw InfoscreenException::tooManyWords();
        }

        return $words;
    }

    private function seatLabelFor(Event $event, User $caller): ?string
    {
        $registrationId = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $caller->id)
            ->value('id');

        if ($registrationId === null) {
            return null;
        }

        return Seat::query()
            ->whereHas('assignment', fn ($q) => $q->where('registration_id', $registrationId))
            ->value('label');
    }
}
