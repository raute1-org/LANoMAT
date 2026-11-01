<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MatchServerJoin from '@/components/bracket/MatchServerJoin.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatInstant } from '@/lib/datetime';
import {
    confirm as confirmMatch,
    dispute as disputeMatch,
    go as goLiveMatch,
    report as reportMatch,
} from '@/routes/matches';
import {
    correct as correctReport,
    withdraw as withdrawReport,
} from '@/routes/matches/report';
import type { BracketMatchDto, PendingReportDto } from '@/types';

const props = withDefaults(
    defineProps<{
        match: BracketMatchDto;
        /** True when the logged-in user participates in this match (either slot). */
        isParticipant: boolean;
        /** True for a helper/orga viewer — shows the manual "Go" control while the match is in warmup. */
        canGoLive?: boolean;
        matchStatusLabels: Record<string, string>;
        /** `tournaments.bracket` labels: bye/not_held — the never-played-match placeholders. */
        bracketStateLabels?: Record<string, string>;
        reportLabels: Record<string, string>;
        /** `tournaments.warmup` labels: go_action/waiting. Only needed when `canGoLive` (or the match can reach warmup). */
        warmupLabels?: Record<string, string>;
        serverLabels?: Record<string, string>;
        serverLinkStatusLabels?: Record<string, string>;
        /** `gameservers.live_score` labels (Task 12: CS2 telemetry live score, roadmap 6.9). */
        liveScoreLabels?: Record<string, string>;
        /** The viewer's own pending result claim for this match, "own : opponent" (null for spectators). */
        pendingReport?: PendingReportDto | null;
        /** True once the whole tournament is over: a never-decided match (the unused grand-final reset) is then "Entfällt". */
        tournamentFinished?: boolean;
    }>(),
    {
        canGoLive: false,
        pendingReport: null,
        tournamentFinished: false,
        bracketStateLabels: () => ({}),
        warmupLabels: () => ({}),
        serverLabels: () => ({}),
        serverLinkStatusLabels: () => ({}),
        liveScoreLabels: () => ({}),
    },
);

const reportForm = useForm({ score1: 0, score2: 0 });
const isReporting = ref(false);

// The reporter may still change or take back the claim while it is pending.
const correctForm = useForm({ score1: 0, score2: 0, lock_version: 0 });
const isCorrecting = ref(false);

const canReport = computed(
    () =>
        props.isParticipant &&
        (props.match.status === 'ready' || props.match.status === 'live'),
);
const canRespond = computed(
    () =>
        props.isParticipant &&
        props.match.status === 'reported' &&
        props.pendingReport?.reportedByMe !== true,
);
// The reporter waits for the opponent: no confirm/dispute buttons (the
// server would answer 403), just a calm status line.
const isAwaitingConfirmation = computed(
    () =>
        props.isParticipant &&
        props.match.status === 'reported' &&
        props.pendingReport?.reportedByMe === true,
);
// A match that was never going to be played: the unused grand-final reset
// once the tournament is finished (like a dead losers-bracket slot).
const isNotHeld = computed(
    () => props.tournamentFinished && props.match.status === 'pending',
);
// Byes and phantoms carry no result; never print scores for them.
const showScores = computed(
    () => !props.match.isBye && !isPhantom.value && !isNotHeld.value,
);
const canTriggerGo = computed(
    () => props.canGoLive && props.match.status === 'warmup',
);
const isGoingLive = ref(false);

function startReport() {
    isReporting.value = true;
}

function submitReport() {
    reportForm.clearErrors();
    reportForm.post(reportMatch.url(props.match.id), {
        preserveScroll: true,
        onSuccess: () => {
            isReporting.value = false;
            reportForm.reset();
        },
    });
}

function startCorrection() {
    correctForm.clearErrors();
    correctForm.score1 = props.pendingReport?.ownScore ?? 0;
    correctForm.score2 = props.pendingReport?.opponentScore ?? 0;
    isCorrecting.value = true;
}

function submitCorrection() {
    correctForm.clearErrors();
    correctForm.lock_version = props.match.lockVersion;
    correctForm.post(correctReport.url(props.match.id), {
        preserveScroll: true,
        onSuccess: () => {
            isCorrecting.value = false;
        },
    });
}

function withdrawPendingReport() {
    useForm({ lock_version: props.match.lockVersion }).post(
        withdrawReport.url(props.match.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                isCorrecting.value = false;
            },
        },
    );
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

/**
 * A phantom losers-bracket match: both winners-bracket feeders were byes, so
 * neither slot was ever filled and no result was ever played (`isBye` is
 * deliberately `false` for this shape — see `BracketMatchProjection`). Kept
 * as its own named check, distinct from a bye (exactly one slot null), so a
 * later reader cannot conflate the two never-played-match states.
 */
const isPhantom = computed(
    () =>
        props.match.status === 'completed' &&
        props.match.slot1 === null &&
        props.match.slot2 === null,
);

function slotLabel(name: string | null): string {
    if (name !== null) {
        return name;
    }

    if (isPhantom.value) {
        return props.bracketStateLabels.not_held ?? '—';
    }

    return props.match.isBye ? (props.bracketStateLabels.bye ?? '—') : '—';
}

/**
 * True when an empty slot is a calm-register placeholder (bye or phantom)
 * rather than a genuinely unfilled slot still waiting on an earlier match —
 * gates the muted treatment on the slot row.
 */
