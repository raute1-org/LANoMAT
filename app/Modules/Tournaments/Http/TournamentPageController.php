<?php

namespace App\Modules\Tournaments\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\EnrollTeam;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Http\Requests\ConfirmReportRequest;
use App\Modules\Tournaments\Http\Requests\SubmitReportRequest;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TournamentPageController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * Every tournament belonging to the event, with a per-status enrollment
     * CTA the participant UI can render without re-deriving business rules.
     */
    public function index(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Tournament $tournament): array => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'startsAt' => $tournament->starts_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Tournaments/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'tournaments' => $tournaments,
            'labels' => [...trans('tournaments.page'), 'title' => trans('tournaments.page.index_title')],
            'statusLabels' => trans('tournaments.status'),
            'formatLabels' => trans('tournaments.format'),
        ]);
    }

    /**
     * The bracket view: every match belonging to the tournament (lean DTOs,
     * no leaked models) plus the viewer's own entry/match, if any, so the
     * page can surface "your match" report/confirm/dispute actions.
     */
    public function show(Request $request, Tournament $tournament): Response
    {
        $tournament->load('event');
        $event = $tournament->event;
        abort_if($event === null, 500, 'Tournament has no associated event.');
        abort_unless($event->isPubliclyVisible(), 404);

        $matches = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->with(['entry1', 'entry2'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        $user = $request->user();
        $myEntry = $user === null ? null : $this->entryFor($tournament, $user);

        return Inertia::render('Tournaments/Show', [
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'event' => ['name' => $event->name, 'slug' => $event->slug],
                'winnerEntryId' => $tournament->winner_entry_id,
            ],
            'matches' => $matches->map(fn (GameMatch $match): array => $this->matchDto($match))->all(),
            'myEntryId' => $myEntry?->id,
            'labels' => [...trans('tournaments.page'), 'title' => trans('tournaments.page.show_title')],
            'matchStatusLabels' => trans('tournaments.match_status'),
            'reportLabels' => trans('tournaments.report'),
        ]);
    }

    public function enroll(Request $request, Tournament $tournament, EnrollSolo $enrollSolo, EnrollTeam $enrollTeam): RedirectResponse
    {
        $this->authorize('enroll', $tournament);

        $user = $this->authUser($request);

        try {
            if ($tournament->team_size > 1) {
                $teamId = $request->integer('team_id');
                $team = Team::query()->findOrFail($teamId);
                $this->authorize('update', $team);
                $enrollTeam->handle($tournament, $team);
            } else {
                $enrollSolo->handle($tournament, $user);
            }
        } catch (TournamentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.enrollment.enrolled')]);

        return back();
    }

    public function checkin(Request $request, Tournament $tournament, CheckInEntry $action): RedirectResponse
    {
        $user = $this->authUser($request);
        $entry = $this->entryFor($tournament, $user);

        abort_if($entry === null, 404);

        $this->authorize('checkIn', $entry);

        try {
            $action->handle($entry);
        } catch (TournamentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.checkin.checked_in')]);

        return back();
    }

    public function report(SubmitReportRequest $request, GameMatch $match, SubmitMatchReport $action): RedirectResponse
    {
        $this->authorize('report', $match);

        $user = $this->authUser($request);
        $entry = $this->entryForMatch($match, $user);

        abort_if($entry === null, 404);

        $data = $request->validated();

        try {
            $action->handle($match, $entry, $data['score1'], $data['score2']);
        } catch (TournamentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.submitted')]);

        return back();
    }

    public function confirm(ConfirmReportRequest $request, GameMatch $match, ConfirmMatchReport $action): RedirectResponse
    {
        $report = $this->pendingReportFor($match);
        abort_if($report === null, 404);

        $this->authorize('confirm', $report);

        $user = $this->authUser($request);
        $entry = $this->entryForMatch($match, $user);

        abort_if($entry === null, 404);

        $data = $request->validated();

        try {
            $action->handle($report, $entry, $data['lock_version']);
        } catch (TournamentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.confirmed')]);

        return back();
    }

    public function dispute(Request $request, GameMatch $match, DisputeMatchReport $action): RedirectResponse
    {
        $report = $this->pendingReportFor($match);
        abort_if($report === null, 404);

        $this->authorize('dispute', $report);

        $user = $this->authUser($request);
        $entry = $this->entryForMatch($match, $user);

        abort_if($entry === null, 404);

        try {
            $action->handle($report, $entry);
        } catch (TournamentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.disputed')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function matchDto(GameMatch $match): array
    {
        return [
            'id' => $match->id,
            'round' => $match->round,
            'bracket' => $match->bracket,
            'position' => $match->position,
            'nextMatchId' => $match->next_match_id,
            'nextSlot' => $match->next_slot,
            'slot1' => $match->entry1?->display_name,
            'slot2' => $match->entry2?->display_name,
            // Entry ids (not full models) so the frontend can tell whether
            // the logged-in viewer participates in this match, without
            // leaking full TournamentEntry/User data into the bracket prop.
            'entry1Id' => $match->entry1_id,
            'entry2Id' => $match->entry2_id,
            'score1' => $match->score1,
            'score2' => $match->score2,
            'winnerEntryId' => $match->winner_entry_id,
            'status' => $match->status->value,
            'lockVersion' => $match->lock_version,
        ];
    }

    /**
     * The viewer's own entry for this tournament — directly owned, or via
     * team ownership — or null if they have none.
     */
    private function entryFor(Tournament $tournament, User $user): ?TournamentEntry
    {
        return TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('team', fn ($teamQuery) => $teamQuery->where('owner_id', $user->id));
            })
            ->first();
    }

    /**
     * Resolve which of the match's two entries belongs to `$user` (directly
     * or via team ownership) — the entry the write Actions act on behalf of.
     */
    private function entryForMatch(GameMatch $match, User $user): ?TournamentEntry
    {
        foreach ([$match->entry1, $match->entry2] as $entry) {
            if ($entry === null) {
                continue;
            }

            if ($entry->user_id === $user->id || $entry->team?->owner_id === $user->id) {
                return $entry;
            }
        }

        return null;
    }

    private function pendingReportFor(GameMatch $match): ?MatchReport
    {
        return MatchReport::query()
            ->where('match_id', $match->id)
            ->latest('id')
            ->first();
    }
}
