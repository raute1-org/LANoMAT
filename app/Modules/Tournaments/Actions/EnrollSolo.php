<?php

namespace App\Modules\Tournaments\Actions;

use App\Models\User;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

class EnrollSolo
{
    public function handle(Tournament $tournament, User $user): TournamentEntry
    {
        if ($tournament->status !== TournamentStatus::Enrollment) {
            throw TournamentException::notInEnrollment();
        }

        return DB::transaction(function () use ($tournament, $user): TournamentEntry {
            // Lock the PARENT tournament row first (M2 lock-order convention,
            // see RegisterForEvent) so concurrent enrollments serialize on
            // this row before reading the capacity count below.
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            $alreadyEnrolled = TournamentEntry::query()
                ->where('tournament_id', $tournament->id)
                ->where('user_id', $user->id)
                ->where('status', '!=', EntryStatus::Withdrawn->value)
                ->exists();

            if ($alreadyEnrolled) {
                throw TournamentException::alreadyEnrolled();
            }

            $activeCount = TournamentEntry::query()
                ->where('tournament_id', $tournament->id)
                ->where('status', '!=', EntryStatus::Withdrawn->value)
                ->count();

            if ($tournament->max_entries !== null && $activeCount >= $tournament->max_entries) {
                throw TournamentException::full();
            }

            $entry = new TournamentEntry([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'display_name' => $user->name,
            ]);
            $entry->status = EntryStatus::Registered;
            $entry->save();

            return $entry;
        });
    }
}
