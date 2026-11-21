<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Models;

use App\Models\User;
use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\Mcp\Models\McpToken;
use Carbon\CarbonInterface;
use Database\Factories\LauncherTerminalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * One paired gaming terminal in the launcher fleet (an arcade cabinet, a LAN
 * party PC, a living-room box). The launcher upserts this row through
 * `POST /api/v1/terminals/heartbeat`; `mcp_token_id` is the identity, so the
 * same token always lands on the same row.
 *
 * `state` is the last *reported* state. Whether the terminal still counts as
 * online is a function of `last_seen_at`, never of the reported state alone:
 * a cabinet that was unplugged mid-game never gets to say "offline".
 *
 * @property int $id
 * @property int|null $mcp_token_id
 * @property string $name
 * @property string|null $os
 * @property string|null $arch
 * @property string|null $version
 * @property TerminalState $state
 * @property string|null $current_game_slug
 * @property list<mixed>|null $installed
 * @property bool $gamepad
 * @property CarbonInterface|null $last_seen_at
 * @property string|null $ip
 */
class LauncherTerminal extends Model
{
    /** @use HasFactory<LauncherTerminalFactory> */
    use HasFactory;

    /** The heartbeat cadence the launcher contract asks for. */
    public const HEARTBEAT_SECONDS = 30;

    /** Three missed heartbeats and the terminal reads as offline. */
    public const OFFLINE_AFTER_SECONDS = 3 * self::HEARTBEAT_SECONDS;

    // Identity and system-managed state (token, last_seen_at, ip) are set by
    // RecordTerminalHeartbeat, never by mass assignment from a request.
    protected $fillable = [
        'name',
        'os',
        'arch',
        'version',
        'state',
        'current_game_slug',
        'installed',
        'gamepad',
    ];

    protected function casts(): array
    {
        return [
            'state' => TerminalState::class,
            'installed' => 'array',
            'gamepad' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<McpToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(McpToken::class, 'mcp_token_id');
    }

    /**
     * The user who paired this terminal (through its token). Authorization
     * itself stays the token owner's role, so revoking a token detaches the
     * row instead of leaving a dangling identity.
     *
     * `mcp_tokens.id = launcher_terminals.mcp_token_id` and
     * `users.id = mcp_tokens.user_id` — the through-row is found by our own
     * local key, the related user by the token's `user_id`.
     *
     * @return HasOneThrough<User, McpToken, $this>
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            McpToken::class,
            firstKey: 'id',
            secondKey: 'id',
            localKey: 'mcp_token_id',
            secondLocalKey: 'user_id',
        );
    }

    /** @param Builder<LauncherTerminal> $query */
    public function scopeSeenSince(Builder $query, CarbonInterface $since): void
    {
        $query->where('last_seen_at', '>=', $since);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    /**
     * What the monitor shows: the reported state while the heartbeat is fresh,
     * "offline" once it is not. A terminal that says goodbye (`offline`) stays
     * offline until its next heartbeat.
     */
    public function derivedState(): TerminalState
    {
        if (! $this->isOnline()) {
            return TerminalState::Offline;
        }

        return $this->state === TerminalState::Offline ? TerminalState::Offline : $this->state;
    }

    /** @return list<string> */
    public function installedSlugs(): array
    {
        return array_values(array_filter(
            $this->installed ?? [],
            static fn (mixed $slug): bool => is_string($slug) && $slug !== '',
        ));
    }

    protected static function newFactory(): LauncherTerminalFactory
    {
        return LauncherTerminalFactory::new();
    }
}
