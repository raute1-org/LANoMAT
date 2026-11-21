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

    /** A menu import that must not run (closed round, existing orders, ...): the reason is shown to the caller. */
    public static function importRefused(string $reason): self
    {
        return new self($reason, 'catering.errors.import_refused');
    }

    /** A set menu needs exactly `count` picks for the group. */
    public static function choicesIncomplete(string $group, int $count, int $given): self
    {
        return new self(
            "Selection group {$group} needs exactly {$count} picks, {$given} given.",
            'catering.errors.choices_incomplete',
            ['group' => $group, 'count' => $count, 'given' => $given],
        );
    }

    /** A pick that is not offered by the group, a repeat that is not allowed, or an unknown group. */
    public static function choicesInvalid(string $group): self
    {
        return new self("Invalid selection for {$group}.", 'catering.errors.choices_invalid', ['group' => $group]);
    }

    /** The set menu has a group without any pickable dish. */
    public static function choicesUnavailable(string $dish): self
    {
        return new self("{$dish} currently offers nothing to pick.", 'catering.errors.choices_unavailable', ['dish' => $dish]);
    }
}
