<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived, single-use, user-bound tokens that let the native client hand a browser the
 * start of the normal "link a third-party account" flow (Steam OpenID, Twitch OAuth) without
 * reimplementing OAuth. Only a hash of the token is stored (cache), bound to the issuing user
 * and the provider; consuming it as any other user is refused and does not burn the token.
 */
final class NativeLinkTokens
{
    public const int TTL_MINUTES = 10;

    private const string PREFIX = 'native-link:';

    /**
     * @return array{url: string, expires_at: CarbonInterface}
     */
    public function issue(User $user, LinkedAccountProvider $provider): array
    {
        $token = Str::random(48);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put(self::PREFIX.hash('sha256', $token), [
            'user_id' => $user->id,
            'provider' => $provider->value,
        ], $expiresAt);

        return ['url' => route('connections.native-link', ['token' => $token]), 'expires_at' => $expiresAt];
    }

    /**
     * Consumes the token for `$user`.
     *
     * @return LinkedAccountProvider|false|null provider on success, false when bound to another user (token stays valid), null when unknown, expired or already used
     */
    public function consume(string $token, User $user): LinkedAccountProvider|false|null
    {
        $key = self::PREFIX.hash('sha256', $token);

        return Cache::lock($key.':lock', 5)->block(5, function () use ($key, $user): LinkedAccountProvider|false|null {
            $entry = Cache::get($key);

            if (! is_array($entry) || ! is_int($entry['user_id'] ?? null) || ! is_string($entry['provider'] ?? null)) {
                return null;
            }

            if ($entry['user_id'] !== $user->id) {
                return false;
            }

            Cache::forget($key);

            return LinkedAccountProvider::tryFrom($entry['provider']);
        });
    }
}
