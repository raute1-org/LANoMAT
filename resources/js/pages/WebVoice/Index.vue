<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import ChannelChat from '@/components/webvoice/ChannelChat.vue';
import ChannelList from '@/components/webvoice/ChannelList.vue';
import ConnectionDiagnostics from '@/components/webvoice/ConnectionDiagnostics.vue';
import LatencyTest from '@/components/webvoice/LatencyTest.vue';
import VoiceRoomPanel from '@/components/webvoice/VoiceRoomPanel.vue';
import { useConnectionStats } from '@/composables/useConnectionStats';
import { useLatencyTest } from '@/composables/useLatencyTest';
import { useWebVoiceRoom } from '@/composables/useWebVoiceRoom';
import { webVoiceRequest, webVoiceUrls } from '@/lib/webVoiceHttp';
import type { WebVoiceChannelDto, WebVoiceParticipantView } from '@/types';

const props = defineProps<{
    channels: WebVoiceChannelDto[];
    me: { id: number; name: string; identity: string };
    canModerate: boolean;
    canMeasure: boolean;
    voiceReady: boolean;
    chat: { maxLength: number; history: number };
    labels: Record<string, string>;
}>();

const room = useWebVoiceRoom();

// Orga and admin only: live connection numbers (polled only while the panel is open) and the
// latency self-test. The jitter buffer choices are what the test can compare; '' = browser default.
const diagnosticsOpen = ref(false);
const diagnostics = useConnectionStats(room.room, diagnosticsOpen);
const latencyTest = useLatencyTest();
const jitterTarget = ref('');
const jitterTargets = ['0', '10', '20', '40', '80'];

function runLatencyTest(): void {
    void latencyTest.run({
        tuning: room.tuning.value,
        jitterBufferTargetMs:
            jitterTarget.value === '' ? null : Number(jitterTarget.value),
    });
}
const selectedId = ref<number | null>(props.channels[0]?.id ?? null);

const selected = computed<WebVoiceChannelDto | null>(
    () =>
        props.channels.find((channel) => channel.id === selectedId.value) ??
        null,
);

// A channel can disappear (a match room closes, an admin archives one): fall back to a
// remaining channel and leave the voice room if it was that one.
watch(
    () => props.channels,
    async (channels) => {
        if (
            room.joinedChannelId.value !== null &&
            !channels.some(
                (channel) => channel.id === room.joinedChannelId.value,
            )
        ) {
            await room.leave();
        }

        if (!channels.some((channel) => channel.id === selectedId.value)) {
            selectedId.value = channels[0]?.id ?? null;
        }
    },
);

// The directory ping carries no data: it only says "pull your own list again".
onMounted(() => {
    echo()
        .private('web-voice.directory')
        .listen('.channels.changed', () => {
            router.reload({ only: ['channels'] });
        });
});

onUnmounted(() => {
    echo().leave('web-voice.directory');
});

async function moderate(
    action: 'mute' | 'remove',
    participant: WebVoiceParticipantView,
): Promise<void> {
    const channelId = room.joinedChannelId.value;

    if (channelId === null || participant.userId === null) {
        return;
    }

    try {
        if (action === 'remove') {
            await webVoiceRequest(
                'POST',
                webVoiceUrls.remove(channelId, participant.userId),
            );
        } else {
            await webVoiceRequest(
                participant.canSpeak ? 'POST' : 'DELETE',
                webVoiceUrls.mute(channelId, participant.userId),
            );
        }
    } catch {
        // The room list refreshes on its own; a failed moderation call leaves the state unchanged.
    }
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="wide"
        :title="labels.heading"
        :description="labels.lead"
    >
        <!-- empty -->
        <p
            v-if="channels.length === 0"
            class="mt-8 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
        >
            {{ labels.no_channels }}
        </p>

        <div
            v-else
            class="mt-8 grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start"
        >
            <ChannelList
                :channels="channels"
                :selected-id="selectedId"
                :joined-id="room.joinedChannelId.value"
                :labels="labels"
                @select="(id) => (selectedId = id)"
            />

            <div v-if="selected" class="space-y-6">
                <VoiceRoomPanel
                    :channel="selected"
                    :status="room.status.value"
                    :failure="room.failure.value"
                    :joined-here="room.joinedChannelId.value === selected.id"
                    :participants="room.participants.value"
                    :mic-on="room.micOn.value"
                    :muted-by-moderator="room.mutedByModerator.value"
                    :needs-audio-unlock="room.needsAudioUnlock.value"
                    :push-to-talk="room.pushToTalk.value"
                    :ptt-held="room.pttHeld.value"
                    :headset-mode="room.tuning.value.headsetMode"
                    :voice-ready="voiceReady"
                    :can-moderate="canModerate"
                    :labels="labels"
                    @join="room.join(selected.id)"
                    @leave="room.leave()"
                    @toggle-mic="room.toggleMic()"
                    @set-push-to-talk="(on) => room.setPushToTalk(on)"
                    @set-headset-mode="(on) => room.setHeadsetMode(on)"
                    @ptt-down="room.pttDown()"
                    @ptt-up="room.pttUp()"
                    @unlock-audio="room.unlockAudio()"
                    @mute="(p) => moderate('mute', p)"
                    @remove="(p) => moderate('remove', p)"
                >
                    <template v-if="canMeasure" #diagnostics>
                        <ConnectionDiagnostics
                            v-model:open="diagnosticsOpen"
                            :state="diagnostics.state.value"
                            :send="diagnostics.send.value"
                            :receive="diagnostics.receive.value"
                            :labels="labels"
                        />
                    </template>
                </VoiceRoomPanel>

                <LatencyTest
                    v-if="canMeasure"
                    v-model:jitter-target="jitterTarget"
                    :phase="latencyTest.phase.value"
                    :failure="latencyTest.failure.value"
                    :progress="latencyTest.progress.value"
                    :result="latencyTest.result.value"
                    :targets="jitterTargets"
                    :voice-ready="voiceReady"
                    :labels="labels"
                    @run="runLatencyTest"
                    @cancel="latencyTest.cancel()"
                />

                <ChannelChat
                    :key="selected.id"
                    :channel-id="selected.id"
                    :max-length="chat.maxLength"
                    :page-size="chat.history"
                    :can-moderate="canModerate"
                    :my-id="me.id"
                    :labels="labels"
                />
            </div>

            <p
                v-else
                class="rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
            >
                {{ labels.select }}
            </p>
        </div>
    </PageContainer>
</template>
