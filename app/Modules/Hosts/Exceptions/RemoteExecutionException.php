<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Exceptions;

use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\SshRemoteExecutor;
use DomainException;

/**
 * Domain errors from the Hosts module's SSH layer. Mirrors
 * {@see GameServerException}'s
 * translation-key-carrying shape so UI callers can render a German message
 * without string-matching on `getMessage()`. Messages here are deliberately
 * generic/non-sensitive: never the private key, never raw command
 * stdout/stderr (which may contain secrets from the remote host).
 */
class RemoteExecutionException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /**
     * Thrown by {@see SshRemoteExecutor} when the SSH connection or login to
     * a {@see RemoteHost} fails.
     */
    public static function connectFailed(): self
    {
        return new self(
            'Failed to connect to the remote host.',
            'hosts.errors.connect_failed',
        );
    }

    /**
     * Thrown by {@see SshRemoteExecutor} when `strict_host_key` is enabled,
     * the host already has a pinned `host_fingerprint`, and the server's
     * presented host key does not match it — the load-bearing MITM guard.
     */
    public static function fingerprintMismatch(): self
    {
        return new self(
            'The remote host\'s key fingerprint does not match the pinned fingerprint.',
            'hosts.errors.fingerprint_mismatch',
        );
    }

    /**
     * Thrown by {@see SshRemoteExecutor} when a command could not be
     * executed at all (as opposed to executing and returning a non-zero
     * exit code, which is a normal {@see CommandResult}).
     */
    public static function commandFailed(): self
    {
        return new self(
            'Failed to execute the command on the remote host.',
            'hosts.errors.command_failed',
        );
    }
}
