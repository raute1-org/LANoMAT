<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Actions;

use App\Models\User;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pins a host key that was established out of band (as opposed to
 * {@see ProbeHost}, which pins what it sees on first contact). An existing,
 * different pin is never overwritten: that is a key change and needs a
 * conscious reset of the host record.
 */
class PinHostKey
{
    public function handle(RemoteHost $host, string $fingerprint, User $actor): RemoteHost
    {
        Gate::forUser($actor)->authorize('update', $host);

        if (preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $fingerprint) !== 1) {
            throw ValidationException::withMessages(['host_fingerprint' => __('hosts.errors.invalid_fingerprint')]);
        }

        if ($host->host_fingerprint !== null && $host->host_fingerprint !== $fingerprint) {
            throw RemoteExecutionException::fingerprintMismatch();
        }

        $host->host_fingerprint = $fingerprint;
        $host->save();

        return $host;
    }
}
