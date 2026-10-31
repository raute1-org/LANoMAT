<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EntryRoster;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Support\BroadcastsSafely;
use Illuminate\Support\Str;

/**
 * Makes one team room per entry of a ready match, readable only by that entry's roster
 * (snapshot taken now, like `roster_snapshot`). Idempotent: a second `MatchReady` for the same
 * match neither duplicates a room nor resurrects an archived one. No SFU call: LiveKit creates the
 * room when the first person joins, so this cannot fail because the server is down.
 */
class ProvisionMatchChannels
{
    use BroadcastsSafely;

    /**
     * @return int how many rooms were created
     */
    public function handle(GameMatch $match): int
    {
        $match->loadMissing(['entry1', 'entry2']);
        $tournamentName = $match->tournament()->firstOrFail()->name;

        $created = 0;

        foreach ([[$match->entry1, $match->entry2], [$match->entry2, $match->entry1]] as [$entry, $opponent]) {
            if (! $entry instanceof TournamentEntry) {
                continue;
            }

            $channel = WebVoiceChannel::query()
                ->where('game_match_id', $match->getKey())
                ->where('tournament_entry_id', $entry->getKey())
                ->first();

            if ($channel !== null) {
                continue;
            }

            $channel = new WebVoiceChannel;
            $channel->kind = ChannelKind::Match;
            $channel->name = Str::limit($entry->display_name, 80, '');
            $channel->description = Str::limit(__('web_voice.match.description', [
                'tournament' => $tournamentName,
                'round' => $match->round,
                'opponent' => $opponent instanceof TournamentEntry ? $opponent->display_name : '?',
            ]), 160, '');
            $channel->game_match_id = $match->getKey();
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
