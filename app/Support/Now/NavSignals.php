<?php

declare(strict_types=1);

namespace App\Support\Now;

use App\Models\User;
use App\Modules\Catering\Support\FoodNow;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\GameServers\Support\ServerGameSummary;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Support\MySeat;
use App\Modules\WebVoice\NativeClient\Support\NowFeed;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Cache;

/**
 * The small live hints the sidebar shows next to its items (shared Inertia prop `navSignals`).
 * Every field is null when its module is off or there is no current event; the whole bundle is
 * cached per user for {@see self::TTL} seconds because it rides on every page response (except
 * `registered`, which is always fresh, and the food hint, which a new or cancelled order item drops
 * through {@see self::forget()}).
 */
final class NavSignals
{
    public const TTL = 30;

    public function __construct(
        private readonly CurrentEvent $current,
        private readonly Modules $modules,
        private readonly NowFeed $feed,
    ) {}

    /**
     * @return array{serversOnline: int|null, tournament: 'ready'|'checkin'|null, foodClosesAt: string|null, seat: string|null, present: int|null, registered: bool|null}
     */
    public function for(User $user): array
    {
        /** @var array{serversOnline: int|null, tournament: 'ready'|'checkin'|null, foodClosesAt: string|null, seat: string|null, present: int|null} $cached */
        $cached = Cache::remember(self::key($user->id), self::TTL, fn (): array => $this->build($user));

        // Whether the viewer is registered must be fresh right after registering (the sidebar
        // drops its "Anmeldung" hint), so it is one cheap exists() outside the cached bundle.
        return [...$cached, 'registered' => $this->registered($user)];
    }

    /** Drops the cached bundle of a user whose state just changed (e.g. after ordering food). */
    public static function forget(int $userId): void
    {
        Cache::forget(self::key($userId));
    }

    private static function key(int $userId): string
    {
        return 'nav-signals:'.$userId;
    }

    private function registered(User $user): ?bool
    {
        $event = $this->current->get();

        return $event === null ? null : EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->exists();
    }

    /**
     * @return array{serversOnline: int|null, tournament: 'ready'|'checkin'|null, foodClosesAt: string|null, seat: string|null, present: int|null}
     */
    private function build(User $user): array
    {
        $event = $this->current->get();

        return [
            'serversOnline' => $this->modules->enabled(Module::GameServers) ? ServerGameSummary::totalPlayers($event) : null,
            'tournament' => $event === null ? null : $this->tournament($user, $event),
            'foodClosesAt' => $event === null || ! $this->modules->enabled(Module::Catering) ? null : $this->foodClosesAt($user, $event),
            'seat' => $event === null || ! $this->modules->enabled(Module::Seating) ? null : $this->seat($user, $event),
            'present' => $event === null || ! $this->modules->enabled(Module::Presence) ? null : $this->present($event),
        ];
    }

    /**
     * @return 'ready'|'checkin'|null
     */
    private function tournament(User $user, Event $event): ?string
    {
        if (! $this->modules->enabled(Module::Tournaments)) {
            return null;
        }

        $states = [];

        foreach ($this->feed->for($user, $event) as $cards) {
            foreach ($cards as $card) {
                if ($card['kind'] === 'match') {
                    $states[] = $card['state'];
                }
            }
        }

        if (array_intersect($states, ['ready', 'live', 'confirm_result']) !== []) {
            return 'ready';
        }

        return in_array('checkin_open', $states, true) ? 'checkin' : null;
    }

    private function foodClosesAt(User $user, Event $event): ?string
    {
        $open = FoodNow::for($event, $user)['open'];

        return $open !== null && $open['orderable'] && $open['mine'] === null ? $open['closesAt'] : null;
    }

    private function seat(User $user, Event $event): ?string
    {
        $seat = MySeat::for($event, $user);

        return $seat === null ? null : $seat['label'];
    }

    private function present(Event $event): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->whereNotNull('checked_in_at')
            ->count();
    }
}
