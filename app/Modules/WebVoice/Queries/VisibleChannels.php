<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Queries;

use App\Models\User;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Policies\WebVoiceChannelPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model: the channels one viewer may see. It must agree with
 * {@see WebVoiceChannelPolicy::view()} (a test pins the two
 * together): an admin or orga sees every live channel, everybody else the general channels plus the
 * match channels whose roster snapshot contains them. Archived channels are never listed.
 */
class VisibleChannels
{
    /**
     * @return Collection<int, WebVoiceChannel>
     */
    public function for(User $viewer): Collection
    {
        return $this->query($viewer)
            ->orderBy('kind')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<WebVoiceChannel>
     */
    public function query(User $viewer): Builder
    {
        $query = WebVoiceChannel::query()->whereNull('archived_at');

        if ($viewer->isOrga()) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($viewer): void {
            $visible->where('kind', ChannelKind::General->value)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($viewer->getKey()));
        });
    }
}
