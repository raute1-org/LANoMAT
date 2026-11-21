<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

/**
 * Game Mode state as `GET /api/game` reports it: whether an effect is running,
 * the current mode and the configured mode names (game, police, start, ...).
 */
final readonly class GameStatus
{
    /**
     * @param  list<string>  $modes
     */
    public function __construct(
        public bool $reachable,
        public bool $active = false,
        public ?string $mode = null,
        public array $modes = [],
    ) {}

    public static function unreachable(): self
    {
        return new self(false);
    }

    public static function isValidMode(string $mode): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $mode) === 1;
    }
}
