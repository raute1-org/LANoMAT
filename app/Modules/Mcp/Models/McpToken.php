<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bearer token for the MCP server / agent installer. The plain token is
 * shown exactly once (device-flow exchange); only its SHA-256 digest lives
 * here. Authorization always goes through the normal policies, so a role
 * change takes effect on the next request.
 *
 * @property int $user_id
 * @property string $name
 * @property array<int, string>|null $abilities Reserved `abilities` list; no mint path sets it and no request enforces it yet — authorization is the user's role through the policies.
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
class McpToken extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function hasAbility(string $ability): bool
    {
        return $this->abilities === null || in_array($ability, $this->abilities, true);
    }
}
