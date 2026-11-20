<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Actions\WithdrawMatchReport;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Support\MyTournamentState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('match_result')]
#[Description('Report, confirm, dispute or withdraw a tournament match result as the caller. report needs score1/score2 (own/opponent); confirm/withdraw need the current lock_version.')]
class MatchResultTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'match_id' => $schema->integer()->description('Match id from tournament_bracket.')->required(),
            'action' => $schema->string()->enum(['report', 'confirm', 'dispute', 'withdraw'])->required(),
            'score1' => $schema->integer()->description('Own score (report only).'),
            'score2' => $schema->integer()->description('Opponent score (report only).'),
            'lock_version' => $schema->integer()->description('Match lock_version (confirm/withdraw).'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        // The same rules the participant form applies (SubmitReportRequest):
        // a report needs both scores and they must differ; confirm/withdraw
        // need the lock_version the caller last read.
        $data = $request->validate([
            'match_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['report', 'confirm', 'dispute', 'withdraw'])],
            'score1' => ['required_if:action,report', 'integer', 'min:0'],
            'score2' => ['required_if:action,report', 'integer', 'min:0', 'different:score1'],
            'lock_version' => ['nullable', 'integer', 'min:0'],
        ], [], [
            // Keep the machine names in the error text: lang/de/validation.php
            // maps the attributes to German labels otherwise, and the model
            // needs the field names it was given.
            'match_id' => 'match_id',
            'action' => 'action',
            'score1' => 'score1',
            'score2' => 'score2',
            'lock_version' => 'lock_version',
        ]);

        $match = GameMatch::query()->with(['tournament', 'entry1.team', 'entry2.team'])->findOrFail((int) $data['match_id']);
        $user = $this->user();
        $gate = Gate::forUser($user);

        abort_unless($match->tournament?->isVisibleTo($user) ?? false, 404);

        $entry = MyTournamentState::entryForMatch($match, $user);
        abort_if($entry === null, 404, 'You are not an entrant of this match.');

        $action = (string) $data['action'];
        $report = null;

        if ($action === 'report') {
            $gate->authorize('report', $match);

            $report = app(SubmitMatchReport::class)->handle(
                $match,
                $entry,
                (int) $data['score1'],
                (int) $data['score2'],
            );
        } else {
            $pending = MyTournamentState::pendingReportFor($match);

            abort_if($pending === null, 422, 'There is no pending report for this match.');

            // Same policy calls as the participant web actions, so the MCP
            // surface can never act where the page would refuse.
            if ($action === 'confirm') {
                $gate->authorize('confirm', $pending);
                app(ConfirmMatchReport::class)->handle($pending, $entry, (int) ($data['lock_version'] ?? $match->lock_version));
            } elseif ($action === 'withdraw') {
                $gate->authorize('withdrawReport', $pending);
                app(WithdrawMatchReport::class)->handle($pending, $entry, (int) ($data['lock_version'] ?? $match->lock_version));
            } else {
                $gate->authorize('dispute', $pending);
                app(DisputeMatchReport::class)->handle($pending, $entry);
            }

            $report = $pending->refresh();
        }

        $match->refresh();

        return $this->structured([
            'match' => [
                'id' => $match->id,
                'status' => $match->status->value,
                'score1' => $match->score1,
                'score2' => $match->score2,
                'lock_version' => $match->lock_version,
            ],
            'report' => [
                'id' => $report->id,
                'status' => $report->status->value,
            ],
        ]);
    }
}
