<?php

namespace App\Modules\Registration\Support;

/** The text a payer pastes into the PayPal message: "<event> · <name> · <what>", max 140 chars. */
class PaymentReference
{
    public const MAX_LENGTH = 140;

    private const SEPARATOR = ' · ';

    public static function for(string $eventName, string $displayName, string $what): string
    {
        $tail = self::SEPARATOR.$displayName.self::SEPARATOR.$what;
        $room = self::MAX_LENGTH - mb_strlen($tail);

        if (mb_strlen($eventName) > $room) {
            $eventName = $room > 1 ? rtrim(mb_substr($eventName, 0, $room - 1)).'…' : '';
        }

        $reference = $eventName.$tail;

        return mb_strlen($reference) > self::MAX_LENGTH ? mb_substr($reference, 0, self::MAX_LENGTH) : $reference;
    }
}
