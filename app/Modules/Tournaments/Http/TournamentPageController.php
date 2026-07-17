<?php

namespace App\Modules\Tournaments\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\EnrollTeam;
use App\Modules\Tournaments\Actions\GoLive;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Http\Requests\ConfirmReportRequest;
use App\Modules\Tournaments\Http\Requests\SubmitReportRequest;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use App\Modules\Voice\Support\VoiceJoinLink;
use App\Modules\Voice\Support\VoiceOccupancy;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $matches = BracketMatchProjection::matchesForTournament($tournament->id);

        $user = $request->user();
        $myEntry = $user === null ? null : $this->entryFor($tournament, $user);

        $myMatch = $myEntry === null ? null : $this->activeMatchFor($matches, $myEntry);

        return Inertia::render('Tournaments/Show', [
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'event' => ['name' => $event->name, 'slug' => $event->slug],
                'winnerEntryId' => $tournament->winner_entry_id,
            ],
            'matches' => $matches->map(fn (GameMatch $match): array => BracketMatchProjection::fromMatch($match))->all(),
            'myEntryId' => $myEntry?->id,
            'myMatchVoiceLinks' => $myMatch === null ? [] : $this->voiceLinksFor($myMatch, $myEntry),
            // Gates the match card's manual "Go" control (Task 11) —
            // mirrors TournamentPolicy::goLive (helper-or-above) so the
            // button is only rendered for someone who could actually submit
            // the action; the server-side Gate check in GoLive remains the
            // real authorization boundary regardless of this flag.
            'canGoLive' => $user !== null && $user->isHelper(),
            'labels' => [...trans('tournaments.page'), 'title' => trans('tournaments.page.show_title')],
            'statusLabels' => trans('tournaments.status'),
            'matchStatusLabels' => trans('tournaments.match_status'),
            'reportLabels' => trans('tournaments.report'),
            'warmupLabels' => trans('tournaments.warmup'),
            'serverLabels' => trans('gameservers.match_page'),
            'serverLinkStatusLabels' => trans('gameservers.server_link_status'),
            'liveScoreLabels' => trans('gameservers.live_score'),
            'voiceLabels' => trans('voice.join'),
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
     * The manual "Go" trigger (Task 11): a helper/orga ends a match's
     * warmup and fires the beamer gong. Authorization lives inside
     * {@see GoLive} itself (via `Gate::forUser()`, mirroring
     * {@see SetManualJoinInfo}) since the
     * Action has more than one future caller (this control today, an
     * automatic "all rosters ready" trigger later) — so no
     * `$this->authorize()` call is needed here.
     */
    public function go(Request $request, GameMatch $match, GoLive $action): RedirectResponse
    {
        $user = $this->authUser($request);

        try {
            $action->handle($match, $user);
        } catch (TournamentException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        return back();
    }

    /**
     * The viewer's currently *active* match — the one the "join voice"
     * surface should point at — rather than simply the earliest one in the
     * (round-ascending) `$matches` collection: once round 1 completes, the
     * viewer's round-1 match is stale (often already Completed and cleaned
     * up), while their real next action is a later, still-live match.
     *
     * Prefers a match whose status is Ready/Reported/Disputed (i.e. still
     * "in play"); if the viewer has no such match right now (e.g. eliminated,
     * or waiting for an opponent to be decided), falls back to their most
     * recent match overall so the page still has something sensible to show.
     */
    /**
     * @param  Collection<int, GameMatch>  $matches
     */
    private function activeMatchFor(Collection $matches, TournamentEntry $myEntry): ?GameMatch
    {
        $mine = $matches->filter(
            fn (GameMatch $match): bool => $match->entry1_id === $myEntry->id || $match->entry2_id === $myEntry->id,
        );

        $active = $mine->first(
            fn (GameMatch $match): bool => in_array($match->status, [MatchStatus::Ready, MatchStatus::Reported, MatchStatus::Disputed], true),
        );

        return $active ?? $mine->last();
    }

    /**
     * The viewer's own entry for this tournament — directly owned, or via
     * team ownership — or null if they have none.
     */
    private function entryFor(Tournament $tournament, User $user): ?TournamentEntry
    {
        return TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->ownedBy($user)
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
        // Scoped to Pending specifically: a match can accumulate more than
        // one MatchReport over its lifetime (submit, dispute, re-report
        // after an orga override, etc.) — the latest row regardless of
        // status could be an already-Confirmed or already-Disputed report,
        // which must not be reachable for a second confirm/dispute.
        return MatchReport::query()
            ->where('match_id', $match->id)
            ->where('status', ReportStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    /**
     * The viewer's own join link for `$match`'s voice channel, on every
     * active provider that {@see ProvisionMatchVoiceJob} has provisioned a
     * channel for — empty when voice channels have not been (or are no
     * longer) provisioned for this match on any provider.
     *
     * `occupants` is the live headcount for that channel (issue #13), via
     * {@see VoiceOccupancy::forMatch()} — real numbers require the provider
     * sidecars to be reachable (mode A, deferred); until then this is
     * consistently 0, which the UI already renders sensibly (no
     * LiveIndicator, plain "0").
     *
     * @return array<int, array{provider: string, label: string, url: string, isDefault: bool, occupants: int}>
     */
    private function voiceLinksFor(GameMatch $match, TournamentEntry $myEntry): array
    {
        $voiceChannels = $match->voice_channels;

        if ($voiceChannels === null) {
            return [];
        }

        $isEntry1 = $match->entry1_id === $myEntry->id;
        $defaultProvider = VoiceJoinLink::defaultProviderFor($myEntry->team?->voice_provider);
        $occupancy = VoiceOccupancy::forMatch($match);

        $links = [];

        foreach ($voiceChannels as $providerValue => $providerChannels) {
            $provider = VoiceProvider::tryFrom((string) $providerValue);

            if ($provider === null) {
                continue;
            }

            $channelId = $isEntry1 ? ($providerChannels['entry1_channel_id'] ?? null) : ($providerChannels['entry2_channel_id'] ?? null);

            if ($channelId === null) {
                continue;
            }

            $links[] = [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'url' => VoiceJoinLink::for($provider, $myEntry->display_name),
                'isDefault' => $provider === $defaultProvider,
                'occupants' => $occupancy[$provider->value][$channelId] ?? 0,
            ];
        }

        return $links;
    }
}
