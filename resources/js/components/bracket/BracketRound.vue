<script setup lang="ts">
import BracketMatchCard from '@/components/bracket/BracketMatchCard.vue';
import type { BracketMatchDto } from '@/types';

withDefaults(
    defineProps<{
        title: string;
        matches: BracketMatchDto[];
        myEntryId: number | null;
        canGoLive?: boolean;
        matchStatusLabels: Record<string, string>;
        reportLabels: Record<string, string>;
        warmupLabels?: Record<string, string>;
        serverLabels?: Record<string, string>;
        serverLinkStatusLabels?: Record<string, string>;
        /** Registers (or unregisters, when `el` is null) a card's DOM element with the parent BracketView for connector measurement. */
        registerCard: (matchId: number, el: Element | null) => void;
    }>(),
    {
        canGoLive: false,
        warmupLabels: () => ({}),
        serverLabels: () => ({}),
        serverLinkStatusLabels: () => ({}),
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
                    :report-labels="reportLabels"
                    :warmup-labels="warmupLabels"
                    :server-labels="serverLabels"
                    :server-link-status-labels="serverLinkStatusLabels"
                />
            </div>
        </div>
    </div>
</template>
