<?php

declare(strict_types=1);

namespace App\Modules\Friends\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Friends\Actions\BlockUser;
use App\Modules\Friends\Actions\CancelFriendRequest;
use App\Modules\Friends\Actions\RemoveFriend;
use App\Modules\Friends\Actions\RespondToFriendRequest;
use App\Modules\Friends\Actions\SendFriendRequest;
use App\Modules\Friends\Actions\UnblockUser;
use App\Modules\Friends\Exceptions\FriendshipException;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Support\FriendSuggestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FriendsController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * The friends management page: accepted friends, incoming/outgoing
     * pending requests, LAN-native suggestions, and blocked users. Every
     * entry is reduced to display-only fields — never email, discord_id,
     * or any other private column.
     */
    public function index(Request $request, FriendSuggestions $suggestions): Response
    {
        $user = $this->authUser($request);

        return Inertia::render('Friends/Index', [
            'friends' => $user->acceptedFriends()
                ->map(fn (User $friend): array => $this->userDto($friend))
                ->values()
                ->all(),
            'incoming' => $user->incomingRequests()
                ->map(fn (Friendship $friendship): array => [
                    'friendshipId' => $friendship->id,
                    'from' => $this->userDto($friendship->otherUser($user)),
                ])
                ->values()
                ->all(),
            'outgoing' => $user->outgoingRequests()
                ->map(fn (Friendship $friendship): array => [
                    'friendshipId' => $friendship->id,
                    'to' => $this->userDto($friendship->otherUser($user)),
                ])
                ->values()
                ->all(),
            'suggestions' => $suggestions->for($user)
                ->map(fn (array $entry): array => [
                    ...$this->userDto($entry['user']),
                    'shared' => $entry['shared'],
                    'reasons' => $entry['reasons'],
                ])
                ->values()
                ->all(),
            'blocked' => $user->blockedUsers()
                ->map(fn (User $blocked): array => $this->userDto($blocked))
                ->values()
                ->all(),
            'labels' => trans('friends.page'),
        ]);
    }

    public function request(Request $request, SendFriendRequest $action): RedirectResponse
    {
        $actor = $this->authUser($request);

        $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $addressee = User::query()->findOrFail($request->integer('user_id'));

        try {
            $action->handle($actor, $addressee);
        } catch (FriendshipException $e) {
            return $this->flashError($e);
        }

        return back();
    }

    public function respond(Request $request, Friendship $friendship, RespondToFriendRequest $action): RedirectResponse
    {
        $actor = $this->authUser($request);

        $data = $request->validate([
            'accept' => ['required', 'boolean'],
        ]);

        // The action authorizes internally via Gate::forUser($actor) against
        // FriendshipPolicy::respond — never trust the route-bound model
        // alone without that check.
        $action->handle($actor, $friendship, (bool) $data['accept']);

        return back();
    }

    public function cancel(Request $request, Friendship $friendship, CancelFriendRequest $action): RedirectResponse
    {
        $actor = $this->authUser($request);

        // The action authorizes internally via Gate::forUser($actor) against
        // FriendshipPolicy::cancel.
        $action->handle($actor, $friendship);

        return back();
    }

    public function remove(Request $request, User $user, RemoveFriend $action): RedirectResponse
    {
        $actor = $this->authUser($request);

        $action->handle($actor, $user);

        return back();
    }

    public function block(Request $request, User $user, BlockUser $action): RedirectResponse
    {
        $actor = $this->authUser($request);

        try {
            $action->handle($actor, $user);
        } catch (FriendshipException $e) {
            return $this->flashError($e);
        }

        return back();
    }

    public function unblock(Request $request, User $user, UnblockUser $action): RedirectResponse
    {
        $actor = $this->authUser($request);

        $action->handle($actor, $user);

        return back();
    }

    /**
     * @return array{id: int, name: string, avatarUrl: string|null}
     */
    private function userDto(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatarUrl' => $user->avatar_url,
        ];
    }

    private function flashError(FriendshipException $e): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

        return back();
    }
}
