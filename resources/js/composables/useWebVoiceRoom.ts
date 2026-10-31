import type { Room } from 'livekit-client';
import { computed, onUnmounted, ref, shallowRef } from 'vue';
import {
    WebVoiceHttpError,
    webVoiceRequest,
    webVoiceUrls,
} from '@/lib/webVoiceHttp';
import type {
    WebVoiceParticipantView,
    WebVoiceRoomStatus,
    WebVoiceTokenResponse,
} from '@/types';

/**
 * One LiveKit room connection for the page. `livekit-client` is imported on the first join, so
 * no other page pays for it.
 *
 * Latency decisions live here (numbers come from `config/webvoice.php`, rationale in
 * docs/superpowers/specs/2026-10-31-web-voice-design.md):
 * - audio only, mono Opus speech preset with DTX + RED, no adaptive stream or dynacast;
 * - join first (listening), microphone on demand; the mic track is published once and then only
 *   muted/unmuted, so push-to-talk is instant (no renegotiation, no new getUserMedia).
 */
const loadLiveKit = () => import('livekit-client');
type LiveKit = Awaited<ReturnType<typeof loadLiveKit>>;

export type JoinFailure = 'unavailable' | 'denied_mic' | 'failed';

export function useWebVoiceRoom() {
    const status = ref<WebVoiceRoomStatus>('idle');
    const failure = ref<JoinFailure | null>(null);
    const participants = ref<WebVoiceParticipantView[]>([]);
    const micOn = ref(false);
    const mutedByModerator = ref(false);
    const needsAudioUnlock = ref(false);
    const pushToTalk = ref(false);
    const pttHeld = ref(false);
    const joinedChannelId = ref<number | null>(null);

    const room = shallowRef<Room | null>(null);
    let lk: LiveKit | null = null;
    let leaving = false;
    const audioElements = new Set<HTMLMediaElement>();

    const isConnected = computed(
        () => status.value === 'connected' || status.value === 'reconnecting',
    );

    function userIdOf(identity: string): number | null {
        const match = /^u(\d+)$/.exec(identity);

        return match ? Number(match[1]) : null;
    }

    function refresh(): void {
        const current = room.value;

        if (!current) {
            participants.value = [];

            return;
        }

        const speaking = new Set(
            current.activeSpeakers.map((speaker) => speaker.identity),
        );
        const everyone = [
            current.localParticipant,
            ...current.remoteParticipants.values(),
        ];

        participants.value = everyone.map((participant) => ({
            identity: participant.identity,
            userId: userIdOf(participant.identity),
            name: participant.name || participant.identity,
            speaking: speaking.has(participant.identity),
            micOn: participant.isMicrophoneEnabled,
            canSpeak: participant.permissions?.canPublish !== false,
            isLocal: participant === current.localParticipant,
        }));

        micOn.value = current.localParticipant.isMicrophoneEnabled;
        mutedByModerator.value =
            current.localParticipant.permissions?.canPublish === false;
    }

    function detachAll(): void {
        audioElements.forEach((element) => element.remove());
        audioElements.clear();
    }

    async function join(channelId: number): Promise<void> {
        if (status.value === 'connecting' || isConnected.value) {
            return;
        }

        failure.value = null;
        status.value = 'connecting';
        leaving = false;

        let grant: WebVoiceTokenResponse;

        try {
            grant = await webVoiceRequest<WebVoiceTokenResponse>(
                'POST',
                webVoiceUrls.token(channelId),
            );
        } catch (error) {
            status.value = 'error';
            failure.value =
                error instanceof WebVoiceHttpError && error.status === 503
                    ? 'unavailable'
                    : 'failed';

            return;
        }

        const audio = grant.audio;

        try {
            lk = lk ?? (await loadLiveKit());
            const { Room, RoomEvent, Track } = lk;

            const next = new Room({
                // Audio only: both are video features.
                adaptiveStream: false,
                dynacast: false,
                audioCaptureDefaults: {
                    echoCancellation: audio.echo_cancellation,
                    noiseSuppression: audio.noise_suppression,
                    autoGainControl: audio.auto_gain_control,
                    channelCount: 1,
                    // A hint only; browsers decide. Ask for the smallest capture buffer.
                    latency: { ideal: 0.01 },
                },
                publishDefaults: {
                    audioPreset: { maxBitrate: audio.bitrate },
                    dtx: audio.dtx,
                    red: audio.red,
                },
            });

            next.on(RoomEvent.TrackSubscribed, (track) => {
                if (track.kind === Track.Kind.Audio) {
                    const element = track.attach();
                    element.classList.add('hidden');
                    document.body.appendChild(element);
                    audioElements.add(element);
                }
            })
                .on(RoomEvent.TrackUnsubscribed, (track) => {
                    track.detach().forEach((element) => {
                        element.remove();
                        audioElements.delete(element);
                    });
                })
                .on(RoomEvent.ParticipantConnected, refresh)
                .on(RoomEvent.ParticipantDisconnected, refresh)
                .on(RoomEvent.ActiveSpeakersChanged, refresh)
                .on(RoomEvent.TrackMuted, refresh)
                .on(RoomEvent.TrackUnmuted, refresh)
                .on(RoomEvent.TrackPublished, refresh)
                .on(RoomEvent.LocalTrackPublished, refresh)
                .on(RoomEvent.LocalTrackUnpublished, refresh)
                .on(RoomEvent.ParticipantPermissionsChanged, refresh)
                .on(RoomEvent.AudioPlaybackStatusChanged, () => {
                    needsAudioUnlock.value = !next.canPlaybackAudio;
                })
                .on(RoomEvent.Reconnecting, () => {
                    status.value = 'reconnecting';
                })
                .on(RoomEvent.Reconnected, () => {
                    status.value = 'connected';
                    refresh();
                })
                .on(RoomEvent.Disconnected, () => {
                    detachAll();
                    room.value = null;
                    joinedChannelId.value = null;
                    micOn.value = false;
                    participants.value = [];

                    if (leaving) {
                        status.value = 'idle';
                    } else {
                        status.value = 'error';
                        failure.value = 'failed';
                    }
                });

            room.value = next;
            await next.connect(grant.url, grant.token);
            joinedChannelId.value = channelId;
            status.value = 'connected';
            needsAudioUnlock.value = !next.canPlaybackAudio;
            refresh();
        } catch {
            detachAll();
            room.value = null;
            status.value = 'error';
            failure.value = 'failed';
        }
    }

    async function leave(): Promise<void> {
        leaving = true;
        const current = room.value;

        if (current) {
            await current.disconnect();
        }

        detachAll();
        room.value = null;
        joinedChannelId.value = null;
        participants.value = [];
        micOn.value = false;
        pushToTalk.value = false;
        status.value = 'idle';
    }

    async function setMic(enabled: boolean): Promise<void> {
        const current = room.value;

        if (!current || (enabled && mutedByModerator.value)) {
            return;
        }

        try {
            // First enable publishes the track (asks for the microphone); afterwards this is
            // only a mute/unmute of the same track, which is why push-to-talk is instant.
            await current.localParticipant.setMicrophoneEnabled(enabled);
            failure.value = null;
        } catch {
            failure.value = 'denied_mic';
        }

        refresh();
    }

    async function toggleMic(): Promise<void> {
        await setMic(!micOn.value);
    }

    /** Switches push-to-talk on (publishes the mic once, then keeps it muted) or off. */
    async function setPushToTalk(enabled: boolean): Promise<void> {
        pushToTalk.value = enabled;

        if (!room.value) {
            return;
        }

        if (enabled) {
            await setMic(true);
            await setMic(false);

            if (failure.value === 'denied_mic') {
                pushToTalk.value = false;
            }
        }
    }

    async function pttDown(): Promise<void> {
        if (!pushToTalk.value || pttHeld.value) {
            return;
        }

        pttHeld.value = true;
        await setMic(true);
    }

    async function pttUp(): Promise<void> {
        if (!pttHeld.value) {
            return;
        }

        pttHeld.value = false;
        await setMic(false);
    }

    async function unlockAudio(): Promise<void> {
        await room.value?.startAudio();
        needsAudioUnlock.value = !(room.value?.canPlaybackAudio ?? true);
    }

    onUnmounted(() => {
        leaving = true;
        void room.value?.disconnect();
        detachAll();
    });

    return {
        status,
        failure,
        participants,
        micOn,
        mutedByModerator,
        needsAudioUnlock,
        pushToTalk,
        pttHeld,
        joinedChannelId,
        isConnected,
        join,
        leave,
        toggleMic,
        setPushToTalk,
        pttDown,
        pttUp,
        unlockAudio,
    };
}
