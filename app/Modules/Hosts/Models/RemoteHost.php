<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Enums\HostStatus;
use Database\Factories\RemoteHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $name
 * @property string $hostname
 * @property int $ssh_port
 * @property string $ssh_user
 * @property string $ssh_private_key
 * @property string|null $host_fingerprint
 * @property HostRole $role
 * @property int|null $event_id
 * @property HostStatus $status
 * @property Carbon|null $last_probed_at
 */
class RemoteHost extends Model
{
    /** @use HasFactory<RemoteHostFactory> */
    use HasFactory;

    // ssh_private_key, host_fingerprint, status, and last_probed_at are
    // deliberately NOT fillable: the private key must only ever be set
    // through RegisterHost or a Filament Create/Edit page override (never
    // raw mass assignment — this is the whole point of this task's security
    // model), and host_fingerprint/status/last_probed_at are system-managed
    // state written only by Task 2's ProbeHost.
    protected $fillable = [
        'name',
        'hostname',
        'ssh_port',
        'ssh_user',
        'role',
        'event_id',
    ];

    protected function casts(): array
    {
        return [
            'ssh_port' => 'integer',
            'ssh_private_key' => 'encrypted',
            'role' => HostRole::class,
            'status' => HostStatus::class,
            'last_probed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected static function newFactory(): RemoteHostFactory
    {
        return RemoteHostFactory::new();
    }
}
