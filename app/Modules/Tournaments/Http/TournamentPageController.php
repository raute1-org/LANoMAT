<?php

namespace App\Modules\Tournaments\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\Identity\Support\GameOwnershipHint;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\CorrectMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\EnrollTeam;
use App\Modules\Tournaments\Actions\GoLive;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Actions\WithdrawEntry;
use App\Modules\Tournaments\Actions\WithdrawMatchReport;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Http\Requests\ConfirmReportRequest;
use App\Modules\Tournaments\Http\Requests\CorrectReportRequest;
use App\Modules\Tournaments\Http\Requests\SubmitReportRequest;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use App\Modules\Tournaments\Support\EventRegistrationRequirement;
use App\Modules\Tournaments\Support\MyTournamentState;
use App\Modules\Tournaments\Support\PendingReportView;
use App\Modules\Tournaments\Support\StandingsView;
use App\Modules\Tournaments\Support\TeamPicker;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use App\Modules\Voice\Support\VoiceJoinLink;
use App\Modules\Voice\Support\VoiceOccupancy;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
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

        $user = $request->user();

        $tournamentModels = Tournament::query()
            ->where('event_id', $event->id)
            ->visibleTo($user)
            ->orderBy('starts_at')
            ->with('game')
            ->withCount(['entries as active_entries_count' => fn ($query) => $query->where('status', '!=', EntryStatus::Withdrawn->value)])
            ->get()
            // Live and open tournaments first, finished ones last; starts_at
            // (already the query order) breaks ties because the sort is stable.
            ->sortBy(fn (Tournament $tournament): int => match ($tournament->status) {
                TournamentStatus::Live => 0,
                TournamentStatus::CheckIn => 1,
                TournamentStatus::Enrollment => 2,
                TournamentStatus::Announced => 3,
                TournamentStatus::Draft => 4,
                TournamentStatus::Finished => 5,
            })
            ->values();

        // The viewer's own active entries (solo, or as team owner), keyed by tournament.
        $myEntries = $user === null ? collect() : TournamentEntry::query()
            ->whereIn('tournament_id', $tournamentModels->modelKeys())
            ->where('status', '!=', EntryStatus::Withdrawn->value)
            ->ownedBy($user)
            ->with('tournament')
            ->latest('id')
            ->get()
            ->unique('tournament_id')
            ->keyBy('tournament_id');

        // Plain team members own nothing, but still see that their team is
        // entered (display only; no check-in/withdraw for them).
        $rosteredEntries = $user === null ? collect() : TournamentEntry::query()
            ->whereIn('tournament_id', $tournamentModels->modelKeys())
            ->where('status', '!=', EntryStatus::Withdrawn->value)
            ->rosteredBy($user)
            ->with('team')
            ->latest('id')
            ->get()
            ->unique('tournament_id')
            ->keyBy('tournament_id');

        $ownedTeams = TeamPicker::ownedTeams($user);

        $tournaments = $tournamentModels
            ->map(fn (Tournament $tournament): array => [
                'teamPicker' => TeamPicker::for($tournament, $ownedTeams),
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'startsAt' => $tournament->starts_at->toIso8601String(),
                // Entries are people for solo and teams for team tournaments;
                // withdrawn entries never count (same rule as the capacity check).
                'entryCount' => (int) $tournament->getAttribute('active_entries_count'),
                'maxEntries' => $tournament->max_entries,
                'teamSize' => $tournament->team_size,
                'myEntry' => MyTournamentState::entryState($user, $myEntries->get($tournament->id) ?? $rosteredEntries->get($tournament->id)),
                'gameName' => $tournament->game?->name,
                // ADVISORY only — see GameOwnershipHint's docblock and
                // OwnershipHintNeverBlocksTest: this never gates enrollment,
                // it only lets the UI render a calm warning. Only computed
                // for a signed-in viewer with a game actually attached.
                'ownershipHint' => ($user !== null && $tournament->game !== null)
                    ? GameOwnershipHint::for($user, $tournament->game)->value
                    : null,
            ])
            ->all();

        return Inertia::render('Tournaments/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'tournaments' => $tournaments,
            'registration' => EventRegistrationRequirement::viewState($user, $event),
            'labels' => [...trans('tournaments.page'), 'title' => trans('tournaments.page.index_title')],
            'statusLabels' => trans('tournaments.status'),
            'formatLabels' => trans('tournaments.format'),
            'ownershipHintLabels' => trans('tournaments.ownership_hint'),
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
        abort_unless($tournament->isVisibleTo($request->user()), 404);

        $matches = BracketMatchProjection::matchesForTournament($tournament->id);

        $user = $request->user();
        $myEntry = $user === null ? null : MyTournamentState::entryFor($tournament, $user);

        $myMatch = $myEntry === null ? null : $this->activeMatchFor($matches, $myEntry);

        return Inertia::render('Tournaments/Show', [
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'format' => $tournament->format->value,
                'status' => $tournament->status->value,
                'event' => ['name' => $event->name, 'slug' => $event->slug],
                'winnerEntryId' => $tournament->winner_entry_id,
                'winnerName' => $tournament->winnerEntry?->display_name,
                // Info block of the announced state (and the header of every later one).
                'startsAt' => $tournament->starts_at->toIso8601String(),
                'maxEntries' => $tournament->max_entries,
                'teamSize' => $tournament->team_size,
                'gameName' => $tournament->game?->name,
                'rules' => $tournament->rules,
            ],
            'standings' => StandingsView::forTournament($tournament),
            'standingsLabels' => trans('tournaments.standings'),
            'matches' => $matches->map(fn (GameMatch $match): array => BracketMatchProjection::fromMatch($match))->all(),
            'teamPicker' => TeamPicker::for($tournament, TeamPicker::ownedTeams($user)),
            'myEntryId' => $myEntry?->id,
            // The viewer's own pending result claims, "own : opponent" (F-S4-2).
            'pendingReports' => (object) PendingReportView::forViewer($matches, $myEntry),
            'myEntry' => MyTournamentState::entryState($user, $myEntry ?? ($user === null ? null : MyTournamentState::rosteredEntryFor($tournament, $user))),
            'registration' => EventRegistrationRequirement::viewState($user, $event),
            'myMatchVoiceLinks' => $myMatch === null ? [] : $this->voiceLinksFor($myMatch, $myEntry, $user->name),
            // Gates the match card's manual "Go" control (Task 11) —
            // mirrors TournamentPolicy::goLive (helper-or-above) so the
            // button is only rendered for someone who could actually submit
            // the action; the server-side Gate check in GoLive remains the
            // real authorization boundary regardless of this flag.
            'canGoLive' => $user !== null && $user->isHelper(),
            'labels' => [...trans('tournaments.page'), 'title' => trans('tournaments.page.show_title'), 'format' => trans('tournaments.fields.format')],
            'formatLabels' => trans('tournaments.format'),
            'statusLabels' => trans('tournaments.status'),
            'matchStatusLabels' => trans('tournaments.match_status'),
            // `BracketMatchCard`'s bye/phantom placeholder labels (Task 5) —
            // distinct from `labels` (threaded to `BracketView` as its own
            // `bracket-labels` prop for round/bracket-name headings) to
            // avoid two differently-shaped things sharing one name. Named
            // to match `OverlayController::bracket()`'s identical concept.
            'bracketStateLabels' => trans('tournaments.bracket'),
            'reportLabels' => trans('tournaments.report'),
            'warmupLabels' => trans('tournaments.warmup'),
            'serverLabels' => trans('gameservers.match_page'),
            'serverLinkStatusLabels' => trans('gameservers.server_link_status'),
            'liveScoreLabels' => trans('gameservers.live_score'),
            'voiceLabels' => app(Modules::class)->enabled(Module::Voice) ? trans('voice.join') : [],
        ]);
    }

    public function enroll(Request $request, Tournament $tournament, EnrollSolo $enrollSolo, EnrollTeam $enrollTeam): RedirectResponse
    {
        $this->abortUnlessVisible($request, $tournament);

        $user = $this->authUser($request);

        if ($tournament->status === TournamentStatus::Enrollment && ! EventRegistrationRequirement::satisfiedBy($user, $tournament)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans('tournaments.errors.registration_required')]);

            return back();
        }

        $this->authorize('enroll', $tournament);

        try {
            if ($tournament->team_size > 1) {
                $team = Team::query()->find($request->integer('team_id'));

                if ($team === null) {
                    Inertia::flash('toast', ['type' => 'error', 'message' => trans('tournaments.errors.team_required')]);

                    return back();
                }

                $this->authorize('update', $team);
                $enrollTeam->handle($tournament, $team);
            } else {
                $enrollSolo->handle($tournament, $user);
            }
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.enrollment.enrolled')]);

        return back();
    }

    public function checkin(Request $request, Tournament $tournament, CheckInEntry $action): RedirectResponse
    {
        $this->abortUnlessVisible($request, $tournament);

        $user = $this->authUser($request);
        $entry = MyTournamentState::entryFor($tournament, $user);

        abort_if($entry === null, 404);

        if (! EventRegistrationRequirement::satisfiedBy($user, $tournament)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans('tournaments.errors.registration_required')]);

            return back();
        }

        $this->authorize('checkIn', $entry);

        try {
            $action->handle($entry);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.checkin.checked_in')]);

        return back();
    }

    public function withdraw(Request $request, Tournament $tournament, WithdrawEntry $action): RedirectResponse
    {
        $this->abortUnlessVisible($request, $tournament);

        $entry = MyTournamentState::entryFor($tournament, $this->authUser($request));

        abort_if($entry === null, 404);

        $this->authorize('withdraw', $entry);

        try {
            $action->handle($entry);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.enrollment.withdrawn')]);

        return back();
    }

    public function report(SubmitReportRequest $request, GameMatch $match, SubmitMatchReport $action): RedirectResponse
    {
        $this->authorize('report', $match);

        $user = $this->authUser($request);
        $entry = MyTournamentState::entryForMatch($match, $user);

        abort_if($entry === null, 404);

        $data = $request->validated();

        try {
            $action->handle($match, $entry, $data['score1'], $data['score2']);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.submitted')]);

        return back();
    }

    public function confirm(ConfirmReportRequest $request, GameMatch $match, ConfirmMatchReport $action): RedirectResponse
    {
        $report = MyTournamentState::pendingReportFor($match);

        if ($report === null) {
            return $this->reportAlreadyHandled($request, $match);
        }

        $this->authorize('confirm', $report);

        $user = $this->authUser($request);
        $entry = MyTournamentState::entryForMatch($match, $user);

        abort_if($entry === null, 404);

        $data = $request->validated();

        try {
            $action->handle($report, $entry, $data['lock_version']);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.confirmed')]);

        return back();
    }

    public function dispute(Request $request, GameMatch $match, DisputeMatchReport $action): RedirectResponse
    {
        $report = MyTournamentState::pendingReportFor($match);
        if ($report === null) {
            return $this->reportAlreadyHandled($request, $match);
        }

        $this->authorize('dispute', $report);

        $user = $this->authUser($request);
        $entry = MyTournamentState::entryForMatch($match, $user);

        abort_if($entry === null, 404);

        try {
            $action->handle($report, $entry);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.disputed')]);

        return back();
    }

    public function withdrawReport(ConfirmReportRequest $request, GameMatch $match, WithdrawMatchReport $action): RedirectResponse
    {
        $report = MyTournamentState::pendingReportFor($match);

        if ($report === null) {
            return $this->reportAlreadyHandled($request, $match);
        }

        $this->authorize('withdrawReport', $report);

        $entry = MyTournamentState::entryForMatch($match, $this->authUser($request));

        abort_if($entry === null, 404);

        try {
            $action->handle($report, $entry, $request->validated()['lock_version']);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.withdrawn')]);

        return back();
    }

    public function correctReport(CorrectReportRequest $request, GameMatch $match, CorrectMatchReport $action): RedirectResponse
    {
        $report = MyTournamentState::pendingReportFor($match);

        if ($report === null) {
            return $this->reportAlreadyHandled($request, $match);
        }

        $this->authorize('withdrawReport', $report);

        $entry = MyTournamentState::entryForMatch($match, $this->authUser($request));

        abort_if($entry === null, 404);

        $data = $request->validated();

        try {
            $action->handle($report, $entry, $data['score1'], $data['score2'], $data['lock_version']);
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.report.corrected')]);

        return back();
    }

    /**
     * A stale second click (the report was already confirmed/disputed): a
     * participant gets a friendly flash instead of a raw 404 page. A match
     * that never had a report, or a non-participant, stays a 404.
     */
    /**
     * Same visibility rule as the page: a tournament the user may not see
     * answers 404 on every write route too, never 403 (which would confirm a
     * hidden draft exists).
     */
    private function abortUnlessVisible(Request $request, Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo($request->user()), 404);
    }

    private function reportAlreadyHandled(Request $request, GameMatch $match): RedirectResponse
    {
        $user = $this->authUser($request);

        abort_unless(
            MyTournamentState::entryForMatch($match, $user) !== null && $match->reports()->exists(),
            404,
        );

        $withdrawn = ($match->status === MatchStatus::Ready || $match->status === MatchStatus::Live)
            && $match->reports()->where('status', ReportStatus::Withdrawn->value)->exists();

        Inertia::flash('toast', ['type' => 'info', 'message' => trans($withdrawn ? 'tournaments.errors.report_withdrawn' : 'tournaments.errors.report_not_pending')]);

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
        } catch (TournamentException|StaleMatchException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('tournaments.warmup.went_live')]);

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
            fn (GameMatch $match): bool => in_array($match->status, [MatchStatus::Ready, MatchStatus::Live, MatchStatus::Reported, MatchStatus::Disputed], true),
        );

        return $active ?? $mine->last();
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
     * The link carries the viewer's own name, so the voice client joins with it.
     *
     * @return array<int, array{provider: string, label: string, url: string, isDefault: bool, occupants: int}>
     */
    private function voiceLinksFor(GameMatch $match, TournamentEntry $myEntry, ?string $username = null): array
    {
        if (! app(Modules::class)->enabled(Module::Voice)) {
            return [];
        }

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
                'url' => VoiceJoinLink::for($provider, $myEntry->display_name, $username),
                'isDefault' => $provider === $defaultProvider,
                'occupants' => $occupancy[$provider->value][$channelId] ?? 0,
            ];
        }

        return $links;
    }
}
