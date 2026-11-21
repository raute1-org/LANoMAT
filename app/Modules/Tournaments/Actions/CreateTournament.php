<?php

namespace App\Modules\Tournaments\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The single write path for a new tournament (Filament create page and the
 * assistant). Authorizes `create` for the acting user and validates the same
 * rules as the Filament form; the tournament always starts as a draft.
 * Times are expected in the application timezone (UTC), like Filament passes.
 */
class CreateTournament
{
    /** The setup fields an author may set; everything else is ignored. */
    public const FIELDS = [
        'name', 'format', 'team_size', 'starts_at', 'game_id', 'max_entries',
        'checkin_opens_at', 'checkin_closes_at', 'rules', 'settings',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Event $event, array $data): Tournament
    {
        Gate::forUser($actor)->authorize('create', Tournament::class);

        $validated = Validator::make(Arr::only($data, self::FIELDS), self::rules(), [
            'checkin_closes_at.after' => __('tournaments.errors.checkin_closes_after_opens'),
        ])->validate();

        $tournament = new Tournament($validated);
        $tournament->event()->associate($event);
        $tournament->status = TournamentStatus::Draft;
        $tournament->save();

        return $tournament;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'format' => [$required, Rule::enum(TournamentFormat::class)],
            'team_size' => [$required, 'integer', 'min:1', 'max:32'],
            'starts_at' => [$required, 'date'],
            'game_id' => ['nullable', 'integer', 'exists:games,id'],
            'max_entries' => ['nullable', 'integer', 'min:2', 'max:1024'],
            'checkin_opens_at' => ['nullable', 'date'],
            'checkin_closes_at' => ['nullable', 'date', 'after:checkin_opens_at'],
            'rules' => ['nullable', 'string', 'max:10000'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
