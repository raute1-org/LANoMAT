<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use Database\Factories\LinkedAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A third-party account (Steam, Twitch, ...) linked to a LANoMAT user.
 * `discord_id` stays a plain column on `users` and is NOT represented here —
 * this table is for the additional identity providers layered on top.
 *
 * @property int $id
 * @property int $user_id
 * @property LinkedAccountProvider $provider
 * @property string $provider_user_id
 * @property string|null $nickname
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property array<int, string>|null $scopes
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LinkedAccount extends Model
{
    /** @use HasFactory<LinkedAccountFactory> */
    use HasFactory;

    /**
     * access_token and refresh_token are deliberately NOT fillable — they are
     * set only via forceFill() in the actions that own the OAuth/token
     * exchange, never mass-assigned from client input. Mirrors RemoteHost's
     * ssh_private_key exclusion (see app/Modules/Hosts/Models/RemoteHost.php).
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'nickname',
        'scopes',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'provider' => LinkedAccountProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function needsReauth(): bool
    {
        return (bool) ($this->meta['needs_reauth'] ?? false);
    }

    protected static function newFactory(): LinkedAccountFactory
    {
        return LinkedAccountFactory::new();
    }
}
