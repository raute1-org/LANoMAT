<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Exceptions\ProvisioningException;

/**
 * The remote side of the steps: runs rendered {@see ProvisioningScript}s and
 * installs files on the VM, ONLY through the {@see RemoteExecutor} contract.
 * Output is logged redacted and truncated; a failing script raises a
 * ProvisioningException that names the script, not the raw output.
 */
final class VmShell
{
    public function __construct(
        private readonly RemoteExecutor $executor,
        private readonly ProvisioningContext $context,
        private readonly RemoteHost $host,
    ) {}

    public function run(ProvisioningScript $script): CommandResult
    {
        $result = $this->exec($script);

        if (! $result->ok()) {
            throw ProvisioningException::stepFailed("Remote script [{$script->name}] failed (exit {$result->exitCode}).");
        }

        return $result;
    }

    /** Runs a read-only check; true when it exits 0. Never throws on a non-zero exit. */
    public function succeeds(ProvisioningScript $script): bool
    {
        return $this->exec($script)->ok();
    }

    public function output(ProvisioningScript $script): string
    {
        return trim($this->run($script)->stdout);
    }

    /** Uploads `$contents` and installs it root-owned at `$target` (sudo install; the SSH user is not root). */
    public function install(string $contents, string $target, string $mode = '0644'): void
    {
        $this->executor->upload($this->host, $contents, self::tmpPath($target));
        $this->context->log("uploaded {$target}");
        $this->run(self::installScript($target, $mode));
    }

    public static function tmpPath(string $target): string
    {
        return '/tmp/lanomat-'.substr(hash('sha256', $target), 0, 12);
    }

    public static function installScript(string $target, string $mode = '0644'): ProvisioningScript
    {
        return ProvisioningScript::make('install-'.basename($target), 'sudo install -D -m {{MODE}} {{TMP}} {{TARGET}} && rm -f {{TMP}}', [
            'MODE' => $mode, 'TMP' => self::tmpPath($target), 'TARGET' => $target,
        ]);
    }

    /**
     * The dry-run lines for installing a file (upload to the temp path, then the install script).
     *
     * @return list<PlannedAction>
     */
    public static function installActions(string $contents, string $target, string $mode = '0644'): array
    {
        return [
            PlannedAction::upload(self::tmpPath($target), $contents),
            PlannedAction::ssh(self::installScript($target, $mode)->render()),
        ];
    }

    private function exec(ProvisioningScript $script): CommandResult
    {
        $result = $this->executor->run($this->host, $script->command());
        $this->context->log("[{$script->name}] exit {$result->exitCode}");

        $tail = trim($result->stdout."\n".$result->stderr);

        if ($tail !== '') {
            $this->context->log(Redactor::truncate($tail, 1500));
        }

        return $result;
    }
}
