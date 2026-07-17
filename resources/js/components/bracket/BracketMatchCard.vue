<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MatchServerJoin from '@/components/bracket/MatchServerJoin.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    confirm as confirmMatch,
    dispute as disputeMatch,
    go as goLiveMatch,
    report as reportMatch,
} from '@/routes/matches';
import type { BracketMatchDto } from '@/types';

const props = withDefaults(
    defineProps<{
        match: BracketMatchDto;
        /** True when the logged-in user participates in this match (either slot). */
        isParticipant: boolean;
        /** True for a helper/orga viewer — shows the manual "Go" control while the match is in warmup. */
        canGoLive?: boolean;
        matchStatusLabels: Record<string, string>;
        reportLabels: Record<string, string>;
        /** `tournaments.warmup` labels: go_action/waiting. Only needed when `canGoLive` (or the match can reach warmup). */
        warmupLabels?: Record<string, string>;
        serverLabels?: Record<string, string>;
        serverLinkStatusLabels?: Record<string, string>;
        /** `gameservers.live_score` labels (Task 12: CS2 telemetry live score, roadmap 6.9). */
        liveScoreLabels?: Record<string, string>;
    }>(),
    {
        canGoLive: false,
        warmupLabels: () => ({}),
        serverLabels: () => ({}),
        serverLinkStatusLabels: () => ({}),
        liveScoreLabels: () => ({}),
    },
);

const reportForm = useForm({ score1: 0, score2: 0 });
const isReporting = ref(false);

const canReport = computed(
    () => props.isParticipant && props.match.status === 'ready',
);
const canRespond = computed(
    () => props.isParticipant && props.match.status === 'reported',
);
const canTriggerGo = computed(
    () => props.canGoLive && props.match.status === 'warmup',
);
const isGoingLive = ref(false);

function startReport() {
    isReporting.value = true;
}

function submitReport() {
    reportForm.post(reportMatch.url(props.match.id), {
        preserveScroll: true,
        onSuccess: () => {
            isReporting.value = false;
            reportForm.reset();
        },
    });
}

function confirm() {
    useForm({ lock_version: props.match.lockVersion }).post(
        confirmMatch.url(props.match.id),
        { preserveScroll: true },
    );
}

function dispute() {
    useForm({}).post(disputeMatch.url(props.match.id), {
        preserveScroll: true,
    });
}

function goLive() {
    isGoingLive.value = true;
    useForm({}).post(goLiveMatch.url(props.match.id), {
        preserveScroll: true,
        onFinish: () => {
            isGoingLive.value = false;
        },
    });
}

function slotLabel(name: string | null): string {
    return name ?? '—';
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'completed') {
        return 'default';
    }

    if (status === 'disputed') {
        return 'destructive';
    }

    if (status === 'reported') {
        return 'secondary';
    }

    return 'outline';
}

const isLive = computed(
    () => props.match.status === 'ready' || props.match.status === 'reported',
);
const isWarmup = computed(() => props.match.status === 'warmup');

// A CS2 telemetry live score (Task 12, roadmap 6.9) writes score1/score2
// while the match is still `ready` (in progress) — before any report is
// submitted. This is the calm-register cue that the numbers already shown
// below are live and updating, not the final reported result (which only
// ever appears once `status` moves past `ready`).
const hasLiveScore = computed(
    () =>
        props.match.status === 'ready' &&
        (props.match.score1 !== null || props.match.score2 !== null),
);
</script>

<template>
    <div
        class="w-56 rounded-lg border border-border bg-card p-3 shadow-sm"
        :data-match-id="match.id"
    >
        <div class="mb-2 flex items-center justify-between">
            <span class="font-mono text-xs text-muted-foreground tabular-nums"
                >#{{ match.position + 1 }}</span
            >
            <LiveIndicator
                v-if="isLive"
                variant="live"
                :label="matchStatusLabels[match.status]"
            />
            <LiveIndicator
                v-else-if="isWarmup"
                variant="warn"
                :label="matchStatusLabels[match.status]"
            />
            <Badge v-else :variant="statusVariant(match.status)">
                {{ matchStatusLabels[match.status] }}
            </Badge>
        </div>

        <div
            v-if="hasLiveScore"
            class="mb-1 flex items-center gap-1.5 font-mono text-xs text-muted-foreground uppercase tabular-nums"
        >
            <span class="relative inline-flex size-1.5">
                <span
                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-live opacity-75 motion-reduce:animate-none"
                />
                <span
                    class="relative inline-flex size-1.5 rounded-full bg-live"
                />
            </span>
            {{ liveScoreLabels.label }}
        </div>

        <div class="space-y-1">
            <div
                class="flex items-center justify-between rounded px-2 py-1"
                :class="
                    match.winnerEntryId !== null && match.slot1
                        ? 'bg-primary/10 font-medium'
                        : ''
                "
            >
                <span class="truncate text-sm">{{
                    slotLabel(match.slot1)
                }}</span>
                <span class="font-mono text-sm tabular-nums">{{
                    match.score1 ?? ''
                }}</span>
            </div>
            <div
                class="flex items-center justify-between rounded px-2 py-1"
                :class="
                    match.winnerEntryId !== null && match.slot2
                        ? 'bg-primary/10 font-medium'
                        : ''
                "
            >
                <span class="truncate text-sm">{{
                    slotLabel(match.slot2)
                }}</span>
                <span class="font-mono text-sm tabular-nums">{{
                    match.score2 ?? ''
                }}</span>
            </div>
        </div>

        <div v-if="canReport && !isReporting" class="mt-3">
            <Button size="sm" class="w-full" @click="startReport">
                {{ reportLabels.report_action }}
            </Button>
        </div>

        <form
            v-else-if="canReport && isReporting"
            class="mt-3 space-y-2"
            @submit.prevent="submitReport"
        >
            <div class="flex items-center gap-2">
                <Input
                    v-model.number="reportForm.score1"
                    type="number"
                    min="0"
                    class="h-8 font-mono tabular-nums"
                    :aria-label="reportLabels.score1"
                />
                <span class="font-mono text-xs text-muted-foreground">:</span>
                <Input
                    v-model.number="reportForm.score2"
                    type="number"
                    min="0"
                    class="h-8 font-mono tabular-nums"
                    :aria-label="reportLabels.score2"
                />
            </div>
            <Button
                type="submit"
                size="sm"
                class="w-full"
                :disabled="reportForm.processing"
            >
                {{ reportLabels.report_action }}
            </Button>
        </form>

        <div v-else-if="canRespond" class="mt-3 flex gap-2">
            <Button size="sm" class="flex-1" @click="confirm">
                {{ reportLabels.confirm_action }}
            </Button>
            <Button size="sm" variant="outline" class="flex-1" @click="dispute">
                {{ reportLabels.dispute_action }}
            </Button>
        </div>

        <div v-else-if="canTriggerGo" class="mt-3">
            <Button
                size="sm"
                class="w-full"
                :disabled="isGoingLive"
                @click="goLive"
            >
                {{ warmupLabels.go_action }}
            </Button>
        </div>
        <p
            v-else-if="isWarmup"
            class="mt-3 text-center text-xs text-muted-foreground"
        >
            {{ warmupLabels.waiting }}
        </p>

        <MatchServerJoin
            :server="match.server"
            :labels="serverLabels"
            :status-labels="serverLinkStatusLabels"
        />
    </div>
</template>
