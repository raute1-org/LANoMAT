<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Assistant\Tools\Write\Concerns\TargetsServer;
use App\Modules\GameServers\Actions\ControlRemoteDockerPower;
use App\Modules\GameServers\Actions\PowerConfiguredServer;
use App\Modules\GameServers\Actions\PowerServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Models\ServerLink;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Power signal for one server. The wrapped action depends on the kind:
 * permanent servers go through the `configure` actions (orga and admin), a
 * remote Docker container through its own control, and an event/match server
 * through the raw {@see PowerServer} only after the `power` policy check here
 * (that action authorizes nothing by itself).
 */
final class ServerPowerTool extends ApprovableTool
{
    use TargetsServer;

    public static function toolName(): string
    {
        return 'server_power';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::GameServers];
    }

    public function description(): string
    {
        return 'Startet, stoppt, startet neu oder beendet (kill) einen Game-Server (nach Bestätigung auf einer Karte). Server-ID aus servers_list.';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->serverIdSchema($schema) + [
            'action' => $schema->string()->enum(array_map(fn (PowerAction $a) => $a->value, PowerAction::cases()))->description('start, stop, restart oder kill.')->required(),
        ];
    }

    protected function precheck(array $arguments): ?string
    {
        $link = $this->findLink($arguments);

        if ($link === null) {
            return 'Not found: no server with this server_id (see servers_list).';
        }

        if (PowerAction::tryFrom((string) ($arguments['action'] ?? '')) === null) {
            return 'action: must be one of start, stop, restart, kill.';
        }

        return $this->can($this->ability($link), $link) ? null : 'Forbidden: this account may not control this server.';
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $link = $this->linkOrFail($arguments);
        $action = PowerAction::from((string) $arguments['action']);

        return new ApprovalPreview(__('assistant.card.title.power', ['action' => $action->value]), [
            ApprovalPreview::row(__('assistant.card.label.server'), $this->describeLink($link)),
            ApprovalPreview::row(__('assistant.card.label.action'), $action->value, true),
        ], danger: in_array($action, [PowerAction::Stop, PowerAction::Kill], true));
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $link = $this->linkOrFail($arguments);
        $action = PowerAction::from((string) $arguments['action']);
        $this->authorize($this->ability($link), $link);

        if ($link->permanent && $this->isRemoteDocker($link)) {
            $state = app(ControlRemoteDockerPower::class)->handle($this->actor, $link, $action);

            return ['server_id' => $link->id, 'action' => $action->value, 'state' => $state];
        }

        if ($link->permanent) {
            app(PowerConfiguredServer::class)->handle($this->actor, $link, $action);
        } else {
            app(PowerServer::class)->handle($link, $action);
        }

        return ['server_id' => $link->id, 'action' => $action->value];
    }

    private function ability(ServerLink $link): string
    {
        return $link->permanent ? 'configure' : 'power';
    }
}
