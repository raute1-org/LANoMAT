<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Models;

use App\Models\User;
use App\Modules\GameServers\Casts\JoinInfoCast;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Database\Factories\ServerLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a match (or, for non-match provisioning, a tournament) to its
 * provisioned Pelican game server.
 *
 * @property string|null $pelican_server_id
 * @property JoinInfo $join_info
 * @property ServerLinkStatus $status
 * @property bool $manual
 * @property int|null $requested_by
 */
class ServerLink extends Model
{
    /** @use HasFactory<ServerLinkFactory> */
    use HasFactory;

    // pelican_server_id/join_info/status are provisioning state set only by
    // the provisioning Job/Action (Task 4), never client-fillable (mirrors
    // GameMatch's status/score fields). requested_by is written only by
    // SetManualJoinInfo (the interactive path) — see GuardrailPolicy's
    // per-user-cap doc for why the automatic job path leaves it null.
    protected $fillable = [
        'match_id',
        'tournament_id',
        'manual',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'join_info' => JoinInfoCast::class,
            'status' => ServerLinkStatus::class,
            'manual' => 'boolean',
        ];
    }

    /** @return BelongsTo<GameMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /** @return BelongsTo<Tournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected static function newFactory(): ServerLinkFactory
    {
        return ServerLinkFactory::new();
    }
}
