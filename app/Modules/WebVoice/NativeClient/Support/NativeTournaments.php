<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EventRegistrationRequirement;
use App\Modules\Tournaments\Support\MyTournamentState;

/**
 * The event's tournaments for the native client, with the viewer's enrollment state: the same
 * visibility (no drafts for participants), ordering and entry rules as the web tournament list.
 */
final class NativeTournaments
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forEvent(User $user, Event $event): array
    {
        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->visibleTo($user)
            ->orderBy('starts_at')
            ->with('game')
            ->withCount(['entries as active_entries_count' => fn ($query) => $query->where('status', '!=', EntryStatus::Withdrawn->value)])
            ->get()
            ->sortBy(fn (Tournament $tournament): int => match ($tournament->status) {
                TournamentStatus::Live => 0,
                TournamentStatus::CheckIn => 1,
                TournamentStatus::Enrollment => 2,
                TournamentStatus::Announced => 3,
                TournamentStatus::Draft => 4,
                TournamentStatus::Finished => 5,
            })
            ->values();

        $registration = EventRegistrationRequirement::viewState($user, $event);

        return array_values($tournaments->map(fn (Tournament $tournament): array => self::dto($user, $tournament, (int) $tournament->getAttribute('active_entries_count'), $registration))->all());
    }

    /**
     * @param  array{required: bool, open: bool, url: string}|null  $registration
     * @return array<string, mixed>
     */
    public static function dto(User $user, Tournament $tournament, ?int $entryCount = null, ?array $registration = null): array
    {
        $owned = MyTournamentState::entryFor($tournament, $user);
        $entry = $owned ?? MyTournamentState::rosteredEntryFor($tournament, $user);
        $state = MyTournamentState::entryState($user, $entry);

        return [
            'id' => $tournament->id,
            'name' => $tournament->name,
            'game' => $tournament->game?->name,
            'format' => $tournament->format->value,
            'status' => $tournament->status->value,
            'startsAt' => $tournament->starts_at->toIso8601String(),
            'checkinOpensAt' => $tournament->checkin_opens_at?->toIso8601String(),
            'checkinClosesAt' => $tournament->checkin_closes_at?->toIso8601String(),
            'entryCount' => $entryCount ?? $tournament->entries()->where('status', '!=', EntryStatus::Withdrawn->value)->count(),
            'maxEntries' => $tournament->max_entries,
            'teamSize' => $tournament->team_size,
            'myEntry' => $state,
            'can' => [
                'checkIn' => $owned instanceof TournamentEntry && MyTournamentState::canCheckIn($user, $owned, $tournament),
                'withdraw' => $state['canWithdraw'] ?? false,
            ],
            'registration' => $registration ?? EventRegistrationRequirement::viewState($user, $tournament->event()->firstOrFail()),
        ];
    }
}
