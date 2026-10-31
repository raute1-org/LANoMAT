<script setup lang="ts">
import { Mic, MicOff, UserX, VolumeOff, Volume2 } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import type { JoinFailure } from '@/composables/useWebVoiceRoom';
import type {
    WebVoiceChannelDto,
    WebVoiceParticipantView,
    WebVoiceRoomStatus,
} from '@/types';

const props = defineProps<{
    channel: WebVoiceChannelDto;
    status: WebVoiceRoomStatus;
    failure: JoinFailure | null;
    joinedHere: boolean;
    participants: WebVoiceParticipantView[];
    micOn: boolean;
    mutedByModerator: boolean;
    needsAudioUnlock: boolean;
    pushToTalk: boolean;
    pttHeld: boolean;
    headsetMode: boolean;
    voiceReady: boolean;
    canModerate: boolean;
    labels: Record<string, string>;
}>();

const emit = defineEmits<{
    join: [];
    leave: [];
    toggleMic: [];
    setPushToTalk: [enabled: boolean];
    setHeadsetMode: [enabled: boolean];
    pttDown: [];
    pttUp: [];
    unlockAudio: [];
    mute: [participant: WebVoiceParticipantView];
    remove: [participant: WebVoiceParticipantView];
}>();

const failureText: Record<JoinFailure, string> = {
    unavailable: 'voice_unavailable',
    denied_mic: 'mic_denied',
    failed: 'connection_lost',
};

// Space is the push-to-talk key. It is ignored while typing or on a focused control, so the
// chat field and buttons keep their normal Space behaviour.
function isTypingTarget(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'].includes(
                target.tagName,
            ))
    );
}

function onKeyDown(event: KeyboardEvent): void {
    if (
        event.code === 'Space' &&
        props.pushToTalk &&
        props.joinedHere &&
        !event.repeat &&
        !isTypingTarget(event.target)
    ) {
        event.preventDefault();
        emit('pttDown');
    }
}

function onKeyUp(event: KeyboardEvent): void {
    if (event.code === 'Space' && props.pushToTalk) {
        emit('pttUp');
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('keyup', onKeyUp);
});
</script>

