<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Provisioning\Contracts\HypervisorClient;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\ProvisioningContext;

/**
 * Step 0a: the cloud image lands on the hypervisor, checksum-verified by the
 * hypervisor while downloading. The stored file name carries the checksum
 * prefix, so an existing file of that name is by construction a verified one
 * (skip). The storage must accept the `import` content type.
 */
class DownloadVerifiedImage implements ProvisioningStep
{
    public function __construct(private readonly HypervisorClientFactory $clients) {}

    public function key(): string
    {
        return 'download_image';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        $c = $context->config;

        return new StepDescription(
            __('provisioning.steps.download_image.title'),
            [
                PlannedAction::hypervisor(__('provisioning.steps.download_image.check_storage', ['storage' => $c->imageStorage])),
                PlannedAction::hypervisor(__('provisioning.steps.download_image.skip_when_exists', ['volume' => $this->volume($context)])),
                PlannedAction::hypervisor(__('provisioning.steps.download_image.download', ['url' => $c->image->url, 'volume' => $this->volume($context), 'algorithm' => $c->image->algorithm, 'checksum' => $c->image->checksum])),
            ],
            [__('provisioning.steps.download_image.verify')],
            __('provisioning.steps.download_image.rollback'),
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $client = $this->client($context);
        $storage = collect($client->listStorages($context->node()))->first(fn ($s): bool => $s->name === $context->config->imageStorage);

        if ($storage === null || ! $storage->usable() || ! $storage->acceptsContent('import')) {
            throw ProvisioningException::stepFailed("Storage [{$context->config->imageStorage}] does not accept cloud images (content type 'import'). Enable 'Import' under Datacenter > Storage, or choose another image_storage.");
        }

        if ($client->hasVolume($context->node(), $context->config->imageStorage, $this->volume($context))) {
            $context->log('image already present, skipping download');

            return StepOutcome::AlreadySatisfied;
        }

        $volume = $client->downloadImage($context->node(), $context->config->imageStorage, $context->config->image);
        $context->put('image_volume', $volume);
        $context->log("downloaded {$volume}");

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        return $this->client($context)->hasVolume($context->node(), $context->config->imageStorage, $this->volume($context))
            ? VerifyResult::ok()
            : VerifyResult::failed('The image volume is not present after the download.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        // Deliberately empty: a verified, cached image is harmless and speeds up the next run.
    }

    private function volume(ProvisioningContext $context): string
    {
        return "{$context->config->imageStorage}:import/{$context->config->image->filename}";
    }

    private function client(ProvisioningContext $context): HypervisorClient
    {
        return $this->clients->for($context->hypervisor());
    }
}
