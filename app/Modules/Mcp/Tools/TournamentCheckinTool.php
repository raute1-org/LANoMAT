<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\MyTournamentState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('tournament_checkin')]
#[Description('Check the caller\'s own tournament entry in. Works only inside the check-in window and with a valid event registration; the entry must already exist.')]
class TournamentCheckinTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tournament_id' => $schema->integer()->description('Tournament id.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $checkIn = app(CheckInEntry::class);
        $user = $this->user();
        $tournament = Tournament::query()->findOrFail((int) $request->get('tournament_id'));
        abort_unless($tournament->isVisibleTo($user), 404);

        // Same resolution and policy as the participant page: only the entry's
        // owner (user or team owner) may check it in, and the event
        // registration requirement is part of the policy.
        $entry = MyTournamentState::entryFor($tournament, $user);
        abort_if($entry === null, 404, 'You have no entry in this tournament.');

        Gate::forUser($user)->authorize('checkIn', $entry);

        $entry = $checkIn->handle($entry);

        return $this->structured([
            'entry' => [
                'id' => $entry->id,
                'tournament_id' => $tournament->id,
                'status' => $entry->status->value,
                'checked_in_at' => $entry->checked_in_at?->toIso8601String(),
            ],
        ]);
    }
}
