<?php

namespace App\Modules\Voting\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Http\GalleryPageController;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the event's "player of the evening" poll: a Draft
 * {@see Poll} with {@see PollKind::Mvp}, auto-seeded with one
 * {@see PollOption} per registered, non-cancelled participant — labelled by
 * that user's display name (mirroring the "registered, non-cancelled"
 * eligibility query used by {@see GalleryPageController::canUpload()}).
 *
 * `kind` (like `status`) is deliberately NOT mass-assignable on {@see Poll}
 * (a privilege/state field), so both are set here via `forceFill()` right
 * after construction. The orga may still edit/remove the seeded options via
 * the existing Filament option management before opening the poll;
 * lifecycle transitions stay with the existing {@see OpenPoll}/
 * {@see ClosePoll} actions — this action only ever creates a Draft poll.
 */
class SeedMvpPoll
{
    public function handle(Event $event, User $actor): Poll
    {
        Gate::forUser($actor)->authorize('create', Poll::class);

        if (Poll::query()->where('event_id', $event->id)->where('kind', PollKind::Mvp)->exists()) {
            throw VotingException::mvpPollExists();
        }

        return DB::transaction(function () use ($event): Poll {
            $poll = new Poll([
                'event_id' => $event->id,
                'question' => trans('polls.mvp.question'),
            ]);
            $poll->forceFill(['kind' => PollKind::Mvp, 'status' => PollStatus::Draft]);
            $poll->save();

            $participants = EventRegistration::query()
                ->where('event_id', $event->id)
                ->whereNotIn('status', [RegistrationStatus::Cancelled])
                ->with('user')
                ->get();

            foreach ($participants as $sort => $registration) {
                $user = $registration->user;

                if ($user === null) {
                    continue;
                }

                $poll->options()->create([
                    'label' => $user->displayNameFor(),
                    'sort' => $sort,
                ]);
            }

            return $poll;
        });
    }
}
