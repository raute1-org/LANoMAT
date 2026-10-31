<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A moderator mute: while a row exists, join tokens for this user in this channel are
 * issued without publish rights, so the mute survives a re-join.
 *
 * @property int $id
 * @property int $web_voice_channel_id
 * @property int $user_id
 * @property int|null $muted_by
 */
class WebVoiceMute extends Model
{
    protected $guarded = ['*'];
}
