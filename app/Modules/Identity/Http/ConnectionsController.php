<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Identity\Actions\LinkAccount;
use App\Modules\Identity\Actions\UnlinkAccount;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Policies\LinkedAccountPolicy;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Link/unlink flow for third-party identity providers (Steam, Twitch, ...).
 * `{provider}` is validated against {@see LinkedAccountProvider::linkable()}
 * (404 otherwise) rather than bound as a model — there is no Eloquent model
 * behind the enum. The linked user is always `$request->user()`, never a
 * client-supplied id; unlinking additionally goes through
 * {@see LinkedAccountPolicy}.
 */
class ConnectionsController
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function redirect(Request $request, string $provider, LinkedAccountConnectors $connectors): RedirectResponse
    {
        $provider = $this->resolveProvider($provider);

        return redirect($connectors->for($provider)->redirectUrl());
    }

    public function callback(Request $request, string $provider, LinkedAccountConnectors $connectors, LinkAccount $linkAccount): RedirectResponse
    {
        $provider = $this->resolveProvider($provider);

        $data = $connectors->for($provider)->resolveCallback();

        try {
            $linkAccount->handle($this->authUser($request), $provider, $data);
        } catch (IdentityException $e) {
            throw ValidationException::withMessages(['provider' => trans($e->translationKey)]);
        }

        return redirect()->route('connections.edit');
    }

    public function destroy(Request $request, string $provider, UnlinkAccount $unlinkAccount): RedirectResponse
    {
        $provider = $this->resolveProvider($provider);

        $user = $this->authUser($request);
        $account = $user->linkedAccount($provider);

        if ($account !== null) {
            $this->authorize('delete', $account);
        }

        $unlinkAccount->handle($user, $provider);

        return back();
    }

    private function resolveProvider(string $provider): LinkedAccountProvider
    {
        $resolved = LinkedAccountProvider::tryFrom($provider);

        abort_unless($resolved !== null && in_array($resolved, LinkedAccountProvider::linkable(), true), 404);

        return $resolved;
    }
}
