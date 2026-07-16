<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import BracketConnector from '@/components/bracket/BracketConnector.vue';
import BracketRound from '@/components/bracket/BracketRound.vue';
import type { BracketMatchDto } from '@/types';

const props = withDefaults(
    defineProps<{
        matches: BracketMatchDto[];
        myEntryId: number | null;
        matchStatusLabels: Record<string, string>;
        reportLabels: Record<string, string>;
        bracketLabels: Record<string, string>;
        /** Omitted by read-only surfaces (e.g. the infoscreen bracket scene), which never render the server-join action. */
        serverLabels?: Record<string, string>;
        serverLinkStatusLabels?: Record<string, string>;
    }>(),
    {
        serverLabels: () => ({}),
        serverLinkStatusLabels: () => ({}),
    },
);

interface RoundGroup {
    key: string;
    title: string;
    matches: BracketMatchDto[];
}

/**
 * Single elimination has only a `winners` bracket, so the round groups are
 * simply "Runde 1", "Runde 2", ... Double elimination additionally has
 * `losers`/`finals` matches; those are grouped as their own column sets,
 * each labelled with the bracket name plus its round number.
 */
const roundGroups = computed<RoundGroup[]>(() => {
    const bracketTitle: Record<string, string> = {
        winners: props.bracketLabels.winners_bracket,
        losers: props.bracketLabels.losers_bracket,
        finals: props.bracketLabels.finals,
    };

    const byBracket = new Map<string, BracketMatchDto[]>();

    for (const match of props.matches) {
        const list = byBracket.get(match.bracket) ?? [];
        list.push(match);
        byBracket.set(match.bracket, list);
    }

    const groups: RoundGroup[] = [];
    const bracketOrder = ['winners', 'losers', 'finals'];
    const onlyWinners = byBracket.size === 1 && byBracket.has('winners');

    for (const bracket of bracketOrder) {
        const bracketMatches = byBracket.get(bracket);

        if (!bracketMatches) {
            continue;
        }

        const byRound = new Map<number, BracketMatchDto[]>();

        for (const match of bracketMatches) {
            const list = byRound.get(match.round) ?? [];
            list.push(match);
            byRound.set(match.round, list);
        }

        const rounds = [...byRound.keys()].sort((a, b) => a - b);

        for (const round of rounds) {
            const roundLabel = props.bracketLabels.round.replace(
                ':number',
                String(round),
            );
            const title = onlyWinners
                ? roundLabel
                : `${bracketTitle[bracket] ?? bracket} — ${roundLabel}`;

            groups.push({
                key: `${bracket}-${round}`,
                title,
                matches: (byRound.get(round) ?? []).sort(
                    (a, b) => a.position - b.position,
                ),
            });
        }
    }

    return groups;
});

const containerRef = ref<HTMLElement | null>(null);
const cardRefs = new Map<number, HTMLElement>();
const connectorSize = ref({ width: 0, height: 0 });
const connectorPaths = ref<string[]>([]);
let resizeObserver: ResizeObserver | null = null;
let scheduled = false;

function registerCard(matchId: number, el: Element | null) {
    if (el instanceof HTMLElement) {
        cardRefs.set(matchId, el);
        resizeObserver?.observe(el);
    } else {
        cardRefs.delete(matchId);
    }

    scheduleRecompute();
}

/**
 * Batches recompute calls triggered during render (one per card ref) into a
 * single measurement pass on the next tick, instead of measuring after every
 * individual card mounts.
 */
function scheduleRecompute() {
    if (scheduled) {
        return;
    }

    scheduled = true;
    void nextTick(() => {
        scheduled = false;
        recomputeConnectors();
    });
}

function recomputeConnectors() {
    const container = containerRef.value;

    if (!container) {
        return;
    }

    const containerBox = container.getBoundingClientRect();
    connectorSize.value = {
        width: containerBox.width,
        height: containerBox.height,
    };

    const paths: string[] = [];

    for (const match of props.matches) {
        if (match.nextMatchId === null) {
            continue;
        }

        const fromEl = cardRefs.get(match.id);
        const toEl = cardRefs.get(match.nextMatchId);

        if (!fromEl || !toEl) {
            continue;
        }

        const fromBox = fromEl.getBoundingClientRect();
        const toBox = toEl.getBoundingClientRect();

        const startX = fromBox.right - containerBox.left;
        const startY = fromBox.top - containerBox.top + fromBox.height / 2;
        const endX = toBox.left - containerBox.left;
        const endY = toBox.top - containerBox.top + toBox.height / 2;
        const midX = startX + (endX - startX) / 2;

        // Cubic bezier "elbow" connecting the source card's right edge to
        // the target card's left edge, curving through the horizontal
        // midpoint so lines never overlap the cards between columns.
        paths.push(
            `M ${startX} ${startY} C ${midX} ${startY}, ${midX} ${endY}, ${endX} ${endY}`,
        );
    }

    connectorPaths.value = paths;
}

onMounted(() => {
    resizeObserver = new ResizeObserver(() => recomputeConnectors());

    if (containerRef.value) {
        resizeObserver.observe(containerRef.value);
    }

    for (const el of cardRefs.values()) {
        resizeObserver.observe(el);
    }

    scheduleRecompute();
});

onUnmounted(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
});
</script>

<template>
    <div ref="containerRef" class="relative overflow-x-auto">
        <BracketConnector
            :width="connectorSize.width"
            :height="connectorSize.height"
            :paths="connectorPaths"
        />

        <div class="relative flex items-stretch gap-12 p-6">
            <BracketRound
                v-for="group in roundGroups"
                :key="group.key"
                :title="group.title"
                :matches="group.matches"
                :my-entry-id="myEntryId"
                :match-status-labels="matchStatusLabels"
                :report-labels="reportLabels"
                :server-labels="serverLabels"
                :server-link-status-labels="serverLinkStatusLabels"
                :register-card="registerCard"
            />
        </div>
    </div>
</template>
