<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The single display/input timezone (`config('lanomat.timezone')`, default
 * Europe/Berlin). Storage is UTC; convert only at the edges via this class.
 */
class DisplayTimezone
{
    public static function name(): string
    {
        return (string) config('lanomat.timezone');
    }

    public static function local(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::name());
    }
}
