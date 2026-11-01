<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\DisputeMatchReport;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Actions\WithdrawMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Http\Requests\ConfirmReportRequest;
use App\Modules\Tournaments\Http\Requests\SubmitReportRequest;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\EventRegistrationRequirement;
use App\Modules\Tournaments\Support\MyTournamentState;
use App\Modules\WebVoice\NativeClient\Support\NativeMatches;
use App\Modules\WebVoice\NativeClient\Support\NativeTournaments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tournaments and matches for the native client (bearer API). Reads and writes go through the
 * participant web flow's own Actions, Policies and read helpers; this controller only resolves
 * "who is acting" and renders JSON. A tournament or match the viewer may not see is a 404, a
 * policy refusal a 403, a domain rule a 422 (`TournamentException`) and a lost optimistic lock
 * a 409 (`StaleMatchException`). There is no default-guard user on these routes, so every
 * authorization uses `Gate::forUser`.
 */
class TournamentApiController extends Controller
{
    public function tournaments(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        return response()->json(['tournaments' => NativeTournaments::forEvent($this->user($request), $event)]);
    }

    public function myMatches(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        return response()->json(['matches' => NativeMatches::forEvent($this->user($request), $event)]);
    }

    public function checkin(Request $request, Tournament $tournament, CheckInEntry $action): JsonResponse
    {
        $user = $this->user($request);
        $this->abortUnlessVisible($user, $tournament);

        $entry = MyTournamentState::entryFor($tournament, $user);
        abort_if($entry === null, 404);

        if (! EventRegistrationRequirement::satisfiedBy($user, $tournament)) {
            return $this->error('tournaments.errors.registration_required', 422);
        }

        abort_if(Gate::forUser($user)->denies('checkIn', $entry), 403);

        try {
            $action->handle($entry);
        } catch (TournamentException|StaleMatchException $e) {
            return $this->domainError($e);
        }

        return response()->json(['tournament' => NativeTournaments::dto($user, $tournament->refresh())]);
    }

    public function report(SubmitReportRequest $request, GameMatch $match, SubmitMatchReport $action): JsonResponse
    {
        $user = $this->user($request);
        $this->abortUnlessVisible($user, $match->tournament);
        abort_if(Gate::forUser($user)->denies('report', $match), 403);

        $entry = MyTournamentState::entryForMatch($match, $user);
        abort_if($entry === null, 404);

        $data = $request->validated();

        try {
            $action->handle($match, $entry, $data['score1'], $data['score2']);
        } catch (TournamentException|StaleMatchException $e) {
            return $this->domainError($e);
        }

        return $this->matchResponse($user, $match);
    }

    public function confirm(ConfirmReportRequest $request, GameMatch $match, ConfirmMatchReport $action): JsonResponse
    {
        $user = $this->user($request);
        $this->abortUnlessVisible($user, $match->tournament);

        $entry = MyTournamentState::entryForMatch($match, $user);
        abort_if($entry === null, 404);

        $report = MyTournamentState::pendingReportFor($match);

        if ($report === null) {
            return $this->reportAlreadyHandled($match);
        }

        abort_if(Gate::forUser($user)->denies('confirm', $report), 403);

        try {
            $action->handle($report, $entry, $request->validated()['lock_version']);
        } catch (TournamentException|StaleMatchException $e) {
            return $this->domainError($e);
        }

        return $this->matchResponse($user, $match);
    }

    public function dispute(Request $request, GameMatch $match, DisputeMatchReport $action): JsonResponse
    {
        $user = $this->user($request);
        $this->abortUnlessVisible($user, $match->tournament);

        $entry = MyTournamentState::entryForMatch($match, $user);
        abort_if($entry === null, 404);

        $report = MyTournamentState::pendingReportFor($match);

        if ($report === null) {
            return $this->reportAlreadyHandled($match);
        }

        abort_if(Gate::forUser($user)->denies('dispute', $report), 403);

        try {
            $action->handle($report, $entry);
        } catch (TournamentException|StaleMatchException $e) {
            return $this->domainError($e);
        }

        return $this->matchResponse($user, $match);
    }

    public function withdrawReport(ConfirmReportRequest $request, GameMatch $match, WithdrawMatchReport $action): JsonResponse
    {
        $user = $this->user($request);
        $this->abortUnlessVisible($user, $match->tournament);

        $entry = MyTournamentState::entryForMatch($match, $user);
        abort_if($entry === null, 404);

        $report = MyTournamentState::pendingReportFor($match);

        if ($report === null) {
            return $this->reportAlreadyHandled($match);
        }

        abort_if(Gate::forUser($user)->denies('withdrawReport', $report), 403);

        try {
            $action->handle($report, $entry, $request->validated()['lock_version']);
        } catch (TournamentException|StaleMatchException $e) {
            return $this->domainError($e);
        }

        return $this->matchResponse($user, $match);
    }

    private function matchResponse(User $user, GameMatch $match): JsonResponse
    {
        $match->refresh();
        $mine = MyTournamentState::entryForMatch($match, $user);
        abort_if($mine === null, 404);

        return response()->json(['match' => NativeMatches::dto($user, $match, $mine)]);
    }

    /** Same rule as the web page: only people who played in a match with a report hear "already handled"; the rest get a 404. */
    private function reportAlreadyHandled(GameMatch $match): JsonResponse
    {
        abort_unless($match->reports()->exists(), 404);

        $withdrawn = in_array($match->status, [MatchStatus::Ready, MatchStatus::Live], true)
            && $match->reports()->where('status', ReportStatus::Withdrawn->value)->exists();

        return $this->error($withdrawn ? 'tournaments.errors.report_withdrawn' : 'tournaments.errors.report_not_pending', 409);
    }

    /** A tournament the viewer may not see answers 404 on every route, like the web pages. */
    private function abortUnlessVisible(User $user, ?Tournament $tournament): void
    {
        abort_if($tournament === null, 404);

        $event = $tournament->event;

        abort_unless($event !== null && $event->isPubliclyVisible() && $tournament->isVisibleTo($user), 404);
    }

    private function domainError(TournamentException|StaleMatchException $e): JsonResponse
    {
        $params = $e instanceof TournamentException ? $e->translationParams : [];

        return $this->error($e->translationKey, $e instanceof StaleMatchException ? 409 : 422, $params);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function error(string $key, int $status, array $params = []): JsonResponse
    {
        return response()->json(['message' => trans($key, $params), 'error' => $key], $status);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
