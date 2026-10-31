<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

use App\Modules\Provisioning\Exceptions\ProvisioningException;

/**
 * Strict input patterns. Everything that ends up in a command line, a file or
 * a hypervisor parameter passes one of these first (defence in depth next to
 * per-value shell escaping).
 */
final class Validate
{
    public static function ip(string $value, string $field): string
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $value : self::fail($field);
    }

    public static function cidr(string $value, string $field): string
    {
        if (preg_match('/^(.+)\/(\d{1,2})$/', $value, $m) !== 1 || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $m[2] > 32) {
            self::fail($field);
        }

        return $value;
    }

    public static function name(string $value, string $field): string
    {
        return preg_match('/^[a-z][a-z0-9-]{0,39}$/', $value) === 1 ? $value : self::fail($field);
    }

    public static function token(string $value, string $field): string
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) === 1 ? $value : self::fail($field);
    }

    public static function domain(string $value, string $field): string
    {
        return preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $value) === 1 ? $value : self::fail($field);
    }

    public static function timezone(string $value, string $field): string
    {
        return preg_match('#^[A-Za-z]+(/[A-Za-z0-9_+-]+){0,2}$#', $value) === 1 ? $value : self::fail($field);
    }

    public static function publicKey(string $value, string $field): string
    {
        return preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp\d+) [A-Za-z0-9+\/=]+( [^\r\n]*)?$/', $value) === 1 ? $value : self::fail($field);
    }

    public static function int(mixed $value, string $field, int $min, int $max): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : self::fail($field);
    }

    public static function string(mixed $value, string $field): string
    {
        return is_string($value) && $value !== '' ? $value : self::fail($field);
    }

    private static function fail(string $field): never
    {
        throw ProvisioningException::invalidConfig($field);
    }
}
