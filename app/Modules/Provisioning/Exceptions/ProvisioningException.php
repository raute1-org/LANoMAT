<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * Domain errors of the provisioning runner and its steps. The message is
 * English and non-sensitive (it is persisted, redacted, in the step log);
 * `translationKey` gives the German text for the console/UI.
 */
class ProvisioningException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function alreadyRunning(string $target): self
    {
        return new self("A provisioning run for target [{$target}] is already active.", 'provisioning.errors.already_running');
    }

    public static function unknownPlan(string $key): self
    {
        return new self("Unknown provisioning plan [{$key}].", 'provisioning.errors.unknown_plan');
    }

    public static function invalidConfig(string $detail): self
    {
        return new self("Invalid provisioning config: {$detail}", 'provisioning.errors.invalid_config');
    }

    public static function stepFailed(string $detail): self
    {
        return new self($detail, 'provisioning.errors.step_failed');
    }

    public static function verificationFailed(string $detail): self
    {
        return new self("Verification failed: {$detail}", 'provisioning.errors.verification_failed');
    }

    public static function stepTimeout(): self
    {
        return new self('The step exceeded its time budget.', 'provisioning.errors.step_timeout');
    }

    public static function lockedOut(string $detail): self
    {
        return new self($detail, 'provisioning.errors.lock_out_guard');
    }

    public static function missingConfirmation(): self
    {
        return new self('Refusing to run without explicit confirmation.', 'provisioning.errors.missing_confirmation');
    }

    public static function hostKeyUntrusted(string $detail): self
    {
        return new self($detail, 'provisioning.errors.host_key_untrusted');
    }
}
