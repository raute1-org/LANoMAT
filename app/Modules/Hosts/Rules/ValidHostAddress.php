<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A remote host address: an RFC 1123 hostname (dot-separated labels of
 * letters, digits and inner hyphens, each at most 63 characters, 253 in
 * total) or an IPv4/IPv6 literal. Nothing else (no spaces, no shell
 * metacharacters, no scheme or port) ever reaches the SSH layer.
 */
class ValidHostAddress implements ValidationRule
{
    public static function isValid(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/', $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('hosts.errors.invalid_hostname')->translate();
        }
    }
}
