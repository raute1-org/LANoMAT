<?php

namespace App\Modules\Discord\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single "this Discord user is currently in this voice channel" row —
 * the read-model behind {@see VoicePresenceProjection}. Guild-wide (Discord
 * voice is not scoped to a LANoMAT event). `user_id` links to the mapped
 * LANoMAT user when the Discord account is known, else null.
 *
 * @property string $discord_user_id
 * @property string $channel_id
 * @property string|null $channel_name
 * @property int|null $user_id
 */
class DiscordVoiceState extends Model
{
    protected $fillable = ['discord_user_id', 'channel_id', 'channel_name', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
