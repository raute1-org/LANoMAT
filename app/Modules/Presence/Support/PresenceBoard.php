<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

/**
 * The full presence board for an event — every checked-in participant with
 * their seat/activity, the tournaments still joinable ("free slots"), the
 * matches currently live, and the checked-in headcount. Produced by
 * {@see PresenceProjection::forEvent()}.
 */
final readonly class PresenceBoard
{
    /**
     * @param  list<ParticipantPresence>  $participants
     * @param  list<FreeSlot>  $freeSlots
     * @param  list<LiveMatchPresence>  $liveMatches
     */
    public function __construct(
        public array $participants,
        public array $freeSlots,
        public array $liveMatches,
        public int $checkedInCount,
    ) {}

    /**
     * @return array{participants: list<array<string, mixed>>, freeSlots: list<array<string, mixed>>, liveMatches: list<array<string, mixed>>, checkedInCount: int}
     */
    public function toArray(): array
    {
        return [
            'participants' => array_map(fn (ParticipantPresence $p): array => $p->toArray(), $this->participants),
            'freeSlots' => array_map(fn (FreeSlot $s): array => $s->toArray(), $this->freeSlots),
            'liveMatches' => array_map(fn (LiveMatchPresence $m): array => $m->toArray(), $this->liveMatches),
            'checkedInCount' => $this->checkedInCount,
        ];
    }
}
