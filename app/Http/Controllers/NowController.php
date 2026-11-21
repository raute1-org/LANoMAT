<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Models\User;
use App\Modules\Catering\Support\FoodNow;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerGameSummary;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedProviders;
use App\Modules\Lfg\Support\WantToPlayBoard;
use App\Modules\News\Models\NewsPost;
use App\Modules\News\Support\NewsQuery;
use App\Modules\Presence\Support\LocatedGames;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\MyRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Support\ScheduleProjection;
use App\Modules\Seating\Support\MySeat;
use App\Modules\Teams\Support\MyTeams;
use App\Modules\WebVoice\NativeClient\Support\NowFeed;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Jetzt" home page of a signed-in user (GET /, see HomeController). It composes
 * read models only and sits outside the module graph, so every optional module is reached
 * behind a `Modules::enabled()` guard; a block of an off module is null and the page hides it.
 */
class NowController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const NOW_PLAYING_ROWS = 8;

    private const UPCOMING_SCHEDULE = 3;

    private const PERMANENT_ROWS = 4;

    public function __invoke(Request $request, CurrentEvent $current, Modules $modules, NowFeed $feed): Response
    {
        $user = $this->authUser($request);
        $event = $current->get();
        $state = $event === null ? 'none' : ($event->status === EventStatus::Live ? 'live' : 'upcoming');

        $props = [
            'state' => $state,
            'event' => $event === null ? null : $this->eventDto($event),
            'forYou' => $event === null ? ['cards' => [], 'more' => []] : $this->forYou($feed, $user, $event),
            'food' => $event !== null && $modules->enabled(Module::Catering) ? FoodNow::for($event, $user) : null,
            'schedule' => $event !== null && $modules->enabled(Module::Schedule) ? $this->schedule($event) : null,
            'me' => $event === null ? null : [
                'registration' => MyRegistration::summary($event, $user),
                'seat' => MySeat::for($event, $user)['label'] ?? null,
                'team' => MyTeams::for($user)[0]['name'] ?? null,
                'steamLinked' => LinkedProviders::has($user, LinkedAccountProvider::Steam),
            ],
            'news' => $modules->enabled(Module::News) ? $this->news() : [],
            'permanent' => $state !== 'live' && $modules->enabled(Module::GameServers) && $this->mayViewServers($user)
                ? array_slice(array_map($this->permanentDto(...), ServerListProjection::forPermanent()), 0, self::PERMANENT_ROWS)
                : null,
            'labels' => trans('now'),
            'clientHint' => trans('web_voice.client_hint'),
        ];

        // Only a running event has anything to show here; the block is not part of the other states.
        $playing = $state === 'live'
            && ($modules->enabled(Module::GameServers) || $modules->enabled(Module::Presence));
        $props['nowPlaying'] = $playing ? Inertia::defer(fn (): array => $this->nowPlaying($modules, $user, $event)) : null;

        $props['wantToPlay'] = $event !== null && $modules->enabled(Module::Lfg)
            ? Inertia::defer(fn (): array => [
                ...app(WantToPlayBoard::class)->for($event, $user),
                'toggleUrl' => route('lfg.want-to-play', $event),
            ])
            : null;

        return Inertia::render('Now', $props);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventDto(Event $event): array
    {
        $registered = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value);

        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'status' => $event->status->value,
            'startsAt' => $event->starts_at?->toIso8601String(),
            'endsAt' => $event->ends_at?->toIso8601String(),
            'location' => $event->location,
            ...RegistrationAvailability::summary($event),
            'registeredCount' => (clone $registered)->count(),
            'checkedInCount' => (clone $registered)->whereNotNull('checked_in_at')->count(),
        ];
    }

    /**
     * @return array{cards: list<array<string, mixed>>, more: list<array<string, mixed>>}
     */
    private function forYou(NowFeed $feed, User $user, Event $event): array
    {
        $lists = $feed->for($user, $event);

        return [
            'cards' => array_map(fn (array $card): array => $this->decorate($card, $event), $lists['cards']),
            'more' => array_map(fn (array $card): array => $this->decorate($card, $event), $lists['more']),
        ];
    }

    /**
     * Adds what the web page needs on top of the shared feed card: where the card leads and its
     * action label. A match card already carries `data.match.server` from NativeMatches.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function decorate(array $card, Event $event): array
    {
        /** @var array<string, mixed> $data */
        $data = $card['data'];
        $kind = (string) $card['kind'];
        $state = (string) $card['state'];

        $href = match ($kind) {
            'match' => $this->tournamentId($data) === null ? null : route('tournaments.show', $this->tournamentId($data)),
            'vote' => isset($data['pollId']) ? route('polls.show', (int) $data['pollId']) : null,
            'order' => route('catering.show', $event),
            'schedule' => route('schedule.index', $event),
            default => null,
        };

        $label = match (true) {
            $kind === 'match' && $state === 'ready' => 'card_action_match',
            $kind === 'match' && in_array($state, ['live', 'confirm_result'], true) => 'card_action_match_report',
            $kind === 'match' && $state === 'checkin_open' => 'card_action_match_checkin',
            $kind === 'vote' => 'card_action_vote',
            $kind === 'order' => 'card_action_order',
            $kind === 'schedule' => 'card_action_schedule',
            default => null,
        };

        if ($label !== null) {
            $data['actionLabel'] = trans('now.'.$label);
        }

        return [...$card, 'data' => $data, 'href' => $href];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tournamentId(array $data): ?int
    {
        $id = $data['match']['tournament']['id'] ?? $data['tournament']['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** The same gate the /servers page applies to servers and permanent servers. */
    private function mayViewServers(User $user): bool
    {
        return Gate::forUser($user)->allows('viewPermanent', ServerLink::class);
    }

    /**
     * Servers (GameServers) merged with the located participants (Presence) per game.
     *
     * @return list<array<string, mixed>>
     */
    private function nowPlaying(Modules $modules, User $user, ?Event $event): array
    {
        $rows = [];

        if ($modules->enabled(Module::GameServers) && $this->mayViewServers($user)) {
            foreach (ServerGameSummary::forEvent($event) as $summary) {
                $rows[mb_strtolower($summary['game'])] = [
                    'game' => $summary['game'],
                    'people' => $summary['players'],
                    'servers' => $summary['servers'],
                    'players' => $summary['players'],
                    'maxPlayers' => $summary['maxPlayers'],
                    'join' => $summary['join'],
                ];
            }
        }

        if ($event !== null && $modules->enabled(Module::Presence)) {
            // The located counts stay ungated on purpose: aggregates without names, like the beamer scene.
            foreach (app(LocatedGames::class)->countsFor($event, $user) as $game => $located) {
                $key = mb_strtolower($game);
                $rows[$key] ??= ['game' => $game, 'people' => 0, 'servers' => 0, 'players' => 0, 'maxPlayers' => 0, 'join' => null];
                $rows[$key]['people'] = max($located, $rows[$key]['players']);
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['people'], $a['game']] <=> [$a['people'], $b['game']]);

        return array_slice($rows, 0, self::NOW_PLAYING_ROWS);
    }

    /**
     * @return array{current: array<string, mixed>|null, upcoming: list<array<string, mixed>>}
     */
    private function schedule(Event $event): array
    {
        $items = ScheduleProjection::itemsFor($event);
        $now = Carbon::now();

        $upcoming = $items
            ->filter(fn (ScheduleItem $item): bool => $item->starts_at->greaterThan($now))
            ->take(self::UPCOMING_SCHEDULE);

        return [
            'current' => $this->scheduleDto(ScheduleProjection::currentItem($items, $now)),
            'upcoming' => array_values(array_map(
                fn (ScheduleItem $item): array => (array) $this->scheduleDto(ScheduleProjection::itemDto($item)),
                $upcoming->all(),
            )),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{title: string, startsAt: string, endsAt: string|null}|null
     */
    private function scheduleDto(?array $item): ?array
    {
        return $item === null ? null : [
            'title' => (string) $item['title'],
            'startsAt' => (string) $item['startsAt'],
            'endsAt' => isset($item['endsAt']) ? (string) $item['endsAt'] : null,
        ];
    }

    /**
     * @return list<array{id: int, title: string, publishedAt: string|null}>
     */
    private function news(): array
    {
        return array_values(app(NewsQuery::class)->published(3)
            ->map(fn (NewsPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'publishedAt' => $post->published_at?->toIso8601String(),
            ])->all());
    }

    /**
     * @param  array<string, mixed>  $dto
     * @return array<string, mixed>
     */
    private function permanentDto(array $dto): array
    {
        $query = is_array($dto['query'] ?? null) ? $dto['query'] : null;

        return [
            'id' => $dto['id'],
            'title' => $dto['title'],
            'game' => $dto['game'] ?? null,
            'status' => $dto['status'],
            'players' => $query === null ? null : (int) $query['players'],
            'maxPlayers' => $query === null ? null : (int) $query['maxPlayers'],
            'connectString' => $dto['connectString'],
            'connectIsLink' => (bool) $dto['connectIsLink'],
        ];
    }
}
