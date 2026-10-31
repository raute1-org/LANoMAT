<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Models;

use App\Models\User;
use App\Modules\WebVoice\Domain\ChannelKind;
use Database\Factories\WebVoiceChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A voice + text channel. General channels are admin-managed and installation-wide;
 * match channels are one room per entry of a ready tournament match, readable only by
 * the roster snapshot in `members`.
 *
 * @property int $id
 * @property ChannelKind $kind
 * @property string $name
 * @property string|null $description
 * @property int|null $game_match_id
 * @property int|null $tournament_entry_id
 * @property Carbon|null $archived_at
 */
class WebVoiceChannel extends Model
{
    /** @use HasFactory<WebVoiceChannelFactory> */
    use HasFactory;

    // kind, match references and archived_at are state set by actions, never by a form.
    protected $fillable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'kind' => ChannelKind::class,
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WebVoiceChannelFactory
    {
        return WebVoiceChannelFactory::new();
    }

    public function isGeneral(): bool
    {
        return $this->kind === ChannelKind::General;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The LiveKit room name: derived from the id, never client-supplied. */
    public function roomName(): string
    {
        return (string) config('webvoice.room_prefix').$this->id;
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'web_voice_channel_user', 'web_voice_channel_id', 'user_id');
    }

    /** @return HasMany<WebVoiceMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WebVoiceMessage::class);
    }

    /** @return HasMany<WebVoiceMute, $this> */
    public function mutes(): HasMany
    {
        return $this->hasMany(WebVoiceMute::class);
    }
}