function isPlaceholderSlot(name: string | null): boolean {
    return name === null && (props.match.isBye || isPhantom.value);
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
    () => props.match.status === 'live' || props.match.status === 'reported',
);
const isWarmup = computed(() => props.match.status === 'warmup');

// A CS2 telemetry live score (Task 12, roadmap 6.9) writes score1/score2
// while the match is `live` (in progress) — before any report is
// submitted. This is the calm-register cue that the numbers already shown
// below are live and updating, not the final reported result (which only
// ever appears once `status` moves past `live`).
const hasLiveScore = computed(
    () =>
        props.match.status === 'live' &&
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
                >#{{ match.position }}</span
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
            <Badge v-else-if="isNotHeld" variant="outline">
                {{ bracketStateLabels.not_held }}
            </Badge>
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
                <span
                    class="truncate text-sm"
                    :class="
                        isPlaceholderSlot(match.slot1)
                            ? 'text-muted-foreground'
                            : ''
                    "
                    >{{ slotLabel(match.slot1) }}</span
                >
                <span class="font-mono text-sm tabular-nums">{{
                    showScores ? (match.score1 ?? '') : ''
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
                <span
                    class="truncate text-sm"
                    :class="
                        isPlaceholderSlot(match.slot2)
                            ? 'text-muted-foreground'
                            : ''
                    "
                    >{{ slotLabel(match.slot2) }}</span
                >
                <span class="font-mono text-sm tabular-nums">{{
                    showScores ? (match.score2 ?? '') : ''
                }}</span>
            </div>
        </div>

        <div
            v-if="pendingReport && match.status === 'reported'"
            class="mt-3 rounded-md border border-border bg-muted/40 px-2 py-1.5 text-center"
            data-testid="pending-report"
        >
            <p class="text-xs text-muted-foreground">
                {{ reportLabels.pending_title }}
            </p>
            <p class="font-mono text-sm tabular-nums">
                {{ pendingReport.ownScore }} : {{ pendingReport.opponentScore }}
                <span class="text-xs text-muted-foreground"
                    >({{ reportLabels.own_label }} :
                    {{ reportLabels.opponent_label }})</span
                >
            </p>
            <p
                v-if="pendingReport.reporterName"
                class="text-xs text-muted-foreground"
            >
                {{
                    reportLabels.reported_by.replace(
                        ':name',
                        pendingReport.reporterName,
                    )
                }}
                <time
                    v-if="pendingReport.reportedAt"
                    :datetime="pendingReport.reportedAt"
                    >·
                    {{
                        formatInstant(pendingReport.reportedAt, {
                            hour: '2-digit',
                            minute: '2-digit',
                        })
                    }}</time
                >
            </p>
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
            <p
                v-if="reportForm.errors.score1 || reportForm.errors.score2"
                class="text-xs text-destructive"
                role="alert"
            >
                {{ reportForm.errors.score1 ?? reportForm.errors.score2 }}
            </p>
            <Button
                type="submit"
                size="sm"
                class="w-full"
                :disabled="reportForm.processing"
            >
                {{ reportLabels.report_action }}
            </Button>
        </form>

        <div v-else-if="canRespond" class="mt-3 flex flex-wrap gap-2">
            <Button size="sm" class="grow" @click="confirm">
                {{ reportLabels.confirm_action }}
            </Button>
            <Button size="sm" variant="outline" class="grow" @click="dispute">
                {{ reportLabels.dispute_action }}
            </Button>
        </div>

        <div v-else-if="isAwaitingConfirmation" class="mt-3 space-y-2">
            <p class="text-center text-xs text-muted-foreground">
                {{
                    reportLabels.awaiting.replace(
                        ':name',
                        pendingReport?.opponentName ?? '',
                    )
                }}
            </p>
            <form
                v-if="isCorrecting"
                class="space-y-2"
                @submit.prevent="submitCorrection"
            >
                <div class="flex items-center gap-2">
                    <Input
                        v-model.number="correctForm.score1"
                        type="number"
                        min="0"
                        class="h-8 font-mono tabular-nums"
                        :aria-label="reportLabels.score1"
                    />
                    <span class="font-mono text-xs text-muted-foreground"
                        >:</span
                    >
                    <Input
                        v-model.number="correctForm.score2"
                        type="number"
                        min="0"
                        class="h-8 font-mono tabular-nums"
                        :aria-label="reportLabels.score2"
                    />
                </div>
                <p
                    v-if="
                        correctForm.errors.score1 || correctForm.errors.score2
                    "
                    class="text-xs text-destructive"
                    role="alert"
                >
                    {{ correctForm.errors.score1 ?? correctForm.errors.score2 }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        class="grow"
                        :disabled="correctForm.processing"
                    >
                        {{ reportLabels.save_change_action }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="isCorrecting = false"
                    >
                        {{ reportLabels.cancel_action }}
                    </Button>
                </div>
            </form>
            <div v-else class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="grow"
                    @click="startCorrection"
                >
                    {{ reportLabels.change_action }}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    class="grow"
                    @click="withdrawPendingReport"
                >
                    {{ reportLabels.withdraw_action }}
                </Button>
            </div>
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
            :spectate-hint="match.spectateHint"
            :labels="serverLabels"
            :status-labels="serverLinkStatusLabels"
        />
    </div>
</template>
