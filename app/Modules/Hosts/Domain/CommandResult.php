<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Domain;

use App\Modules\Hosts\Contracts\RemoteExecutor;

/**
 * The outcome of a single remote command execution via
 * {@see RemoteExecutor::run()}.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
