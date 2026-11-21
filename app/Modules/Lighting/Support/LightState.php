<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use InvalidArgumentException;

/**
 * A desired light state. Every field is optional (null = leave unchanged).
 * Colours are normalised to lower-case `#rrggbb`; numeric fields are clamped
 * to their range. Strobe is 0 (off) or 10..255 (speed); values 1..9 are
 * bumped to 10, the bridge's slowest strobe.
 */
final readonly class LightState
{
    public const MIN_STROBE = 10;

    public const MAX_STROBE = 255;

    public function __construct(
        public ?string $hex = null,
        public ?float $brightness = null,
        public ?float $temperature = null,
        public ?float $whiteLevel = null,
        public ?int $strobe = null,
    ) {}

    public static function make(
        ?string $hex = null,
        int|float|string|null $brightness = null,
        int|float|string|null $temperature = null,
        int|float|string|null $whiteLevel = null,
        int|float|string|null $strobe = null,
    ): self {
        return new self(
            $hex === null || $hex === '' ? null : self::normalizeHex($hex),
            self::unit($brightness, 'brightness'),
            self::unit($temperature, 'temperature'),
            self::unit($whiteLevel, 'white_level'),
            self::strobeValue($strobe),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data  keys: hex, brightness, temperature, white_level, strobe
     */
    public static function fromArray(array $data): self
    {
        return self::make(
            self::stringOrNull($data['hex'] ?? null),
            self::numberOrNull($data['brightness'] ?? null),
            self::numberOrNull($data['temperature'] ?? null),
            self::numberOrNull($data['white_level'] ?? null),
            self::numberOrNull($data['strobe'] ?? null),
        );
    }

    public static function normalizeHex(string $hex): string
    {
        $hex = strtolower(ltrim(trim($hex), '#'));

        if (preg_match('/^[0-9a-f]{3}$/', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException('Invalid hex colour.');
        }

        return '#'.$hex;
    }

    public static function isValidHex(string $hex): bool
    {
        try {
            self::normalizeHex($hex);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function withStrobe(?int $strobe): self
    {
        return new self($this->hex, $this->brightness, $this->temperature, $this->whiteLevel, $strobe);
    }

    public function isEmpty(): bool
    {
        return $this->hex === null && $this->brightness === null && $this->temperature === null
            && $this->whiteLevel === null && $this->strobe === null;
    }

    /**
     * Bridge request body (only the fields that are set).
     *
     * @return array<string, string|int|float>
     */
    public function toPayload(): array
    {
        return array_filter([
            'hex' => $this->hex,
            'brightness' => $this->brightness,
            'temperature' => $this->temperature,
            'white_level' => $this->whiteLevel,
            'strobe' => $this->strobe,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, string|int|float>
     */
    public function toArray(): array
    {
        return $this->toPayload();
    }

    private static function unit(int|float|string|null $value, string $name): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("{$name} must be a finite number.");
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    private static function strobeValue(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('strobe must be a finite number.');
        }

        $strobe = (int) round((float) $value);

        if ($strobe <= 0) {
            return 0;
        }

        return max(self::MIN_STROBE, min(self::MAX_STROBE, $strobe));
    }

    private static function numberOrNull(mixed $v): int|float|string|null
    {
        return is_int($v) || is_float($v) || is_string($v) ? $v : null;
    }

    private static function stringOrNull(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
