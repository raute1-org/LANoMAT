<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Actions;

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * Registers a new managed remote host (M7's registry, the persistence layer
 * this task delivers — SSH connectivity itself is Task 2's executor). The
 * private key is taken as a separate, explicit parameter rather than folded
 * into $data so it can never accidentally flow through mass assignment or
 * end up logged alongside the descriptive fields (e.g. a `Log::info($data)`
 * call elsewhere would still never see it).
 *
 * Now that phpseclib is available (Task 2), the key is validated by actually
 * attempting to parse it via {@see PublicKeyLoader::loadPrivateKey()} rather
 * than a shallow "-----BEGIN ... PRIVATE KEY-----" marker check — a garbage
 * PEM-shaped string is rejected, not persisted.
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

        if (! self::isParseablePrivateKey($privateKeyPem)) {
            throw ValidationException::withMessages([
                'ssh_private_key' => __('hosts.errors.invalid_private_key'),
            ]);
        }

        $host = new RemoteHost($data);
        $host->ssh_private_key = $privateKeyPem;
        $host->save();

        return $host;
    }

    private static function isParseablePrivateKey(string $pem): bool
    {
        if (trim($pem) === '') {
            return false;
        }

        try {
            PublicKeyLoader::loadPrivateKey($pem);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
