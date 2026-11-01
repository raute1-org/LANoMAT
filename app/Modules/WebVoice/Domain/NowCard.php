<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Domain;

use Carbon\CarbonInterface;

/**
 * One card of the native client's "now" list. `priority` only orders the feed (lower is more
 * urgent) and is not part of the wire shape; `data` is the kind specific payload.
 */
final readonly class NowCard
{
    public const KIND_MATCH = 'match';

    public const KIND_VOTE = 'vote';

    public const KIND_ORDER = 'order';

    public const KIND_NOTIFICATION = 'notification';

    public const KIND_SCHEDULE = 'schedule';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $state,
        public int $priority,
        public string $title,
        public ?string $body = null,
        public ?CarbonInterface $at = null,
        public array $data = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'state' => $this->state,
            'title' => $this->title,
            'body' => $this->body,
            'at' => $this->at?->toIso8601String(),
            'data' => $this->data,
        ];
    }
}
