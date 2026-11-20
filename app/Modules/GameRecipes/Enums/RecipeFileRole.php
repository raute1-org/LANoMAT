<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Enums;

/**
 * What a recipe file is for. `Avatar` is the multi-select content group: VRM
 * character files and other per-player cosmetics that live on the LAN file
 * host and are copied to the player's machine by the installer.
 */
enum RecipeFileRole: string
{
    case Base = 'base';
    case Iso = 'iso';
    case Patch = 'patch';
    case Mod = 'mod';
    case Tool = 'tool';
    case Config = 'config';
    case Avatar = 'avatar';
    case Content = 'content';

    public function isSelectable(): bool
    {
        return in_array($this, [self::Avatar, self::Content], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
