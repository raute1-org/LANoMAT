<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Models\User;
use App\Modules\Identity\Actions\UpdateProfile;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Own profile for the native client (bearer API v1): what the browser's settings page edits.
 * The owner sees their email (the browser shows it to them too); role and discord_id are never
 * part of the payload, and the avatar is the Discord one (read-only, the browser has no upload).
 */
class NativeProfileController
{
    use ResolvesAuthenticatedUser;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['profile' => $this->payload($this->authUser($request))]);
    }

    public function update(NativeProfileUpdateRequest $request, UpdateProfile $action): JsonResponse
    {
        $user = $action->handle($this->authUser($request), $request->validated());

        return response()->json(['profile' => $this->payload($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        $steam = $user->linkedAccount(LinkedAccountProvider::Steam);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'avatar_url' => $user->nativeAvatarSrc(),
            'bio' => $user->bio,
            'steam_url' => $user->steam_url,
            'stream_url' => $user->stream_url,
            'profile_color' => $user->profile_color,
            'share_location' => $user->share_location,
            'has_verified_steam_link' => $steam !== null,
            'verified_steam_nickname' => $steam?->nickname,
            'editable' => ['name', 'email', 'bio', 'steam_url', 'stream_url', 'profile_color', 'share_location'],
        ];
    }
}
