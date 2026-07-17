<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Models;

use App\Modules\CustomServers\Enums\CustomServerStatus;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use Database\Factories\CustomServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An orga-defined docker "escape hatch" game server: a plain `docker run`
 * command executed on a registered {@see RemoteHost} via the
 * {@see RemoteExecutor} contract, for games
 * Pelican (M6's GameServers module) doesn't cover.
 *
 * @property string $name
 * @property int $remote_host_id
 * @property int|null $event_id
 * @property string $image
 * @property string|null $command
 * @property string|null $ports
 * @property array<string, string>|null $env
 * @property string $container_name
 * @property CustomServerStatus $status
 * @property string|null $last_output
 */
class CustomServer extends Model
{
    /** @use HasFactory<CustomServerFactory> */
    use HasFactory;

    // status and last_output are deliberately NOT fillable: they are
    // system-managed state written only by StartCustomServer,
    // StopCustomServer, and ProbeCustomServer (mirrors RemoteHost's
    // status/last_probed_at).
    protected $fillable = [
        'name',
        'remote_host_id',
        'event_id',
        'image',
        'command',
        'ports',
        'env',
        'container_name',
    ];

    protected function casts(): array
    {
        return [
            'env' => 'array',
            'status' => CustomServerStatus::class,
        ];
    }

    /** @return BelongsTo<RemoteHost, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(RemoteHost::class, 'remote_host_id');
    }

    protected static function newFactory(): CustomServerFactory
    {
        return CustomServerFactory::new();
    }
}
