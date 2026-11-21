<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Assistant\Tools\Write\Concerns\TargetsServer;
use App\Modules\GameServers\Actions\RestartRound;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ServerRestartRoundTool extends ApprovableTool
{
    use TargetsServer;

    public static function toolName(): string
    {
        return 'server_restart_round';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::GameServers];
    }

    public function description(): string
    {
        return 'Startet auf einem laufenden CS2-Dauer-Server die Runde neu, die Punktestände werden zurückgesetzt (nach Bestätigung auf einer Karte).';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->serverIdSchema($schema);
    }

    protected function precheck(array $arguments): ?string
    {
        $link = $this->findLink($arguments);

        if ($link === null) {
            return 'Not found: no server with this server_id (see servers_list).';
        }

        return $this->can('configure', $link) ? null : 'Forbidden: this account may not configure this server.';
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $link = $this->linkOrFail($arguments);

        return new ApprovalPreview(__('assistant.card.title.restart_round'), [
            ApprovalPreview::row(__('assistant.card.label.server'), $this->describeLink($link)),
            ApprovalPreview::row(__('assistant.card.label.command'), RestartRound::commandFor($link), true),
        ], danger: true);
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $link = $this->linkOrFail($arguments);

        app(RestartRound::class)->handle($this->actor, $link);

        return ['restarted' => true, 'server_id' => $link->id];
    }
}
