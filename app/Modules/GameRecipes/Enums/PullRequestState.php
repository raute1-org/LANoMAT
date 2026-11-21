<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Enums;

/**
 * Where a recipe's pull request into the git catalogue stands. `Open`,
 * `Merged` and `Closed` mirror the repository; `Blocked` and `Failed` are
 * local: the proposal never left LANoMAT (public-safety check refused the
 * text, or the repository could not be reached).
 */
enum PullRequestState: string
{
    case Open = 'open';
    case Merged = 'merged';
    case Closed = 'closed';
    case Blocked = 'blocked';
    case Failed = 'failed';

    public function label(): string
    {
        return __('recipe_pull_requests.state.'.$this->value);
    }

    /** Whether a pull request exists in the repository for this state. */
    public function existsRemotely(): bool
    {
        return in_array($this, [self::Open, self::Merged, self::Closed], true);
    }

    /** Whether the status sync still has something to ask the repository. */
    public function isPending(): bool
    {
        return $this === self::Open;
    }
}
