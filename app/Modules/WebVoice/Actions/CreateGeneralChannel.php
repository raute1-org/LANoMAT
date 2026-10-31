<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Support\BroadcastsSafely;
use Illuminate\Support\Facades\Gate;

/** Admin: creates an installation-wide general channel (a lobby, a chill room). */
class CreateGeneralChannel
{
    use BroadcastsSafely;

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(User $admin, array $data): WebVoiceChannel
    {
        Gate::forUser($admin)->authorize('manage', WebVoiceChannel::class);

        $channel = new WebVoiceChannel;
        $channel->kind = ChannelKind::General;
        $channel->name = $data['name'];
        $channel->description = $data['description'] ?? null;
        $channel->save();

        $this->broadcastSafely(new ChannelsChanged);

        return $channel;
    }
}
