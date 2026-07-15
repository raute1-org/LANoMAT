<?php

namespace App\Modules\Catering\Exceptions;

use App\Modules\Catering\Enums\FoodOrderStatus;
use DomainException;

class CateringException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
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

    public static function invalidTransition(FoodOrderStatus $from, FoodOrderStatus $to): self
    {
        return new self(
            "Illegal food order status transition from {$from->value} to {$to->value}.",
            'catering.errors.invalid_transition'
        );
    }
}
