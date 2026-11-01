<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Modules\Presence\Domain\PersonLocation;

/**
 * The last resolved view of where everybody is: one {@see PersonLocation} per user who is
 * somewhere, when it was taken, and when each user was last seen anywhere. Only user ids and
 * room/server facts are kept; Discord accounts without a LANoMAT user are never stored.
 */
final readonly class LocationSnapshot
{
    /**
     * @param  array<int, PersonLocation>  $people
     * @param  array<int, string>  $lastSeen  user id => ISO time they were last seen in any room or game
     */
    public function __construct(
        public string $builtAt,
        public array $people = [],
        public array $lastSeen = [],
    ) {}

    /**
     * @return array{builtAt: string, people: list<array<string, mixed>>, lastSeen: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'builtAt' => $this->builtAt,
            'people' => array_values(array_map(fn (PersonLocation $p): array => $p->toArray(), $this->people)),
            'lastSeen' => $this->lastSeen,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $people = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($data['people'] ?? null) ? $data['people'] : [];

        foreach ($rows as $row) {
            $person = PersonLocation::fromArray($row);
            $people[$person->userId] = $person;
        }

        $lastSeen = [];

        foreach (is_array($data['lastSeen'] ?? null) ? $data['lastSeen'] : [] as $userId => $at) {
            $lastSeen[(int) $userId] = (string) $at;
        }

        return new self((string) ($data['builtAt'] ?? ''), $people, $lastSeen);
    }
}
