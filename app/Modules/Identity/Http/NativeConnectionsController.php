<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Models\User;
use App\Modules\Identity\Actions\UnlinkAccount;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use App\Modules\Identity\Support\NativeLinkTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Linked accounts for the native client (bearer API v1). Same data as the browser's connections
 * page, never any token. Linking needs a browser round trip: `link` only hands out a single-use
 * URL that starts the normal logged-in flow; the client then polls `index` for completion.
 */
class NativeConnectionsController
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request, LinkedAccountConnectors $connectors): JsonResponse
    {
        $user = $this->authUser($request);

        return response()->json([
            'connections' => [
                $this->discord($user),
                ...array_map(fn (LinkedAccountProvider $p): array => $this->payload($user, $p, $connectors), LinkedAccountProvider::linkable()),
            ],
        ]);
    }

    public function link(Request $request, string $provider, LinkedAccountConnectors $connectors, NativeLinkTokens $tokens): JsonResponse
    {
        $provider = $this->resolveProvider($provider);

        // Unconfigured providers 404, like the browser's redirect route.
        abort_unless($connectors->isConfigured($provider), 404);

        $issued = $tokens->issue($this->authUser($request), $provider);

        return response()->json([
            'provider' => $provider->value,
            'url' => $issued['url'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, string $provider, UnlinkAccount $unlink, LinkedAccountConnectors $connectors): JsonResponse
    {
        $provider = $this->resolveProvider($provider);
        $user = $this->authUser($request);
        $account = $user->linkedAccount($provider);

        if ($account !== null) {
            abort_if(Gate::forUser($user)->denies('delete', $account), 403);
        }

        $unlink->handle($user, $provider);
        $user->unsetRelation('linkedAccounts');

        return response()->json(['connection' => $this->payload($user, $provider, $connectors)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, LinkedAccountProvider $provider, LinkedAccountConnectors $connectors): array
    {
        $account = $user->linkedAccount($provider);
        $available = $connectors->isConfigured($provider);
        $status = match (true) {
            $account === null => 'not_connected',
            $account->needsReauth() => 'needs_reauth',
            default => 'connected',
        };

        return [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'status' => $status,
            'external_name' => $account?->nickname,
            'available' => $available,
            'can_link' => $available && $status !== 'connected',
            'can_unlink' => $account !== null && Gate::forUser($user)->allows('delete', $account),
            'read_only' => false,
        ];
    }

    /**
     * Discord is the login identity: shown, never linkable or removable.
     *
     * @return array<string, mixed>
     */
    private function discord(User $user): array
    {
        return [
            'provider' => 'discord',
            'label' => 'Discord',
            'status' => 'connected',
            'external_name' => $user->name,
            'available' => true,
            'can_link' => false,
            'can_unlink' => false,
            'read_only' => true,
        ];
    }

    private function resolveProvider(string $provider): LinkedAccountProvider
    {
        $resolved = LinkedAccountProvider::tryFrom($provider);

        abort_unless($resolved !== null && in_array($resolved, LinkedAccountProvider::linkable(), true), 404);

        return $resolved;
    }
}
