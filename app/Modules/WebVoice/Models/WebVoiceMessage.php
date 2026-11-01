<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One plain-text chat message. Written only by PostMessage (author = the authenticated
 * user), so nothing here is mass-assignable.
 *
 * @property int $id
 * @property int $web_voice_channel_id
 * @property int $user_id
 * @property string $body
 * @property Carbon $created_at
 */
class WebVoiceMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<WebVoiceChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(WebVoiceChannel::class, 'web_voice_channel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * `$native` picks the avatar path of the bearer API instead of the browser route; the realtime
     * broadcast is shared by both clients and carries the browser path (the native client maps it).
     *
     * @return array{id: int, channel_id: int, body: string, created_at: string, author: array{id: int, name: string, avatar_url: string|null}}
     */
    public function toPayload(bool $native = false): array
    {
        $author = $this->author ?? throw new LogicException('The author relation must be loaded.');

        return [
            'id' => $this->id,
            'channel_id' => $this->web_voice_channel_id,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'author' => [
                'id' => $this->user_id,
                'name' => $author->name,
                'avatar_url' => $native ? $author->nativeAvatarSrc() : $author->avatar_src,
            ],
        ];
    }
}
