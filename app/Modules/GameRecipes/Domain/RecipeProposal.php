<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Domain;

/**
 * One recipe on its way into the git catalogue: the files a pull request
 * carries and the text around it. Built from a database recipe; holds no
 * game data, only recipe text.
 */
final readonly class RecipeProposal
{
    /**
     * @param  array<string, string>  $files  repository-relative path => complete file contents
     */
    public function __construct(
        public string $slug,
        public string $branch,
        public string $commitMessage,
        public string $title,
        public string $body,
        public array $files,
    ) {}
}
