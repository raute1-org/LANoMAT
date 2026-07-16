<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    confirm as confirmMatch,
    dispute as disputeMatch,
    report as reportMatch,
} from '@/routes/matches';
import type { BracketMatchDto } from '@/types';

const props = defineProps<{
    match: BracketMatchDto;
    /** True when the logged-in user participates in this match (either slot). */
    isParticipant: boolean;
    matchStatusLabels: Record<string, string>;
    reportLabels: Record<string, string>;
}>();

const reportForm = useForm({ score1: 0, score2: 0 });
const isReporting = ref(false);

const canReport = computed(
    () => props.isParticipant && props.match.status === 'ready',
);
const canRespond = computed(
    () => props.isParticipant && props.match.status === 'reported',
);

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
            <Badge v-else :variant="statusVariant(match.status)">
                {{ matchStatusLabels[match.status] }}
            </Badge>
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
    </div>
</template>
