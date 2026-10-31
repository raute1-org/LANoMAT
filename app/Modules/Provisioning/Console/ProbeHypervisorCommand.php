<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\ProbeHypervisor;
use App\Modules\Provisioning\Console\Concerns\GuardsProvisioning;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use Illuminate\Console\Command;

class ProbeHypervisorCommand extends Command
{
    use GuardsProvisioning;

    protected $signature = 'provisioning:probe-hypervisor
        {hypervisor : Name des registrierten Hypervisors}
        {--as= : ID des ausführenden Orga-/Admin-Benutzers}';

    protected $description = 'Hypervisor nur lesend prüfen: Version, Nodes, Storages, freier RAM und Plattenplatz.';

    public function handle(ProbeHypervisor $probe): int
    {
        if (! $this->moduleEnabled() || ($actor = $this->actor($this->option('as'))) === null) {
            return self::FAILURE;
        }

        $hypervisor = $this->findHypervisor($this->stringArgument('hypervisor'));

        if ($hypervisor === null) {
            return self::FAILURE;
        }

        try {
            $summary = $probe->handle($hypervisor, $actor);
        } catch (HypervisorException $e) {
            $this->error(__($e->translationKey).' '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('provisioning.console.probe_version', ['version' => $summary->version, 'node' => $summary->node]));
        $this->table(
            [__('provisioning.console.col_node'), __('provisioning.console.col_online'), 'CPU', __('provisioning.console.col_ram_free')],
            array_map(fn ($n): array => [$n->name, $n->online ? 'ja' : 'nein', $n->maxCpu, self::gib($n->memFree())], $summary->nodes),
        );
        $this->table(
            [__('provisioning.console.col_storage'), 'Typ', __('provisioning.console.col_content'), __('provisioning.console.col_free')],
            array_map(fn ($s): array => [$s->name, $s->type, implode(',', $s->content), self::gib($s->avail)], $summary->storages),
        );
        $this->line(__('provisioning.console.probe_summary', [
            'ram' => self::gib($summary->memFreeBytes()),
            'disk' => self::gib($summary->freeDiskBytes()),
            'import' => implode(', ', array_map(fn ($s): string => $s->name, $summary->importStorages())) ?: '-',
        ]));

        if ($summary->importStorages() === []) {
            $this->warn(__('provisioning.console.no_import_storage'));
        }

        return self::SUCCESS;
    }

    private static function gib(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 1, ',', '.').' GiB';
    }
}
