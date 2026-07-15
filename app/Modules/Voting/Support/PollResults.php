<?php

namespace App\Modules\Voting\Support;

use App\Modules\Voting\Events\PollUpdated;
use App\Modules\Voting\Models\Poll;

/**
 * Read-only tally projection for a Poll — shared between the broadcast
 * payload ({@see PollUpdated}) and any HTTP
 * results endpoint, so both always agree on shape and numbers.
 */
class PollResults
{
    /**
     * @return array{
     *     pollId: int,
     *     question: string,
     *     totalVotes: int,
     *     options: array<int, array{id: int, label: string, count: int, percent: float}>,
     * }
     */
    public static function for(Poll $poll): array
    {
        $options = $poll->options()->withCount('votes')->orderBy('sort')->get();

        $totalVotes = (int) $options->sum('votes_count');

        return [
            'pollId' => $poll->id,
            'question' => $poll->question,
            'totalVotes' => $totalVotes,
            'options' => $options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'count' => (int) $option->votes_count,
                'percent' => $totalVotes === 0 ? 0.0 : round(($option->votes_count / $totalVotes) * 100, 1),
            ])->all(),
        ];
    }
}
