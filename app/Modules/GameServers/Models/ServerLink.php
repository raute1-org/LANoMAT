<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Casts\JoinInfoCast;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Carbon\CarbonInterface;
use Database\Factories\ServerLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Links a match (or, for non-match provisioning, a tournament) to its
 * provisioned Pelican game server.
 *
 * @property string|null $pelican_server_id Numeric Application API id (show/delete).
 * @property string|null $pelican_server_identifier Short uuid the Client API (power/resources) uses.
 * @property JoinInfo $join_info
 * @property ServerLinkStatus $status
 * @property bool $manual
 * @property int|null $requested_by
 * @property string|null $telemetry_token
 * @property int|null $event_id Set for event-level servers started from a preset.
 * @property int|null $game_id The preset's game (event-level servers have no tournament to take it from).
 * @property string|null $title
 * @property string|null $description
 * @property bool $permanent Runs all year, independent of any event/match/tournament.
 * @property ServerSource $source
 * @property string|null $pelican_node_id
 * @property int|null $custom_server_id
 * @property QueryProtocol|null $query_protocol
 * @property string|null $preset_key
 * @property array<string, mixed>|null $readiness Preset readiness/verification snapshot.
 * @property array<string, mixed>|null $query_info Last A2S answer (see ServerInfo::toArray()).
 * @property CarbonInterface|null $queried_at
 * @property int $install_attempts
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
    // telemetry_token is likewise never client-fillable — it is lazily
    // generated in booted() below, the bearer credential a CS2 game server
    // presents to MatchTelemetryController (Task 12).
    // permanent/source/pelican_node_id/custom_server_id/query_protocol are written
    // only by CreatePermanentServer, never client-fillable.
    protected $fillable = [
        'match_id',
        'tournament_id',
        'manual',
        'requested_by',
        'event_id',
        'game_id',
        'title',
        'description',
        'preset_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServerLink $link): void {
            $link->telemetry_token ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'join_info' => JoinInfoCast::class,
            'status' => ServerLinkStatus::class,
            'manual' => 'boolean',
            'permanent' => 'boolean',
            'source' => ServerSource::class,
            'query_protocol' => QueryProtocol::class,
            'readiness' => 'array',
            'query_info' => 'array',
            'queried_at' => 'datetime',
            'install_attempts' => 'integer',
        ];
    }

    /**
     * Permanent servers only (see the `permanent` column).
     *
     * @param  Builder<ServerLink>  $query
     * @return Builder<ServerLink>
     */
    public function scopePermanent(Builder $query): Builder
    {
        return $query->where('permanent', true);
    }

    /**
     * Match- and event-level servers only.
     *
     * @param  Builder<ServerLink>  $query
     * @return Builder<ServerLink>
     */
    public function scopeNotPermanent(Builder $query): Builder
    {
        return $query->where('permanent', false);
    }

    /** @return BelongsTo<CustomServer, $this> */
    public function customServer(): BelongsTo
    {
        return $this->belongsTo(CustomServer::class);
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

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
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
