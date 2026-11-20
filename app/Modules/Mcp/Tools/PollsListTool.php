<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('polls_list')]
#[Description('Polls of one event with options and the caller\'s own vote (results only as the web page shows them).')]
#[IsReadOnly]
class PollsListTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Voting);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $event = $this->eventFromSlug((string) $request->get('event_slug'));
        $userId = $this->user()->id;

        $polls = Poll::query()
            ->where('event_id', $event->id)
            ->with(['options' => fn ($query) => $query->orderBy('sort')->orderBy('id')])
            ->orderByDesc('id')
            ->get();

        $votes = PollVote::query()
            ->whereIn('poll_id', $polls->pluck('id'))
            ->where('user_id', $userId)
            ->get()
            ->keyBy('poll_id');

        return $this->structured([
            'polls' => $polls->map(fn (Poll $poll): array => [
                'id' => $poll->id,
                'question' => $poll->question,
                'status' => $poll->status->value,
                'kind' => $poll->kind->value,
                'closes_at' => $poll->closes_at?->toIso8601String(),
                'my_vote_option_id' => $votes->get($poll->id)?->poll_option_id,
                'options' => $poll->options->map(static fn (PollOption $option): array => [
                    'id' => $option->id,
                    'label' => $option->label,
                ])->all(),
            ])->all(),
        ]);
    }
}
