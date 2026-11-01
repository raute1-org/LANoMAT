<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EntryRoster;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Support\BroadcastsSafely;
use Illuminate\Support\Str;

/**
 * The web voice counterpart of the Mumble/TeamSpeak tournament tree: one standing room per
 * non-withdrawn entry of a team tournament (`team_size > 1`), readable only by that entry's
 * roster. Idempotent per entry. No SFU call, like {@see ProvisionMatchChannels}.
 */
class ProvisionTeamChannels
{
    use BroadcastsSafely;

    /**
     * @return int how many rooms were created
     */
    public function handle(Tournament $tournament): int
    {
        if ($tournament->team_size <= 1) {
            return 0;
        }

        $created = 0;

        $entries = $tournament->entries()->get()->reject(fn (TournamentEntry $entry): bool => $entry->status === EntryStatus::Withdrawn);

        foreach ($entries as $entry) {
            $exists = WebVoiceChannel::query()
                ->where('kind', ChannelKind::Team->value)
                ->where('tournament_entry_id', $entry->getKey())
                ->exists();

            if ($exists) {
                continue;
            }

            $channel = new WebVoiceChannel;
            $channel->kind = ChannelKind::Team;
            $channel->name = Str::limit($entry->display_name, 80, '');
            $channel->description = Str::limit(__('web_voice.team.description', ['tournament' => $tournament->name]), 160, '');
            $channel->tournament_entry_id = $entry->getKey();
            $channel->save();

            $channel->members()->sync(EntryRoster::userIdsFor($entry));
            $created++;
        }

        if ($created > 0) {
            $this->broadcastSafely(new ChannelsChanged);
        }

        return $created;
    }
}
