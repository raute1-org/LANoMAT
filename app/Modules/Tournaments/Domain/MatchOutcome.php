<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * How a match result was reached. `Score` is a normally played-out result
 * (scores are given explicitly); the `Forfeit*` cases describe a no-show or
 * withdrawal, where the forfeiting slot is recorded with a score of 0 and
 * the opponent is awarded the win.
 */
enum MatchOutcome
{
    case Score;
    case ForfeitSlot1;
    case ForfeitSlot2;
}
