<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Models;

use App\Modules\Hosts\Models\RemoteHost;
use Database\Factories\HypervisorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credentials of one Proxmox VE API endpoint. `token_secret` is never
 * fillable and never serialized: it is set only through RegisterHypervisor
 * (or a factory) and stored with the `encrypted` cast, like
 * RemoteHost::$ssh_private_key.
 *
 * @property string $name
 * @property string $base_url
 * @property string $node default node
 * @property string $token_id `user@realm!tokenid`
 * @property string $token_secret
 * @property string|null $tls_pin
 * @property int|null $jump_host_id
 * @property string|null $last_version
 * @property Carbon|null $last_probed_at
 */
class Hypervisor extends Model
{
    /** @use HasFactory<HypervisorFactory> */
    use HasFactory;

    protected $fillable = ['name', 'base_url', 'node', 'token_id', 'tls_pin', 'jump_host_id'];

    /** @var list<string> */
    protected $hidden = ['token_secret'];

    protected function casts(): array
    {
        return [
            'token_secret' => 'encrypted',
            'last_probed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RemoteHost, $this> */
    public function jumpHost(): BelongsTo
    {
        return $this->belongsTo(RemoteHost::class, 'jump_host_id');
    }

    protected static function newFactory(): HypervisorFactory
    {
        return HypervisorFactory::new();
    }
}
