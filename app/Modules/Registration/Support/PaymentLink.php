<?php

namespace App\Modules\Registration\Support;

/**
 * Builds the payable URL from an orga-configured payment link. Pure, IO-free.
 *
 * paypal.me links get `/<amount>EUR` appended (replacing any amount already
 * in the path, dropping the query); any other https URL is returned unchanged.
 */
class PaymentLink
{
    public const MAX_LENGTH = 255;

    public static function isValid(?string $link): bool
    {
        if ($link === null || $link === '' || strlen($link) > self::MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($link);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return false;
        }

        // A paypal.me link without a handle cannot be paid.
        return ! self::isPayPalMe($link) || self::handle($link) !== null;
    }

    public static function appendsAmount(?string $link): bool
    {
        return self::isValid($link) && self::isPayPalMe((string) $link);
    }

    public static function for(?string $link, int $amountCents): ?string
    {
        if ($amountCents <= 0 || ! self::isValid($link)) {
            return null;
        }

        $link = (string) $link;

        if (! self::isPayPalMe($link)) {
            return $link;
        }

        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        $handle = self::handle($link);

        if ($handle === null) {
            return null;
        }

        return 'https://'.$host.'/'.$handle.'/'.self::formatAmount($amountCents).'EUR';
    }

    /** 2500 → "25", 2550 → "25.5", 2505 → "25.05", 1 → "0.01". */
    public static function formatAmount(int $cents): string
    {
        $formatted = intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function handle(string $link): ?string
    {
        $path = (string) parse_url($link, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        return $segments[0] ?? null;
    }

    private static function isPayPalMe(string $link): bool
    {
        $host = strtolower((string) (parse_url($link, PHP_URL_HOST) ?? ''));

        return $host === 'paypal.me' || $host === 'www.paypal.me';
    }
}
