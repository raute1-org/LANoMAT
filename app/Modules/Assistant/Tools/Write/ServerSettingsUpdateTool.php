<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Assistant\Tools\Write\Concerns\TargetsServer;
use App\Modules\GameServers\Actions\UpdateServerSettings;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Passwords never pass through the model. The model only chooses
 * `password_action` (set, clear, keep); for `set` the orga types the new
 * password on the approval card, and it reaches {@see execute()} as the
 * `$secret` argument, never as a tool argument.
 */
final class ServerSettingsUpdateTool extends ApprovableTool
{
    use TargetsServer;

    public static function toolName(): string
    {
        return 'server_settings_update';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::GameServers];
    }

    public function description(): string
    {
        return 'Ändert Einstellungen eines CS2-Dauer-Servers (nach Bestätigung auf einer Karte): Name, Modus, Map, Workshop-ID, Spielerzahl, optional Neustart. '
            .'Es gibt kein Passwort-Feld: mit password_action=set bittet man die Orga, das neue Passwort auf der Karte einzutippen, clear entfernt es, keep lässt es. '
            .'Wiederhole nie ein Passwort, das jemand im Chat nennt.';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->serverIdSchema($schema) + [
            'server_name' => $schema->string()->description('Neuer Servername (höchstens 64 Zeichen).'),
            'mode' => $schema->string()->enum(array_keys(Cs2Settings::MODES))->description('Spielmodus.'),
            'map' => $schema->string()->enum(Cs2Settings::MAPS)->description('Standard-Map.'),
            'workshop_id' => $schema->string()->description('Workshop-Map-ID (nur Ziffern), statt map.'),
            'max_players' => $schema->integer()->description('Maximale Spielerzahl (1 bis 64).')->min(1)->max(64),
            'password_action' => $schema->string()->enum(['set', 'clear', 'keep'])->description('set = Orga tippt das Passwort auf der Karte, clear = entfernen, keep = unverändert (Standard).'),
            'restart' => $schema->boolean()->description('Server danach neu starten, damit die Änderungen greifen.'),
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
            'server_name' => ['nullable', 'string', 'max:64', 'regex:/^[\p{L}\p{N} _.,:+#()\[\]!&\/-]+$/u'],
            'mode' => ['nullable', Rule::in(array_keys(Cs2Settings::MODES))],
            'map' => ['nullable', Rule::in(Cs2Settings::MAPS)],
            'workshop_id' => ['nullable', 'regex:/^\d{6,12}$/'],
            'max_players' => ['nullable', 'integer', 'between:1,64'],
            'password_action' => ['nullable', Rule::in(['set', 'clear', 'keep'])],
            'restart' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();

            return implode(' ', array_map(fn (string $f, array $m): string => $f.': '.implode(' ', $m), array_keys($errors), array_values($errors)));
        }

        $hasChange = collect(['server_name', 'mode', 'map', 'workshop_id', 'max_players'])->contains(fn (string $k): bool => isset($arguments[$k]))
            || $this->passwordAction($arguments) !== 'keep'
            || ! empty($arguments['restart']);

        return $hasChange ? null : 'No changes given: pass at least one setting to change.';
    }

    public function needsSecret(array $arguments): bool
    {
        return $this->passwordAction($arguments) === 'set';
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $link = $this->linkOrFail($arguments);
        $current = $this->currentState($link);
        $rows = [ApprovalPreview::row('Server', $this->describeLink($link))];
        $labels = ['server_name' => 'Name', 'mode' => 'Modus', 'map' => 'Map', 'workshop_id' => 'Workshop-ID', 'max_players' => 'Spieler'];

        foreach ($labels as $key => $label) {
            if (isset($arguments[$key])) {
                $old = $current[$key] ?? null;
                $rows[] = ApprovalPreview::row($label, ($old !== null && $old !== '' ? $old.' → ' : '').$arguments[$key], in_array($key, ['map', 'workshop_id'], true));
            }
        }

        $action = $this->passwordAction($arguments);

        if ($action !== 'keep') {
            $rows[] = ApprovalPreview::row('Passwort', $action === 'set' ? 'wird auf der Karte gesetzt' : 'wird entfernt');
        }

        if (! empty($arguments['restart'])) {
            $rows[] = ApprovalPreview::row('Neustart', true);
        }

        return new ApprovalPreview('Server-Einstellungen ändern', $rows, danger: ! empty($arguments['restart']), needsSecret: $action === 'set');
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $link = $this->linkOrFail($arguments);
        $this->authorize('configure', $link);

        $input = array_filter(
            array_intersect_key($arguments, array_flip(['server_name', 'mode', 'map', 'workshop_id', 'max_players'])),
            fn (mixed $v): bool => $v !== null && $v !== '',
        );
        $action = $this->passwordAction($arguments);

        if ($action === 'set' && ($secret === null || $secret === '')) {
            return __('assistant.password_missing');
        }

        // The action needs a complete setting set: unchanged values come from the live server.
        $current = Cs2Settings::formState($this->environment($link));
        $merged = array_merge($current, $input);

        // A workshop map replaces the standard map, and the other way round.
        if (isset($input['workshop_id'])) {
            $merged['map'] = null;
        } elseif (isset($input['map'])) {
            $merged['workshop_id'] = null;
        }

        $merged += ['password' => $action === 'set' ? $secret : null, 'clear_password' => $action === 'clear'];

        $result = app(UpdateServerSettings::class)->handle($this->actor, $link, $merged, ! empty($arguments['restart']));

        return ['updated' => true, 'server_id' => $link->id, 'restarted' => $result['restarted'], 'skipped' => $result['skipped']];
    }

    /** @param  array<string, mixed>  $arguments */
    private function passwordAction(array $arguments): string
    {
        $action = (string) ($arguments['password_action'] ?? 'keep');

        return in_array($action, ['set', 'clear', 'keep'], true) ? $action : 'keep';
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(ServerLink $link): array
    {
        $server = app(PelicanClient::class)->getServer((string) $link->pelican_server_id);
        $container = is_array($server->meta['container'] ?? null) ? $server->meta['container'] : [];

        return is_array($container['environment'] ?? null) ? $container['environment'] : [];
    }

    /**
     * Best effort for the old values on the card; a panel outage only drops them.
     *
     * @return array<string, mixed>
     */
    private function currentState(ServerLink $link): array
    {
        try {
            return $link->hasPelicanLink() ? Cs2Settings::formState($this->environment($link)) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
