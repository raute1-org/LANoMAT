<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('tournament_bracket')]
#[Description('The bracket/matches of one tournament: rounds, entrants, scores and status.')]
#[IsReadOnly]
class TournamentBracketTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tournament_id' => $schema->integer()->description('Tournament id from tournament_list.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $tournament = Tournament::query()->with('event')->findOrFail((int) $request->get('tournament_id'));

        abort_unless($tournament->isVisibleTo($this->user()), 404);

        $matches = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->with(['entry1:id,display_name', 'entry2:id,display_name'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        return $this->structured([
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'event_slug' => $tournament->event?->slug,
            ],
            'matches' => $matches->map(static fn (GameMatch $match): array => [
                'id' => $match->id,
                'round' => $match->round,
                'bracket' => $match->bracket,
                'position' => $match->position,
                'status' => $match->status->value,
                'entry1' => $match->entry1?->display_name,
                'entry2' => $match->entry2?->display_name,
                'score1' => $match->score1,
                'score2' => $match->score2,
            ])->all(),
        ]);
    }
}
