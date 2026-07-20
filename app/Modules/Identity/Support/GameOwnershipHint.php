<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\Identity\Enums\OwnershipHintStatus;

/**
 * Whether a user is likely to own the game a tournament is for — an
 * ADVISORY HINT ONLY, computed from a linked account's provider-reported
 * ownership. This is deliberately NEVER wired into any authorization or
 * enrollment gate: LAN games without an online requirement, and any game
 * with no provider mapping at all, must always be enrollable regardless of
 * what this returns. Consumers (e.g. the enrollment UI) may render this as
 * a calm, non-blocking warning — never disable a submit button or fail a
 * request because of it. See tests/Feature/Identity/OwnershipHintNeverBlocksTest.php,
 * the guardrail that pins this rule.
 *
 * Resolves to Unknown (not Owned/NotOwned) whenever the question genuinely
 * cannot be answered: no provider mapping on the game (the common case —
 * most games leave provider/provider_app_id null, so this produces no
 * warning noise), no linked account for that provider, or the connector
 * itself came back unknown (private profile, API failure, or a provider
 * with no ownership concept like Twitch).
 */
final class GameOwnershipHint
{
    public static function for(User $user, Game $game): OwnershipHintStatus
    {
        if ($game->provider === null || $game->provider_app_id === null) {
            return OwnershipHintStatus::Unknown;
        }

        $account = $user->linkedAccount($game->provider);

        if ($account === null) {
            return OwnershipHintStatus::Unknown;
        }

        $owns = app(LinkedAccountConnectors::class)->for($game->provider)->ownsApp($account, $game->provider_app_id);

        return match ($owns) {
            true => OwnershipHintStatus::Owned,
            false => OwnershipHintStatus::NotOwned,
            null => OwnershipHintStatus::Unknown,
        };
    }
}
