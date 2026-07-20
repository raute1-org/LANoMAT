<?php

namespace App\Modules\Identity\Http;

use App\Models\User;
use App\Modules\Friends\Support\FriendService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController
{
    public function __construct(private readonly FriendService $friends) {}

    /**
     * Show a user's public profile. Exposes only public-safe fields —
     * never email, discord_id, or role.
     */
    public function show(Request $request, User $user): Response
    {
        return Inertia::render('Profile/Show', [
            'profile' => [
                'name' => $user->name,
                'avatarUrl' => $user->avatar_url,
                'bio' => $user->bio,
                'steamUrl' => $user->steam_url,
                'profileColor' => $user->profile_color,
            ],
            'userId' => $user->id,
            'relationship' => $this->relationship($request, $user),
            'labels' => trans('profile.public'),
            'relationshipLabels' => trans('profile.relationship'),
        ]);
    }

    /**
     * The authenticated viewer's relationship to the profile's owner, for
     * rendering the matching friend/block controls. Null for guests — no
     * controls are shown to unauthenticated visitors.
     *
     * A block by the viewer always wins over any (now-torn-down) friendship.
     * If the viewer is blocked BY the target instead, we deliberately report
     * `none` rather than `blocked` — revealing that would be an oracle for
     * an action the target took against the viewer, and BlockUser has
     * already deleted the underlying friendship anyway.
     *
     * @return array{state: string, friendshipId?: int}|null
     */
    private function relationship(Request $request, User $target): ?array
    {
        $viewer = $request->user();

        if (! $viewer instanceof User) {
            return null;
        }

        if ($viewer->id === $target->id) {
            return ['state' => 'self'];
        }

        if ($viewer->hasBlocked($target)) {
            return ['state' => 'blocked'];
        }

        if ($this->friends->areFriends($viewer, $target)) {
            return ['state' => 'friends'];
        }

        $pending = $this->friends->pendingBetween($viewer, $target);

        if ($pending !== null) {
            return $pending->requester_id === $viewer->id
                ? ['state' => 'request_sent']
                : ['state' => 'request_received', 'friendshipId' => $pending->id];
        }

        return ['state' => 'none'];
    }
}
