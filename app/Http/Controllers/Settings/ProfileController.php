<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Modules\Identity\Actions\UpdateProfile;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $this->authUser($request);

        // The free-text steam_url predates the OAuth-verified Steam link
        // (Identity module, M9). Once a verified link exists it is
        // authoritative; steam_url stays editable as a fallback for players
        // who haven't linked yet. See UpdateProfile — steam_url itself is
        // untouched by linking, this is display-only reconciliation.
        $steamAccount = $user->linkedAccount(LinkedAccountProvider::Steam);

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'profile' => [
                'bio' => $user->bio,
                'steamUrl' => $user->steam_url,
                'streamUrl' => $user->stream_url,
                'profileColor' => $user->profile_color,
                'hasVerifiedSteamLink' => $steamAccount !== null,
                'verifiedSteamNickname' => $steamAccount?->nickname,
            ],
            'labels' => trans('profile.form'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateProfile $action): RedirectResponse
    {
        $action->handle($this->authUser($request), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $this->authUser($request);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
