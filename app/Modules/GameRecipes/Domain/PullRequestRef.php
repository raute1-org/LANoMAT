<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Domain;

use App\Modules\GameRecipes\Enums\PullRequestState;

/** The pull request a proposal ended up in. */
final readonly class PullRequestRef
{
    public function __construct(
        public int $number,
        public string $url,
        public string $branch,
        public PullRequestState $state,
    ) {}
}
