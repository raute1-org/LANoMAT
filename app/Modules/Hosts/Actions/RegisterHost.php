<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Actions;

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Registers a new managed remote host (M7's registry, the persistence layer
 * this task delivers — SSH connectivity itself is Task 2's executor). The
 * private key is taken as a separate, explicit parameter rather than folded
 * into $data so it can never accidentally flow through mass assignment or
 * end up logged alongside the descriptive fields (e.g. a `Log::info($data)`
 * call elsewhere would still never see it).
 *
 * A full key-parse (phpseclib) is Task 2's concern; here we only guard
 * against an empty value or something that plainly isn't PEM-shaped, so the
 * registry never persists a private key that is obviously not one.
 */
class RegisterHost
{
    /**
     * @param  array<string, mixed>  $data  name, hostname, ssh_port, ssh_user, role, event_id
     */
    public function handle(array $data, string $privateKeyPem, User $actor): RemoteHost
    {
        if (! $actor->isOrga()) {
            throw new AuthorizationException;
        }

        if (! self::looksLikePem($privateKeyPem)) {
            throw ValidationException::withMessages([
                'ssh_private_key' => __('hosts.errors.invalid_private_key'),
            ]);
        }

        $host = new RemoteHost($data);
        $host->ssh_private_key = $privateKeyPem;
        $host->save();

        return $host;
    }

    private static function looksLikePem(string $pem): bool
    {
        $trimmed = trim($pem);

        return $trimmed !== '' && str_contains($trimmed, '-----BEGIN') && str_contains($trimmed, 'PRIVATE KEY-----');
    }
}
