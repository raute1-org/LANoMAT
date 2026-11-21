<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Exceptions;

use RuntimeException;

/**
 * The git catalogue could not take a proposal. `transient` separates "try
 * again later" (network, rate limit, 5xx) from "this will not work until
 * someone changes the configuration or the proposal". The message never
 * contains the access token.
 */
final class RecipeRepositoryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('Recipe pull requests are not configured.');
    }

    public static function unreachable(string $reason): self
    {
        return new self('The recipe repository is not reachable: '.$reason, transient: true);
    }

    public static function rejected(int $status, string $reason): self
    {
        return new self("The recipe repository refused the request ({$status}): {$reason}");
    }
}
