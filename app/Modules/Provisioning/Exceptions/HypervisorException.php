<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * Errors from the hypervisor layer. Carries a translation key so callers
 * (console, UI) can show a German message; messages never contain tokens,
 * request headers or raw response bodies.
 */
class HypervisorException extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('No hypervisor is configured.', 'provisioning.errors.hypervisor_not_configured');
    }

    public static function unauthorized(int $status): self
    {
        return new self("The hypervisor rejected the API token (HTTP {$status}).", 'provisioning.errors.hypervisor_unauthorized', $status);
    }

    public static function unreachable(): self
    {
        return new self('The hypervisor is not reachable (connection or TLS pin failed).', 'provisioning.errors.hypervisor_unreachable');
    }

    public static function notFound(string $what): self
    {
        return new self("Hypervisor resource not found: {$what}.", 'provisioning.errors.hypervisor_not_found', 404);
    }

    public static function api(int $status, string $detail): self
    {
        return new self('Hypervisor API error (HTTP '.$status.'): '.mb_substr($detail, 0, 300), 'provisioning.errors.hypervisor_api', $status);
    }

    public static function malformed(string $what): self
    {
        return new self("Unexpected hypervisor response: {$what}.", 'provisioning.errors.hypervisor_malformed');
    }

    public static function taskFailed(string $exitStatus): self
    {
        return new self('A hypervisor task failed: '.mb_substr($exitStatus, 0, 300), 'provisioning.errors.hypervisor_task_failed');
    }

    public static function taskTimeout(): self
    {
        return new self('A hypervisor task did not finish in time.', 'provisioning.errors.hypervisor_task_timeout');
    }

    public static function checksumRequired(): self
    {
        return new self('A valid sha256 or sha512 checksum is mandatory for an image download.', 'provisioning.errors.checksum_required');
    }

    public static function invalidImageSource(): self
    {
        return new self('The image URL must be https and the file name a plain .qcow2/.img/.raw name.', 'provisioning.errors.invalid_image_source');
    }

    public static function vmidInUse(int $vmid): self
    {
        return new self("VM id {$vmid} is already in use.", 'provisioning.errors.vmid_in_use');
    }
}