<template>
    <section
        class="rounded-md border border-border bg-card p-5"
        :aria-label="channel.name"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold tracking-tight">
                    {{ channel.name }}
                </h2>
                <p
                    v-if="channel.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ channel.description }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <LiveIndicator
                    v-if="joinedHere && status === 'connected'"
                    :label="labels.live"
                />
                <LiveIndicator
                    v-else-if="joinedHere && status === 'reconnecting'"
                    variant="warn"
                    :label="labels.joining"
                />
                <Button
                    v-if="!joinedHere"
                    :disabled="!voiceReady || status === 'connecting'"
                    @click="emit('join')"
                >
                    {{ status === 'connecting' ? labels.joining : labels.join }}
                </Button>
                <Button v-else variant="outline" @click="emit('leave')">
                    {{ labels.leave }}
                </Button>
            </div>
        </div>

        <p
            v-if="!voiceReady"
            class="mt-4 rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
        >
            {{ labels.voice_not_ready }}
        </p>

        <div
            v-if="failure && !joinedHere"
            role="alert"
            class="mt-4 rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
        >
            {{ labels[failureText[failure]] }}
        </div>

        <template v-if="joinedHere">
            <div
                v-if="failure"
                role="alert"
                class="mt-4 rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
            >
                {{ labels[failureText[failure]] }}
            </div>

            <div
                v-if="needsAudioUnlock"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-muted/40 p-4 text-sm"
            >
                <span>{{ labels.audio_unlock }}</span>
                <Button size="sm" @click="emit('unlockAudio')">
                    <Volume2 class="size-4" aria-hidden="true" />
                    {{ labels.audio_unlock_action }}
                </Button>
            </div>

            <p
                v-if="mutedByModerator"
                class="mt-4 rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
            >
                {{ labels.muted_by_mod }}
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <Button
                    v-if="!pushToTalk"
                    :variant="micOn ? 'default' : 'outline'"
                    :disabled="mutedByModerator"
                    :aria-pressed="micOn"
                    @click="emit('toggleMic')"
                >
                    <component
                        :is="micOn ? Mic : MicOff"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ micOn ? labels.mic_disable : labels.mic_enable }}
                </Button>

                <Button
                    v-else
                    :variant="pttHeld ? 'default' : 'outline'"
                    :disabled="mutedByModerator"
                    class="touch-none select-none"
                    @pointerdown.prevent="emit('pttDown')"
                    @pointerup="emit('pttUp')"
                    @pointerleave="emit('pttUp')"
                    @pointercancel="emit('pttUp')"
                >
                    <Mic class="size-4" aria-hidden="true" />
                    {{ labels.ptt_hold }}
                </Button>

                <label
                    class="flex items-center gap-2 text-sm pointer-coarse:min-h-10"
                >
                    <input
                        type="checkbox"
                        class="size-4 rounded-sm border-input accent-primary focus-visible:ring-2 focus-visible:ring-ring"
                        :checked="pushToTalk"
                        :disabled="mutedByModerator"
                        @change="
                            emit(
                                'setPushToTalk',
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    <span>{{ labels.ptt }}</span>
                    <span
                        v-if="pushToTalk"
                        class="text-xs text-muted-foreground"
                    >
                        {{ labels.ptt_hint }}
                    </span>
                </label>
            </div>

            <label
                class="mt-3 flex items-start gap-2 text-sm pointer-coarse:min-h-10"
            >
                <input
                    type="checkbox"
                    class="mt-0.5 size-4 shrink-0 rounded-sm border-input accent-primary focus-visible:ring-2 focus-visible:ring-ring"
                    :checked="headsetMode"
                    @change="
                        emit(
                            'setHeadsetMode',
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                <span>
                    {{ labels.headset }}
                    <span class="block text-xs text-muted-foreground">
                        {{ labels.headset_hint }}
                    </span>
                </span>
            </label>

            <h3 class="mt-6 text-sm font-medium text-muted-foreground">
                {{ labels.participants }}
            </h3>
            <ul class="mt-2 space-y-1" aria-live="polite">
                <li
                    v-for="participant in participants"
                    :key="participant.identity"
                    class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm ring-2 transition-shadow motion-reduce:transition-none"
                    :class="
                        participant.speaking
                            ? 'bg-accent/50 ring-live'
                            : 'ring-transparent'
                    "
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <component
                            :is="
                                participant.micOn && participant.canSpeak
                                    ? Mic
                                    : MicOff
                            "
                            class="size-4 shrink-0"
                            :class="
                                participant.speaking
                                    ? 'text-live'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ participant.name }}</span>
                        <span
                            v-if="participant.isLocal"
                            class="text-xs text-muted-foreground"
                            >({{ labels.you }})</span
                        >
                        <span v-if="participant.speaking" class="sr-only">{{
                            labels.speaking
                        }}</span>
                    </span>

                    <span
                        v-if="
                            canModerate &&
                            !participant.isLocal &&
                            participant.userId !== null
                        "
                        class="flex shrink-0 gap-1"
                    >
                        <Button
                            size="icon"
                            variant="ghost"
                            :title="
                                participant.canSpeak
                                    ? labels.mute
                                    : labels.unmute
                            "
                            :aria-label="
                                participant.canSpeak
                                    ? labels.mute
                                    : labels.unmute
                            "
                            @click="emit('mute', participant)"
                        >
                            <VolumeOff class="size-4" aria-hidden="true" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            :title="labels.remove"
                            :aria-label="labels.remove"
                            @click="emit('remove', participant)"
                        >
                            <UserX class="size-4" aria-hidden="true" />
                        </Button>
                    </span>
                </li>
            </ul>
            <p
                v-if="participants.length <= 1"
                class="mt-2 text-sm text-muted-foreground"
            >
                {{ labels.participants_empty }}
            </p>
            <div v-if="$slots.diagnostics" class="mt-6">
                <slot name="diagnostics" />
            </div>
        </template>
    </section>
</template>
