<?php

namespace App\Modules\Registration\Support;

/**
 * The self check-in URL encoded into the printed/shown QR code. Built from the
 * configured `APP_URL` instead of the request host, so a code generated while
 * the admin panel is opened via another hostname (IP, internal name) still
 * points at the public address participants can reach.
 */
class CheckinUrl
{
    public static function for(string $token): string
    {
        return rtrim((string) config('app.url'), '/').route('checkin.show', ['token' => $token], absolute: false);
    }
}
