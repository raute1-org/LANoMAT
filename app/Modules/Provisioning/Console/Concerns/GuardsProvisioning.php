<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console\Concerns;

use App\Models\User;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

/**
 * Shared console plumbing: module gate, the acting user (`--as=<user id>`,
 * policies still apply) and config/hypervisor lookup. Messages are German.
 */
trait GuardsProvisioning
{
    private function moduleEnabled(): bool
    {
        if (app(Modules::class)->enabled(Module::Provisioning)) {
            return true;
        }

        $this->error(__('provisioning.errors.module_disabled'));

        return false;
    }

    /**
     * A declared argument as a string; Symfony hands out a wider type than the
     * signature guarantees, so check instead of cast.
     */
    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * An option as a non-empty string, or null when it was not given.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function actor(mixed $id): ?User
    {
        $user = is_numeric($id) ? User::query()->find((int) $id) : null;

        if ($user === null) {
            $this->error(__('provisioning.console.actor_required'));
        }

        return $user;
    }

    private function findHypervisor(?string $name): ?Hypervisor
    {
        $hypervisor = $name === null ? null : Hypervisor::query()->where('name', $name)->first();

        if ($name !== null && $hypervisor === null) {
            $this->error(__('provisioning.console.hypervisor_unknown', ['name' => $name]));
        }

        return $hypervisor;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readConfig(?string $path): ?array
    {
        $json = is_string($path) && is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)) {
            $this->error(__('provisioning.console.config_unreadable'));

            return null;
        }

        return $data;
    }

    private function printError(ProvisioningException $e): void
    {
        $this->error(__($e->translationKey).' '.$e->getMessage());
    }
}
