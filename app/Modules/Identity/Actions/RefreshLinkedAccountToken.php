<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use App\Modules\Notifications\Notifications\LinkedAccountReauthRequired;

/**
 * Refreshes a single {@see LinkedAccount}'s OAuth token via its
 * {@see LinkedAccountConnectors} connector. On success the new tokens are
 * stored (via `forceFill()`, never mass-assigned) and any prior
 * `needs_reauth` flag is cleared. On failure the account is flagged
 * `needs_reauth` so the linking UI can prompt the user, and the owner is
 * notified in-app.
 */
class RefreshLinkedAccountToken
{
    public function __construct(private readonly LinkedAccountConnectors $connectors) {}

    public function handle(LinkedAccount $account): void
    {
        $provider = $account->provider;

        try {
            $data = $this->connectors->for($provider)->refresh($account);
        } catch (IdentityException) {
            $account->update(['meta' => array_merge($account->meta ?? [], ['needs_reauth' => true])]);

            $account->user()->firstOrFail()->notify(new LinkedAccountReauthRequired($provider));

            return;
        }

        $account->update(['meta' => array_merge($account->meta ?? [], ['needs_reauth' => false])]);

        $account->forceFill([
            'access_token' => $data->access_token,
            'refresh_token' => $data->refresh_token,
            'token_expires_at' => $data->token_expires_at,
        ])->save();
    }
}
