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
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Exceptions\FriendshipException;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Support\FriendSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Friends for the native client (bearer API v1). Reads are the browser's /friends page (display
 * fields only: id, name, avatar, never email/discord_id; the web page shows no presence, so
 * neither does this), every write goes through the same Actions and FriendshipPolicy.
 *
 * Privacy rule kept from the browser: nobody can learn that someone blocked them. A request to
 * a user who blocked the caller answers exactly like a normal pending request and is dropped.
 */
class NativeFriendsController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        return response()->json([
            'friends' => $user->acceptedFriends()->map(fn (User $f): array => $this->userDto($f))->values()->all(),
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        return response()->json([
            'incoming' => $user->incomingRequests()->map(fn (Friendship $f): array => [
                'id' => $f->id,
                'user' => $this->userDto($f->otherUser($user)),
            ])->values()->all(),
            'outgoing' => $user->outgoingRequests()->map(fn (Friendship $f): array => [
                'id' => $f->id,
                'user' => $this->userDto($f->otherUser($user)),
            ])->values()->all(),
        ]);
    }

    public function suggestions(Request $request, FriendSuggestions $suggestions): JsonResponse
    {
        return response()->json([
            'suggestions' => $suggestions->for($this->authUser($request))->map(fn (array $entry): array => [
                ...$this->userDto($entry['user']),
                'shared' => $entry['shared'],
                'reasons' => $entry['reasons'],
                'reason_labels' => array_map(fn (string $r): string => (string) trans('friends.page.reason_'.$r), $entry['reasons']),
            ])->values()->all(),
        ]);
    }

    public function blocked(Request $request): JsonResponse
    {
        return response()->json([
            'blocked' => $this->authUser($request)->blockedUsers()->map(fn (User $u): array => $this->userDto($u))->values()->all(),
        ]);
    }

    public function sendRequest(Request $request, SendFriendRequest $action): JsonResponse
    {
        $actor = $this->authUser($request);

        $request->validate(['user_id' => ['required', 'integer', Rule::exists('users', 'id')]]);

        $addressee = User::query()->findOrFail($request->integer('user_id'));

        // The addressee blocked the caller: look exactly like a pending request, create nothing.
        if ($addressee->id !== $actor->id && $addressee->hasBlocked($actor) && ! $actor->hasBlocked($addressee)) {
            return response()->json(['status' => FriendshipStatus::Pending->value], 201);
        }

        try {
            $friendship = $action->handle($actor, $addressee);
        } catch (FriendshipException $e) {
            return $this->domainError($e);
        }

        // No friendship id here on purpose (the dropped case above has none): clients re-read the lists.
        return response()->json(['status' => $friendship->status->value], 201);
    }

    public function accept(Request $request, Friendship $friendship, RespondToFriendRequest $action): JsonResponse
    {
        return $this->respond($request, $friendship, $action, true);
    }

    public function decline(Request $request, Friendship $friendship, RespondToFriendRequest $action): JsonResponse
    {
        return $this->respond($request, $friendship, $action, false);
    }

    public function cancel(Request $request, Friendship $friendship, CancelFriendRequest $action): Response
    {
        $actor = $this->authUser($request);
        $this->requireParticipant($actor, $friendship);

        $action->handle($actor, $friendship);

        return response()->noContent();
    }

    public function remove(Request $request, User $user, RemoveFriend $action): Response
    {
        $action->handle($this->authUser($request), $user);

        return response()->noContent();
    }

    public function block(Request $request, User $user, BlockUser $action): JsonResponse
    {
        try {
            $action->handle($this->authUser($request), $user);
        } catch (FriendshipException $e) {
            return $this->domainError($e);
        }

        return response()->json(['blocked' => true]);
    }

    public function unblock(Request $request, User $user, UnblockUser $action): Response
    {
        $action->handle($this->authUser($request), $user);

        return response()->noContent();
    }

    private function respond(Request $request, Friendship $friendship, RespondToFriendRequest $action, bool $accept): JsonResponse
    {
        $actor = $this->authUser($request);
        $this->requireParticipant($actor, $friendship);

        // Authorizes via Gate::forUser($actor) against FriendshipPolicy::respond (addressee, pending).
        $action->handle($actor, $friendship, $accept);

        return response()->json(['status' => $accept ? FriendshipStatus::Accepted->value : 'declined']);
    }

    /** A request of two other people does not exist for the caller (404, not 403). */
    private function requireParticipant(User $actor, Friendship $friendship): void
    {
        abort_if(Gate::forUser($actor)->denies('view', $friendship), 404);
    }

    private function domainError(FriendshipException $e): JsonResponse
    {
        return response()->json([
            'code' => str($e->translationKey)->afterLast('.')->toString(),
            'message' => trans($e->translationKey),
        ], 422);
    }

    /**
     * @return array{id: int, name: string, avatar_url: string|null}
     */
    private function userDto(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'avatar_url' => $user->nativeAvatarSrc()];
    }
}
