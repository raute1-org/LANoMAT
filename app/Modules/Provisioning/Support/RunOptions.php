<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

/**
 * `rollbackFailedStep` (default on): when a step fails, undo only that step.
 * `rollbackAll` (explicit `--rollback`): after a failure also undo the steps
 * that this run applied before, in reverse order.
 */
final readonly class RunOptions
{
    public function __construct(
        public bool $rollbackFailedStep = true,
        public bool $rollbackAll = false,
    ) {}

    /** @return array{rollback_failed_step: bool, rollback_all: bool} */
    public function toArray(): array
    {
        return ['rollback_failed_step' => $this->rollbackFailedStep, 'rollback_all' => $this->rollbackAll];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self((bool) ($a['rollback_failed_step'] ?? true), (bool) ($a['rollback_all'] ?? false));
    }
}
