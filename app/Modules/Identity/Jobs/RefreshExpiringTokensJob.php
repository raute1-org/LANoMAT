<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Actions\RefreshLinkedAccountToken;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly sweep (see `routes/console.php`) that refreshes every linked
 * account whose OAuth token is about to expire, for providers that actually
 * have a token lifecycle (see {@see LinkedAccountProvider::hasTokenLifecycle()}
 * — currently only Twitch). Accounts with no `token_expires_at` (Steam and
 * any provider without a token lifecycle) are excluded by the `whereNotNull`
 * guard rather than relying solely on the provider filter.
 */
class RefreshExpiringTokensJob implements ShouldQueue
{
    use Queueable;

    public function handle(RefreshLinkedAccountToken $refresh): void
    {
        $lifecycleProviders = array_values(array_filter(
            LinkedAccountProvider::cases(),
            fn (LinkedAccountProvider $provider): bool => $provider->hasTokenLifecycle(),
        ));

        LinkedAccount::query()
            ->whereIn('provider', $lifecycleProviders)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addHour())
            ->each(fn (LinkedAccount $account) => $refresh->handle($account));
    }
}
