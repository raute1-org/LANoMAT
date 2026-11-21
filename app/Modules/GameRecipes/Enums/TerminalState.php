<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Enums;

use App\Modules\GameRecipes\Models\LauncherTerminal;

/**
 * What a launcher terminal reports over the heartbeat. A terminal is not a
 * state machine the server drives: it says what it is doing, the monitor shows
 * it, and `last_seen_at` decides whether the terminal still counts as online
 * ({@see LauncherTerminal::derivedState()}).
 */
enum TerminalState: string
{
    case Online = 'online';
    case Playing = 'playing';
    case Idle = 'idle';
    case Offline = 'offline';

    public function label(): string
    {
        return __('game_recipes.terminal_state.'.$this->value);
    }

    /** Badge colour in the admin monitor (Filament's token names). */
    public function color(): string
    {
        return match ($this) {
            self::Playing => 'success',
            self::Online, self::Idle => 'info',
            self::Offline => 'gray',
        };
    }
}
