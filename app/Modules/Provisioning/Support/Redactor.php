<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

/**
 * Scrubs text before it is persisted or printed: registered secret values,
 * Proxmox API token headers, private key blocks and `secret|token|password`
 * assignments. Steps should not produce such output in the first place; this
 * is the safety net.
 */
final class Redactor
{
    public const string MASK = '[redacted]';

    /**
     * @param  list<string>  $secrets  exact values to remove (shorter than 6 characters are ignored)
     */
    public static function redact(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', self::MASK, $text);
        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*/s', self::MASK, $text);
        $text = (string) preg_replace('/PVEAPIToken=\S+/', 'PVEAPIToken='.self::MASK, $text);

        return (string) preg_replace('/\b((?:[A-Za-z_]*(?:secret|token|password|passwd|apikey|api_key))\s*[=:]\s*)\S+/i', '$1'.self::MASK, $text);
    }

    public static function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : '[...] '.mb_substr($text, -$limit);
    }
}
