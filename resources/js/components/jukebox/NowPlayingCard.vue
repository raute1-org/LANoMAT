<script setup lang="ts">
/**
 * The jukebox's "on air" signature strip: LiveIndicator + cover art +
 * elapsed/duration progress + the community skip-vote control. Amber is
 * rationed to the LiveIndicator dot/label here — the progress fill and
 * skip-vote count stay graphite/mono, never amber, so the one signal reads
 * clearly against a calm card. See docs/design.md § "The signature:
 * live-state treatment".
 */
import { computed } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { NowPlayingDto } from '@/types';

const props = defineProps<{
    nowPlaying: NowPlayingDto;
    elapsedSeconds: number;
    skipVotes: number;
    skipThreshold: number;
    canParticipate: boolean;
    canModerate: boolean;
    labels: {
        now_playing: string;
        skip_vote: string;
        skip: string;
    };
}>();

const emit = defineEmits<{
    (e: 'skip-vote'): void;
    (e: 'skip'): void;
}>();

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '--:--';
    }

    const clamped = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(clamped / 60);
    const secs = clamped % 60;

    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

const progressPercent = computed(() => {
    if (
        !props.nowPlaying.durationSeconds ||
        props.nowPlaying.durationSeconds <= 0
    ) {
        return 0;
    }

    const ratio = props.elapsedSeconds / props.nowPlaying.durationSeconds;

    return Math.min(100, Math.max(0, ratio * 100));
});
</script>

<template>
    <Card>
        <CardContent class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <img
                v-if="nowPlaying.imageUrl"
                :src="nowPlaying.imageUrl"
                :alt="nowPlaying.title"
                width="72"
                height="72"
                loading="lazy"
                class="h-18 w-18 shrink-0 rounded-md object-cover"
            />
            <div
                v-else
                class="flex h-18 w-18 shrink-0 items-center justify-center rounded-md bg-muted text-2xl font-semibold text-muted-foreground"
                aria-hidden="true"
            >
                ♪
            </div>

            <div class="min-w-0 flex-1">
                <LiveIndicator variant="live" :label="labels.now_playing" />
                <p class="mt-1 truncate text-lg font-semibold text-foreground">
                    {{ nowPlaying.title }}
                </p>
                <p
                    v-if="nowPlaying.artist"
                    class="truncate text-sm text-muted-foreground"
                >
                    {{ nowPlaying.artist }}
                </p>

                <div class="mt-3 flex items-center gap-2">
                    <div
                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="Math.round(progressPercent)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-foreground/40 transition-[width] motion-reduce:transition-none"
                            :style="{ width: `${progressPercent}%` }"
                        />
                    </div>
                    <span
                        class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ formatDuration(elapsedSeconds) }} /
                        {{ formatDuration(nowPlaying.durationSeconds) }}
                    </span>
                </div>
            </div>

            <div
                class="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end"
            >
                <Button
                    v-if="canParticipate"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="emit('skip-vote')"
                >
                    {{ labels.skip_vote }}
                    <span class="ml-1.5 font-mono tabular-nums">
                        {{ skipVotes }}/{{ skipThreshold }}
                    </span>
                </Button>
                <Button
                    v-if="canModerate"
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="emit('skip')"
                >
                    {{ labels.skip }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
