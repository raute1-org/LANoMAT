<?php

namespace App\Modules\Discord\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordOutbox extends Model
{
    protected $table = 'discord_outbox';

    protected $fillable = ['kind', 'dedup_key', 'channel_id', 'content', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
