<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\RegisterHypervisor;
use App\Modules\Provisioning\Console\Concerns\GuardsProvisioning;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RegisterHypervisorCommand extends Command
{
    use GuardsProvisioning;

    protected $signature = 'provisioning:register-hypervisor
        {name : Anzeigename des Hypervisors}
        {base_url : https://<host>:8006}
        {token_id : API-Token-ID, z. B. user@realm!tokenname}
        {--node=pve : Standard-Node}
        {--tls-pin= : Optionaler SPKI-Pin (sha256//<base64>) für selbstsignierte Zertifikate}
        {--jump-host= : Optional: ID eines Remote-Hosts (der Hypervisor selbst) für die unabhängige Host-Key-Prüfung}
        {--as= : ID des ausführenden Orga-/Admin-Benutzers}';

    protected $description = 'Hypervisor (Proxmox VE) mit verschlüsseltem API-Token registrieren. Den Token per Umgebungsvariable PROVISIONING_TOKEN_SECRET oder Eingabeaufforderung übergeben.';

    public function handle(RegisterHypervisor $register): int
    {
        if (! $this->moduleEnabled() || ($actor = $this->actor($this->option('as'))) === null) {
            return self::FAILURE;
        }

        // The secret never appears as an argument (shell history, process list).
        $secret = getenv('PROVISIONING_TOKEN_SECRET');
        $secret = is_string($secret) && $secret !== '' ? $secret : (string) $this->secret(__('provisioning.console.token_prompt'));

        try {
            $hypervisor = $register->handle([
                'name' => (string) $this->argument('name'),
                'base_url' => (string) $this->argument('base_url'),
                'node' => (string) $this->option('node'),
                'token_id' => (string) $this->argument('token_id'),
                'tls_pin' => $this->option('tls-pin') ?: null,
                'jump_host_id' => is_numeric($this->option('jump-host')) ? (int) $this->option('jump-host') : null,
            ], $secret, $actor);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error("{$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->info(__('provisioning.console.hypervisor_registered', ['name' => $hypervisor->name, 'id' => $hypervisor->id]));

        return self::SUCCESS;
    }
}
