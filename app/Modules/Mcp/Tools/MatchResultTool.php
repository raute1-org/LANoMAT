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
            'lock_version' => $schema->integer()->description('Match lock_version (required for confirm/withdraw).'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $match = GameMatch::query()->with(['tournament', 'entry1.team', 'entry2.team'])->findOrFail((int) $request->get('match_id'));
        $user = $this->user();

        abort_unless($match->tournament?->isVisibleTo($user) ?? false, 404);

        $entry = MyTournamentState::entryForMatch($match, $user);
        abort_if($entry === null, 404, 'You are not an entrant of this match.');

        $action = (string) $request->get('action');

        if ($action === 'report') {
            $score1 = (int) $request->get('score1', 0);
            $score2 = (int) $request->get('score2', 0);

            abort_if($score1 < 0 || $score2 < 0 || $score1 > 9999 || $score2 > 9999, 422, 'Scores must be between 0 and 9999.');
            abort_if($score1 === $score2, 422, 'A tournament match cannot end in a draw; report a winner.');

            $report = app(SubmitMatchReport::class)->handle($match, $entry, $score1, $score2);
        } else {
            $report = MyTournamentState::pendingReportFor($match);

            abort_if($report === null, 422, 'There is no pending report for this match.');

            if (in_array($action, ['confirm', 'withdraw'], true) && $request->get('lock_version') === null) {
                abort(422, 'lock_version is required for confirm/withdraw.');
            }

            if ($action === 'confirm') {
                app(ConfirmMatchReport::class)->handle($report, $entry, (int) $request->get('lock_version'));
            } elseif ($action === 'withdraw') {
                app(WithdrawMatchReport::class)->handle($report, $entry, (int) $request->get('lock_version'));
            } else {
                app(DisputeMatchReport::class)->handle($report, $entry);
            }
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
