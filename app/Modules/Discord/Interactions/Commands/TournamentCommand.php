<?php

namespace App\Modules\Discord\Interactions\Commands;

use App\Modules\Discord\Interactions\InteractionPayload;
use App\Modules\Discord\Interactions\InteractionResponse;
use App\Modules\Discord\Jobs\SendFollowupJob;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;

/**
 * `/tournament list|info|checkin|bracket` — thin wrappers around the
 * existing M3 tournament actions/queries:
 *
 * - `list`: {@see CurrentEvent} resolver + a plain query over its tournaments.
 * - `info`: a plain lookup, no action involved.
 * - `checkin`: maps the Discord user to a local User, resolves their
 *   {@see TournamentEntry}, and calls {@see CheckInEntry} — the same action
 *   the web check-in endpoint uses.
 * - `bracket`: deferred (type 5) to exercise the follow-up-job path; the
 *   actual link is delivered by {@see SendFollowupJob}.
 */
class TournamentCommand
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return match (InteractionPayload::subcommand($payload)) {
            'list' => $this->list(),
            'info' => $this->info($payload),
            'checkin' => $this->checkin($payload),
            'bracket' => $this->bracket($payload),
            default => InteractionResponse::message(__('discord.unknown_command')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function list(): array
    {
        $event = app(CurrentEvent::class)->get();

        if ($event === null) {
            return InteractionResponse::message(__('discord.commands.tournament.list.no_current_event'));
        }

        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->orderBy('starts_at')
            ->get();

        if ($tournaments->isEmpty()) {
            return InteractionResponse::message(__('discord.commands.tournament.list.none'));
        }

        $content = $tournaments
            ->map(fn (Tournament $tournament): string => "- {$tournament->name} ({$tournament->status->label()})")
            ->implode("\n");

        return InteractionResponse::message($content);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function info(array $payload): array
    {
        $tournament = $this->findTournament($payload);

        if ($tournament === null) {
            return InteractionResponse::message(__('discord.commands.tournament.info.not_found'));
        }

        return InteractionResponse::message(__('discord.commands.tournament.info.summary', [
            'name' => $tournament->name,
            'format' => $tournament->format->label(),
            'status' => $tournament->status->label(),
            'starts_at' => $tournament->starts_at->toDateTimeString(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function checkin(array $payload): array
    {
        $user = InteractionPayload::mappedUser($payload);

        if ($user === null) {
            return InteractionResponse::message(__('discord.not_linked'));
        }

        $tournament = $this->findTournament($payload);

        if ($tournament === null) {
            return InteractionResponse::message(__('discord.commands.tournament.info.not_found'));
        }

        $entry = TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->ownedBy($user)
            ->first();

        if ($entry === null) {
            return InteractionResponse::message(__('discord.commands.tournament.checkin.no_entry'));
        }

        try {
            Gate::forUser($user)->authorize('checkIn', $entry);

            app(CheckInEntry::class)->handle($entry);
        } catch (AuthorizationException) {
            return InteractionResponse::message(__('discord.commands.tournament.checkin.no_entry'));
        } catch (TournamentException $e) {
            return InteractionResponse::message(__($e->translationKey));
        }

        return InteractionResponse::message(__('discord.commands.tournament.checkin.success', [
            'name' => $tournament->name,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function bracket(array $payload): array
    {
        $tournament = $this->findTournament($payload);
        $applicationId = $payload['application_id'] ?? null;
        $token = $payload['token'] ?? null;

        $content = $tournament === null
            ? __('discord.commands.tournament.bracket.not_found')
            : __('discord.commands.tournament.bracket.link', [
                'name' => $tournament->name,
                'url' => route('tournaments.show', $tournament),
            ]);

        if (is_string($applicationId) && is_string($token)) {
            Bus::dispatch(new SendFollowupJob($applicationId, $token, $content));
        }

        return InteractionResponse::deferred();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findTournament(array $payload): ?Tournament
    {
        $id = InteractionPayload::subcommandOption($payload, 'id');

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return Tournament::query()->find($id);
    }
}
