<?php

namespace App\Modules\Catering\Exceptions;

use App\Modules\Catering\Enums\FoodOrderStatus;
use DomainException;

class CateringException extends DomainException
{
    /**
     * @param  array<string, string|int>  $parameters  placeholders for the translation
     */
    private function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    public static function notOpen(): self
    {
        return new self('The food order is not open for placement right now.', 'catering.errors.not_open');
    }

    public static function unknownOption(string $optionKey): self
    {
        return new self("Unknown menu option: {$optionKey}.", 'catering.errors.unknown_option');
    }

    public static function registrationRequired(): self
    {
        return new self('The user has no confirmed event registration.', 'catering.errors.registration_required');
    }

    public static function itemLimitReached(int $limit): self
    {
        return new self("Food item limit of {$limit} reached.", 'catering.errors.item_limit', ['limit' => $limit]);
    }

    public static function invalidTransition(FoodOrderStatus $from, FoodOrderStatus $to): self
    {
        return new self(
            "Illegal food order status transition from {$from->value} to {$to->value}.",
            'catering.errors.invalid_transition'
        );
    }
}
