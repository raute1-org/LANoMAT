<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Switches the map of a running CS2 server through the console (`changelevel <map>`
 * or `host_workshop_map <id>`) without touching its startup variables. The command
 * is built from an allowlisted map name or a digits-only workshop id, never from
 * free text.
 */
final class ChangeServerMap
{
    use ConfiguresLinkedServer;

    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @throws GameServerException
     * @throws ValidationException
     */
    public function handle(User $actor, ServerLink $link, ?string $map, ?string $workshopId): void
    {
        $identifier = $this->authorizeConfigure($actor, $link);

        $data = Validator::make(
            ['map' => $map === '' ? null : $map, 'workshop_id' => $workshopId === '' ? null : $workshopId],
            [
                'map' => ['nullable', 'required_without:workshop_id', Rule::in(Cs2Settings::MAPS)],
                'workshop_id' => ['nullable', 'required_without:map', 'regex:/^\d{6,12}$/'],
            ],
            attributes: ['map' => __('gameservers.settings.fields.map'), 'workshop_id' => __('gameservers.settings.fields.workshop_id')],
        )->validate();

        $workshop = isset($data['workshop_id']) ? (string) $data['workshop_id'] : null;
        $standard = $workshop === null && isset($data['map']) ? (string) $data['map'] : null;

        $this->client->sendCommand($identifier, Cs2Settings::mapCommand($standard, $workshop));

        Log::info('Permanent server map changed.', ['server_link' => $link->id, 'actor' => $actor->id]);
    }
}
