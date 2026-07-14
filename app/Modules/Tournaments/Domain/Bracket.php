<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * Which sub-bracket a {@see BracketMatch} lives in.
 */
enum Bracket: string
{
    case Winners = 'winners';
    case Losers = 'losers';
    case Finals = 'finals';
}
