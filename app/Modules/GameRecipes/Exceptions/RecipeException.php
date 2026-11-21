<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Exceptions;

use DomainException;

final class RecipeException extends DomainException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems, string $message = 'The recipe is invalid.')
    {
        parent::__construct($message);
    }

    public static function file(string $path, string $reason): self
    {
        return new self(["{$path}: {$reason}"], 'The recipe file could not be read.');
    }

    public static function source(string $source, string $reason): self
    {
        return new self(["{$source}: {$reason}"], 'The recipe source is not available.');
    }

    public function summary(): string
    {
        return implode("\n", $this->problems);
    }
}
