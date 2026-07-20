<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;

/**
 * Upserts a {@see LinkedAccount} for the given user/provider from the
 * provider-agnostic {@see LinkedAccountData} a connector resolved. Tokens
 * are set via `forceFill()` — never mass-assigned — mirroring the model's
 * own fillable exclusion (see {@see LinkedAccount}'s docblock).
 */
class LinkAccount
{
    /**
     * @throws IdentityException when the resolved (provider, provider_user_id)
     *                           already belongs to a different user.
     */
    public function handle(User $user, LinkedAccountProvider $provider, LinkedAccountData $data): LinkedAccount
    {
        $existing = LinkedAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $data->provider_user_id)
            ->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw IdentityException::accountAlreadyLinked($provider);
        }

        /** @var LinkedAccount $account */
        $account = LinkedAccount::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            [
                'provider_user_id' => $data->provider_user_id,
                'nickname' => $data->nickname,
                'scopes' => $data->scopes,
                'meta' => array_merge($data->meta, ['needs_reauth' => false]),
            ],
        );

        $account->forceFill([
            'access_token' => $data->access_token,
            'refresh_token' => $data->refresh_token,
            'token_expires_at' => $data->token_expires_at,
        ])->save();

        return $account;
    }
}
