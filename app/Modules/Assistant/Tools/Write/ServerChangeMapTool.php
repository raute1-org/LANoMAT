<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Assistant\Tools\Write\Concerns\TargetsServer;
use App\Modules\GameServers\Actions\ChangeServerMap;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ServerChangeMapTool extends ApprovableTool
{
    use TargetsServer;

    public static function toolName(): string
    {
        return 'server_change_map';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::GameServers];
    }

    public function description(): string
    {
        return 'Wechselt die Map eines laufenden CS2-Dauer-Servers sofort (nach Bestätigung auf einer Karte), ohne die Startvariablen zu ändern. Entweder map oder workshop_id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->serverIdSchema($schema) + [
            'map' => $schema->string()->enum(Cs2Settings::MAPS)->description('Standard-Map.'),
            'workshop_id' => $schema->string()->description('Workshop-Map-ID (nur Ziffern).'),
        ];
    }

    protected function precheck(array $arguments): ?string
    {
        $link = $this->findLink($arguments);

        if ($link === null) {
            return 'Not found: no server with this server_id (see servers_list).';
        }

        if (! $this->can('configure', $link)) {
            return 'Forbidden: this account may not configure this server.';
        }

        $validator = Validator::make($arguments, [
            'map' => ['nullable', 'required_without:workshop_id', Rule::in(Cs2Settings::MAPS)],
            'workshop_id' => ['nullable', 'required_without:map', 'regex:/^\d{6,12}$/'],
        ]);

        return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $link = $this->linkOrFail($arguments);
        $workshop = isset($arguments['workshop_id']) && $arguments['workshop_id'] !== '';

        return new ApprovalPreview(__('assistant.card.title.change_map'), [
            ApprovalPreview::row(__('assistant.card.label.server'), $this->describeLink($link)),
            ApprovalPreview::row($workshop ? 'Workshop-ID' : 'Map', $workshop ? $arguments['workshop_id'] : ($arguments['map'] ?? null), true),
        ]);
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $link = $this->linkOrFail($arguments);

        app(ChangeServerMap::class)->handle(
            $this->actor,
            $link,
            isset($arguments['map']) ? (string) $arguments['map'] : null,
            isset($arguments['workshop_id']) ? (string) $arguments['workshop_id'] : null,
        );

        return ['changed' => true, 'server_id' => $link->id];
    }
}
