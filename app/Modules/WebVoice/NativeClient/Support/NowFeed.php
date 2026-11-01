<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\WebVoice\Contracts\OrderCardSource;
use App\Modules\WebVoice\Contracts\ScheduleCardSource;
use App\Modules\WebVoice\Contracts\VoteCardSource;
use App\Modules\WebVoice\Domain\NowCard;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The prioritised "what do I do now" list of the native client's first screen (see
 * clients/voice/docs/ux-concept.md). Sources:
 *
 * - match: the viewer's own matches and tournament check-ins, read through the same helpers the
 *   web tournament pages use (Tournaments is a core module, so this is a direct read);
 * - vote, order, schedule: one contract each, implemented by the owning module and replaced by a
 *   null source while that module is off;
 * - notification: the latest unread in-app notifications (Notifications is core, read directly).
 *
 * The voice card is the client's own and is not part of this feed. Lower priority comes first;
 * at most {@see self::LIMIT} cards are shown, the remainder is returned as `more`.
 */
final class NowFeed
{
    public const LIMIT = 5;

    public const NOTIFICATIONS = 3;

    /** A check-in that closes within this many minutes outranks a ready match. */
    private const CHECKIN_URGENT_MINUTES = 10;

    public function __construct(
        private readonly VoteCardSource $votes,
        private readonly OrderCardSource $orders,
        private readonly ScheduleCardSource $schedule,
    ) {}

    /**
     * @return array{cards: list<array<string, mixed>>, more: list<array<string, mixed>>}
     */
    public function for(User $user, Event $event): array
    {
        $actionable = [
            ...$this->matchCards($user, $event),
            ...$this->votes->cards($user, $event),
            ...$this->orders->cards($user, $event),
        ];

        // The schedule only fills the screen when nothing else asks for attention.
        $cards = collect([
            ...$actionable,
            ...($actionable === [] ? $this->schedule->cards($user, $event) : []),
            ...$this->notificationCards($user),
        ])->sortBy(fn (NowCard $card): int => $card->priority)->values();

        return [
            'cards' => array_values($cards->take(self::LIMIT)->map(fn (NowCard $card): array => $card->toArray())->all()),
            'more' => array_values($cards->slice(self::LIMIT)->map(fn (NowCard $card): array => $card->toArray())->all()),
        ];
    }

    /**
     * @return list<NowCard>
     */
    private function matchCards(User $user, Event $event): array
    {
        $cards = [];

        foreach (NativeTournaments::forEvent($user, $event) as $tournament) {
            if (! ($tournament['can']['checkIn'] ?? false)) {
                continue;
            }

            $closes = isset($tournament['checkinClosesAt']) ? CarbonImmutable::parse($tournament['checkinClosesAt']) : null;
            $urgent = $closes !== null && $closes->lte(now()->addMinutes(self::CHECKIN_URGENT_MINUTES));

            $cards[] = new NowCard(
                kind: NowCard::KIND_MATCH,
                id: 'checkin:'.$tournament['id'],
                state: 'checkin_open',
                priority: $urgent ? 5 : 12,
                title: trans('native_client.now.checkin_open', ['tournament' => $tournament['name']]),
                at: $closes,
                data: ['tournament' => $tournament],
            );
        }

        foreach (NativeMatches::active($user, $event) as $match) {
            $card = $this->matchCard($match);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $match  a NativeMatches DTO
     */
    private function matchCard(array $match): ?NowCard
    {
        $opponent = (string) ($match['opponent']['name'] ?? '?');
        $tournament = (string) $match['tournament']['name'];
        $at = isset($match['scheduledAt']) ? CarbonImmutable::parse($match['scheduledAt']) : null;
        $id = 'match:'.$match['id'];

        if ($match['status'] === MatchStatus::Reported->value) {
            // Only the side that has to answer gets a card; the reporter just waits.
            if (! ($match['can']['confirm'] ?? false)) {
                return null;
            }

            $report = $match['pendingReport'];

            return new NowCard(NowCard::KIND_MATCH, $id, 'confirm_result', 8, trans('native_client.now.confirm_result', [
                'own' => (string) ($report['ownScore'] ?? 0),
                'opponent_score' => (string) ($report['opponentScore'] ?? 0),
                'opponent' => $opponent,
            ]), $tournament, $at, ['match' => $match]);
        }

        return match ($match['status']) {
            MatchStatus::Ready->value, MatchStatus::Warmup->value => new NowCard(NowCard::KIND_MATCH, $id, 'ready', 10, trans('native_client.now.match_ready', ['opponent' => $opponent]), $tournament, $at, ['match' => $match]),
            MatchStatus::Live->value => new NowCard(NowCard::KIND_MATCH, $id, 'live', 15, trans('native_client.now.match_live', ['opponent' => $opponent]), $tournament, $at, ['match' => $match]),
            default => null,
        };
    }

    /**
     * @return list<NowCard>
     */
    private function notificationCards(User $user): array
    {
        /** @var Collection<int, DatabaseNotification> $unread */
        $unread = $user->unreadNotifications()->latest()->limit(self::NOTIFICATIONS)->get();

        $cards = [];

        foreach ($unread->values() as $index => $notification) {
            $data = $notification->data;

            $cards[] = new NowCard(
                kind: NowCard::KIND_NOTIFICATION,
                id: 'notification:'.$notification->id,
                state: 'unread',
                priority: 60 + $index,
                title: (string) ($data['title'] ?? ''),
                body: isset($data['body']) ? (string) $data['body'] : null,
                at: $notification->created_at,
                data: ['notificationId' => $notification->id, 'category' => $data['category'] ?? null],
            );
        }

        return $cards;
    }
}
