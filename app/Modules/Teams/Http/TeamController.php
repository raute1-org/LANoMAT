<?php

namespace App\Modules\Teams\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Actions\LeaveTeam;
use App\Modules\Teams\Actions\RequestToJoin;
use App\Modules\Teams\Actions\RespondToJoinRequest;
use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Http\Requests\StoreTeamRequest;
use App\Modules\Teams\Http\Requests\UpdateTeamRequest;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use App\Modules\Teams\Models\TeamMember;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function index(): Response
    {
        $teams = Team::query()
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team) => $this->summary($team))
            ->all();

        return Inertia::render('Teams/Index', [
            'teams' => $teams,
            'labels' => trans('teams.page'),
        ]);
    }

    public function show(Request $request, Team $team): Response
    {
        $team->loadCount('members')->load(['owner', 'members.user']);

        $user = $request->user();
        $isMember = $user !== null && $team->members()->where('user_id', $user->id)->exists();

        return Inertia::render('Teams/Show', [
            'team' => $this->detail($team),
            'isMember' => $isMember,
            'labels' => trans('teams.page'),
        ]);
    }

    public function store(StoreTeamRequest $request, CreateTeam $action): RedirectResponse
    {
        $action->handle($this->authUser($request), $request->validated()['name'], $request->validated()['tag']);

        return back();
    }

    public function edit(Request $request, Team $team): Response
    {
        $this->authorize('update', $team);

        $team->loadCount('members')->load(['owner', 'members.user', 'joinRequests' => function ($query) {
            $query->where('status', JoinRequestStatus::Pending->value)->with('user');
        }]);

        return Inertia::render('Teams/Edit', [
            'team' => [
                ...$this->detail($team),
                'joinRequests' => $team->joinRequests->map(function (TeamJoinRequest $joinRequest) {
                    $user = $joinRequest->user;
                    abort_if($user === null, 500, 'Join request has no associated user.');

                    return [
                        'id' => $joinRequest->id,
                        'message' => $joinRequest->message,
                        'user' => ['id' => $user->id, 'name' => $user->name],
                    ];
                })->all(),
            ],
            'labels' => trans('teams.page'),
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $data = $request->validated();

        $team->name = $data['name'];
        $team->tag = $data['tag'];

        $logo = $request->file('logo');
        if ($logo !== null) {
            $oldLogoPath = $team->logo_path;

            $path = $logo->store('team-logos', 'public');
            abort_if($path === false, 500, 'Failed to store the uploaded logo.');
            $team->logo_path = $path;

            if ($oldLogoPath !== null) {
                Storage::disk('public')->delete($oldLogoPath);
            }
        }

        $team->save();

        return back();
    }

    public function join(Request $request, Team $team, RequestToJoin $action): RedirectResponse
    {
        try {
            $action->handle($this->authUser($request), $team, $request->string('message')->value() ?: null);
        } catch (TeamException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        return back();
    }

    public function respond(Request $request, Team $team, TeamJoinRequest $teamRequest, RespondToJoinRequest $action): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        abort_unless($teamRequest->team_id === $team->id, 404);

        $action->handle($teamRequest, (bool) $request->boolean('accept'));

        return back();
    }

    public function leave(Request $request, Team $team, LeaveTeam $action): RedirectResponse
    {
        try {
            $action->handle($this->authUser($request), $team);
        } catch (TeamException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'tag' => $team->tag,
            'logoUrl' => $team->logo_path !== null ? asset('storage/'.$team->logo_path) : null,
            'memberCount' => $team->members_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Team $team): array
    {
        $owner = $team->owner;
        abort_if($owner === null, 500, 'Team has no owner.');

        return [
            ...$this->summary($team),
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
            'members' => $team->members->map(function (TeamMember $member) {
                $user = $member->user;
                abort_if($user === null, 500, 'Team member has no associated user.');

                return [
                    'id' => $member->id,
                    'role' => $member->role->value,
                    'user' => ['id' => $user->id, 'name' => $user->name],
                ];
            })->all(),
        ];
    }
}
