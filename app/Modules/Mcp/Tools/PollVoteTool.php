<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('poll_vote')]
#[Description('Cast or change the caller\'s vote in an open poll.')]
class PollVoteTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Voting);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'poll_id' => $schema->integer()->description('Poll id from polls_list.')->required(),
            'option_id' => $schema->integer()->description('Option id from polls_list.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $castVote = app(CastVote::class);
        // A poll of a draft event is not public: the id must not become a
        // vote-anything oracle for participants.
        $poll = Poll::query()
            ->whereHas('event', fn ($query) => $query->where('status', '!=', EventStatus::Draft->value))
            ->findOrFail((int) $request->get('poll_id'));
        $option = PollOption::query()
            ->where('poll_id', $poll->id)
            ->findOrFail((int) $request->get('option_id'));

        $vote = $castVote->handle($poll, $this->user(), $option);

        return $this->structured([
            'vote' => [
                'poll_id' => $poll->id,
                'option_id' => $vote->poll_option_id,
            ],
        ]);
    }
}
