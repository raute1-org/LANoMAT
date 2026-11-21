<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

/**
 * Stable fingerprint of one tool call (tool name + arguments, key order
 * independent). The audit row stores it when the card is proposed and the tool
 * checks it again right before executing, so what ran is what the card showed.
 */
final class ArgumentsHash
{
    /** @param array<string, mixed> $arguments */
    public static function of(string $tool, array $arguments): string
    {
        return hash('sha256', $tool."\n".json_encode(self::sorted($arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
