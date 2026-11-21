<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Writes the general settings of a linked CS2 server into its Pelican startup
 * variables, optionally restarting it so they take effect.
 *
 * Only variables the server's egg declares are written (the panel silently ignores
 * the rest); the core ones that were left out are returned so the UI can say so.
 * A changed password is mirrored into the link's join info (the participant page
 * shows it), a blank one leaves both untouched. Nothing here logs a value: only the
 * names of the written variables.
 */
final class UpdateServerSettings
{
    use ConfiguresLinkedServer;

    public function __construct(
        private readonly PelicanClient $client,
        private readonly PowerServer $power,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{skipped: list<string>, restarted: bool}
     *
     * @throws GameServerException
     * @throws ValidationException
     */
    public function handle(User $actor, ServerLink $link, array $input, bool $restart = false): array
    {
        $this->authorizeConfigure($actor, $link);

        $settings = Cs2Settings::validate($input);
        $serverId = (string) $link->pelican_server_id;

        $server = $this->client->getServer($serverId);
        $eggId = $server->meta['egg'] ?? null;
        $declared = is_int($eggId) || is_string($eggId) ? array_keys($this->client->getEgg((string) $eggId)->variables) : null;

        $variables = $settings->variables();
        $write = $declared === null ? $variables : array_intersect_key($variables, array_flip($declared));
        $skipped = array_values(array_diff(
            array_intersect(Cs2Settings::CORE_VARIABLES, array_keys($variables)),
            array_keys($write),
        ));

        if ($write !== []) {
            $this->client->updateStartupVariables($serverId, $write);
        }

        $passwordChange = $settings->passwordChange();

        if ($passwordChange !== null && array_key_exists(Cs2Settings::VAR_PASSWORD, $write)) {
            $info = $link->join_info;
            $link->join_info = new JoinInfo($info->address, $info->port, $passwordChange === '' ? null : $passwordChange, $info->connectString);
            $link->save();
        }

        if ($restart) {
            $this->power->handle($link, PowerAction::Restart);
        }

        Log::info('Permanent server settings written.', [
            'server_link' => $link->id,
            'actor' => $actor->id,
            'variables' => array_keys($write),
            'restarted' => $restart,
        ]);

        return ['skipped' => $skipped, 'restarted' => $restart];
    }
}
