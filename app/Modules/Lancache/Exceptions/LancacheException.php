<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Exceptions;

use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Lancache\Actions\ApplyLancacheSetup;
use App\Modules\Lancache\Actions\ProbeLancache;
use DomainException;

/**
 * Domain errors from the Lancache module. Mirrors
 * {@see RemoteExecutionException}'s translation-key-carrying shape so UI
 * callers can render a German message without string-matching on
 * `getMessage()`.
 */
class LancacheException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /**
     * Thrown by {@see ApplyLancacheSetup} and {@see ProbeLancache} when the
     * given {@see RemoteHost} is not registered with
     * {@see HostRole::Lancache} — the LanCache bootstrap/health command must
     * only ever run on a host explicitly designated for it, never on an
     * arbitrary generic or game-server host.
     */
    public static function notALancacheHost(RemoteHost $host): self
    {
        return new self(
            "RemoteHost {$host->id} is not registered with role=lancache (actual: {$host->role->value}).",
            'lancache.errors.not_a_lancache_host',
        );
    }
}
