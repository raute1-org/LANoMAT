<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Domain\TestVmConfig;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Models\Hypervisor;
use Closure;

/**
 * Typed state shared by the steps of one run: the immutable config, the
 * hypervisor credential row, the acting user and a small mutable state bag
 * (VM id, host id, "did this run create it" flags) that the runner persists
 * after every step. Secrets never go into the state bag: generated keys are
 * stored straight into their encrypted owner (the RemoteHost).
 */
final class ProvisioningContext
{
    /** @var array<string, mixed> */
    private array $state;

    private string $log = '';

    private ?int $deadline = null;

    /** @var list<string> */
    private array $secrets = [];

    /** @var Closure(int): void */
    private Closure $sleeper;

    /**
     * @param  array<string, mixed>  $state
     * @param  (Closure(int): void)|null  $sleeper
     */
    public function __construct(
        public readonly TestVmConfig $config,
        public readonly ?Hypervisor $hypervisor,
        public readonly ?User $actor,
        public readonly bool $dryRun = false,
        array $state = [],
        ?Closure $sleeper = null,
    ) {
        $this->state = $state;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function hypervisor(): Hypervisor
    {
        return $this->hypervisor ?? throw ProvisioningException::stepFailed('No hypervisor is attached to this run.');
    }

    public function actor(): User
    {
        return $this->actor ?? throw ProvisioningException::stepFailed('The requesting user no longer exists.');
    }

    public function node(): string
    {
        return $this->config->node ?? $this->hypervisor->node ?? '<node>';
    }

    public function vmid(): ?int
    {
        $vmid = $this->state['vmid'] ?? $this->config->vmid;

        return is_int($vmid) ? $vmid : null;
    }

    public function vmidLabel(): string
    {
        return $this->vmid() === null ? '<next free vmid>' : (string) $this->vmid();
    }

    public function vmHost(): RemoteHost
    {
        $id = $this->state['host_id'] ?? null;

        return RemoteHost::query()->findOrFail(is_int($id) ? $id : throw ProvisioningException::stepFailed('The VM host is not registered yet.'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    public function flag(string $key): bool
    {
        return ($this->state[$key] ?? false) === true;
    }

    public function put(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->state;
    }

    public function registerSecret(string $secret): void
    {
        $this->secrets[] = $secret;
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return $this->secrets;
    }

    public function log(string $line): void
    {
        $this->log .= Redactor::redact($line, $this->secrets)."\n";
    }

    /** Returns and clears the log buffer (already redacted). */
    public function flushLog(): string
    {
        $log = $this->log;
        $this->log = '';

        return $log;
    }

    public function sleep(int $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    public function startStep(int $timeoutSeconds): void
    {
        $this->deadline = time() + $timeoutSeconds;
    }

    /** Long-running loops call this between attempts; the step budget is cooperative, not preemptive. */
    public function assertWithinDeadline(): void
    {
        if ($this->deadline !== null && time() > $this->deadline) {
            throw ProvisioningException::stepTimeout();
        }
    }
}
