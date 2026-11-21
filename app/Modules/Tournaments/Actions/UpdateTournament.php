<?php

namespace App\Modules\Tournaments\Actions;

use App\Models\User;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edits the setup fields of a tournament that has not opened for sign-up yet
 * (draft or announced). Authorizes `update`; same rules as
 * {@see CreateTournament}, every field optional. The check-in window is
 * validated against the stored value when only one side is given.
 */
class UpdateTournament
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Tournament $tournament, array $data): Tournament
    {
        Gate::forUser($actor)->authorize('update', $tournament);

        $validated = Validator::make(Arr::only($data, CreateTournament::FIELDS), CreateTournament::rules(partial: true), [
            'checkin_closes_at.after' => __('tournaments.errors.checkin_closes_after_opens'),
        ])->validate();

        return DB::transaction(function () use ($tournament, $validated): Tournament {
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Announced], true)) {
                throw TournamentException::notEditable();
            }

            $tournament->fill($validated);

            if ($tournament->checkin_opens_at !== null && $tournament->checkin_closes_at !== null
                && $tournament->checkin_closes_at->lte($tournament->checkin_opens_at)) {
                throw ValidationException::withMessages([
                    'checkin_closes_at' => __('tournaments.errors.checkin_closes_after_opens'),
                ]);
            }

            $tournament->save();

            return $tournament;
        });
    }
}
