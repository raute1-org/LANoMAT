<?php

declare(strict_types=1);

namespace App\Modules\Voice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A voice channel created for an event-level game server, one row per
 * provider. Owned by the Voice module; `server_link_id` is a plain reference
 * to GameServers' server link, resolved only through the event payload.
 *
 * @property int $id
 * @property int $server_link_id
 * @property string $provider
 * @property int $channel_id
 */
class VoiceServerChannel extends Model
{
    protected $fillable = ['server_link_id', 'provider', 'channel_id'];

    protected function casts(): array
    {
        return [
            'server_link_id' => 'integer',
            'channel_id' => 'integer',
        ];
    }
}
