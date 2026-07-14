<?php

namespace App\Modules\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Announced = 'announced';
    case Registration = 'registration';
    case Live = 'live';
    case Finished = 'finished';
    case Archived = 'archived';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Announced],
            self::Announced => [self::Registration],
            self::Registration => [self::Live],
            self::Live => [self::Finished],
            self::Finished => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return __('events.status.'.$this->value);
    }
}
