import { useEchoPublic } from '@laravel/echo-vue';
import { onUnmounted } from 'vue';

/**
 * Subscribes to the public `event.{id}` broadcast channel (see
 * `routes/channels.php` — no auth needed, poll results are public) and
 * invokes `callback` whenever the backend reports a change on one of
 * `eventNames`.
 *
 * Event names must match each event class's `broadcastAs()` exactly
 * (Task 13's `PollUpdated` -> `poll.updated`). Custom broadcast names are
 * listened to with a leading dot so Echo does not namespace them under the
 * event's fully-qualified class name — mirrors
 * {@see file://./useTournamentChannel.ts}.
 */
export function useEventChannel<TPayload = unknown>(
    eventId: number,
    eventNames: string[],
    callback: (payload: TPayload) => void,
): void {
    const { stopListening, leaveChannel } = useEchoPublic<TPayload>(
        `event.${eventId}`,
        eventNames,
        callback,
    );

    onUnmounted(() => {
        stopListening();
        leaveChannel();
    });
}
