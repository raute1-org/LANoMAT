<script setup lang="ts">
import BracketMatchCard from '@/components/bracket/BracketMatchCard.vue';
import type { BracketMatchDto, PendingReportDto } from '@/types';

const props = withDefaults(
    defineProps<{
        title: string;
        matches: BracketMatchDto[];
        myEntryId: number | null;
        canGoLive?: boolean;
        matchStatusLabels: Record<string, string>;
        /** `tournaments.bracket` labels: bye/not_held — BracketMatchCard's never-played-match placeholders. */
        bracketStateLabels?: Record<string, string>;
        reportLabels: Record<string, string>;
        warmupLabels?: Record<string, string>;
        serverLabels?: Record<string, string>;
        serverLinkStatusLabels?: Record<string, string>;
        liveScoreLabels?: Record<string, string>;
        /** The viewer's pending result claims keyed by match id (participants only). */
        pendingReports?: Record<number, PendingReportDto>;
        tournamentFinished?: boolean;
        /** Registers (or unregisters, when `el` is null) a card's DOM element with the parent BracketView for connector measurement. */
        registerCard: (matchId: number, el: Element | null) => void;
    }>(),
    {
        canGoLive: false,
        bracketStateLabels: () => ({}),
        warmupLabels: () => ({}),
        serverLabels: () => ({}),
        serverLinkStatusLabels: () => ({}),
        liveScoreLabels: () => ({}),
        pendingReports: () => ({}),
        tournamentFinished: false,
    },
);

function isParticipant(
    match: BracketMatchDto,
    myEntryId: number | null,
): boolean {
    return (
        myEntryId !== null &&
        (match.entry1Id === myEntryId || match.entry2Id === myEntryId)
    );
}
</script>

<template>
    <div class="flex flex-col items-center gap-6">
        <h3 class="text-sm font-semibold text-muted-foreground">{{ title }}</h3>
        <div class="flex flex-1 flex-col justify-around gap-8">
            <div
                v-for="match in matches"
                :key="match.id"
                :ref="(el) => registerCard(match.id, el as Element | null)"
                :data-bracket-match-id="match.id"
            >
                <BracketMatchCard
                    :match="match"
                    :is-participant="isParticipant(match, myEntryId)"
                    :can-go-live="canGoLive"
                    :match-status-labels="matchStatusLabels"
                    :bracket-state-labels="bracketStateLabels"
                    :report-labels="reportLabels"
                    :warmup-labels="warmupLabels"
                    :server-labels="serverLabels"
                    :server-link-status-labels="serverLinkStatusLabels"
                    :live-score-labels="liveScoreLabels"
                    :pending-report="props.pendingReports[match.id] ?? null"
                    :tournament-finished="props.tournamentFinished"
                />
            </div>
        </div>
    </div>
</template>
