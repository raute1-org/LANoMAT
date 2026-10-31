<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import BracketConnector from '@/components/bracket/BracketConnector.vue';
import BracketRound from '@/components/bracket/BracketRound.vue';
import type { BracketMatchDto, PendingReportDto } from '@/types';

const props = withDefaults(
    defineProps<{
        matches: BracketMatchDto[];
        myEntryId: number | null;
        /** True for a helper/orga viewer — gates the match card's manual "Go" control. Omitted by read-only surfaces. */
        canGoLive?: boolean;
        matchStatusLabels: Record<string, string>;
        /** `tournaments.bracket` labels: bye/not_held — BracketMatchCard's never-played-match placeholders. */
        bracketStateLabels?: Record<string, string>;
        reportLabels: Record<string, string>;
        /** Omitted by read-only surfaces (e.g. the infoscreen bracket scene), which never render the "Go" control. */
        warmupLabels?: Record<string, string>;
        bracketLabels: Record<string, string>;
        /** Omitted by read-only surfaces (e.g. the infoscreen bracket scene), which never render the server-join action. */
        serverLabels?: Record<string, string>;
        serverLinkStatusLabels?: Record<string, string>;
        liveScoreLabels?: Record<string, string>;
        /** The viewer's pending result claims keyed by match id (participants only). */
        pendingReports?: Record<number, PendingReportDto>;
        tournamentFinished?: boolean;
        /**
         * Beamer/overlay mode: the bracket is fitted to its parent box with a
         * CSS scale (no scrollbars). The parent must give this component a
         * definite size (`h-full w-full`). Brackets too big to stay legible
         * are windowed, see `useBracketFit`-style logic below.
         */
        fit?: boolean;
        /** Seconds each window of a multi-window fitted bracket stays up; 0 keeps the first one. */
        pageSeconds?: number;
        /** `hidden` (early rounds are not shown) and `page` (":current of :total") hints for fit mode. */
        fitLabels?: Record<string, string>;
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
        fit: false,
        pageSeconds: 0,
        fitLabels: () => ({}),
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

// ---- Fit mode (beamer scenes and OBS overlays) ---------------------------
//
// Legibility floor: bracket text is 14 px (`text-sm`) at scale 1. On a
// 1920x1080 beamer a bracket is never rendered below scale 1.15 (names about
// 16 px, status labels about 14 px); the floor shrinks proportionally on
// smaller viewports. Small brackets scale UP (to MAX_SCALE) so the stage is
// used. A bracket that cannot fit at the floor is windowed instead of being
// shrunk into illegibility: columns (rounds) with too many matches are left
// out (always the early rounds) and the remaining columns are split into
// windows that rotate; the window holding the current round starts.
const MIN_SCALE_1080P = 1.15;
const MAX_SCALE = 2.5;
const COLUMN_WIDTH = 224;
const COLUMN_GAP = 48;
const PADDING = 24;
const COLUMN_HEADER = 44;
const MATCH_GAP = 32;
const HINT_HEIGHT = 44;

const viewportRef = ref<HTMLElement | null>(null);
const available = ref({ width: 0, height: 0 });
const natural = ref({ width: 0, height: 0 });
const cardHeight = ref(116);
const pageIndex = ref(0);
let pageTimer: ReturnType<typeof setInterval> | null = null;

const minScale = computed(() => {
    const { width, height } = available.value;

    if (width === 0 || height === 0) {
        return MIN_SCALE_1080P;
    }

    return MIN_SCALE_1080P * Math.min(width / 1920, height / 1080);
});

function isFinishedGroup(group: RoundGroup): boolean {
    return group.matches.every(
        (match) =>
            match.status === 'completed' ||
            (match.entry1Id === null && match.entry2Id === null),
    );
}

const fitPages = computed<RoundGroup[][]>(() => {
    const groups = roundGroups.value;

    if (!props.fit || available.value.width === 0) {
        return [groups];
    }

    const reserve = HINT_HEIGHT;
    const maxHeight = (available.value.height - reserve) / minScale.value;
    const maxWidth = available.value.width / minScale.value;

    const heightFor = (matchCount: number) =>
        PADDING * 2 +
        COLUMN_HEADER +
        matchCount * cardHeight.value +
        Math.max(matchCount - 1, 0) * MATCH_GAP;

    let usable = groups.filter(
        (group) => heightFor(group.matches.length) <= maxHeight,
    );

    if (usable.length === 0) {
        // Nothing fits at the floor (tiny viewport): show the last round.
        usable = groups.slice(-1);
    }

    const perPage = Math.max(
        Math.floor(
            (maxWidth - PADDING * 2 + COLUMN_GAP) / (COLUMN_WIDTH + COLUMN_GAP),
        ),
        1,
    );

    const pages: RoundGroup[][] = [];

    for (let i = 0; i < usable.length; i += perPage) {
        pages.push(usable.slice(i, i + perPage));
    }

    return pages.length > 0 ? pages : [groups];
});

const currentPage = computed(() =>
    Math.min(pageIndex.value, fitPages.value.length - 1),
);

const visibleGroups = computed<RoundGroup[]>(
    () => fitPages.value[currentPage.value] ?? [],
);

const hiddenEarlyRounds = computed(
    () =>
        props.fit &&
        visibleGroups.value.length > 0 &&
        fitPages.value.flat().length < roundGroups.value.length,
);

const showHint = computed(
    () => props.fit && (hiddenEarlyRounds.value || fitPages.value.length > 1),
);

/** The window that holds the first round that is still being played. */
function startPage(): number {
    const groups = roundGroups.value;
    const pages = fitPages.value;
    const currentIdx = groups.findIndex((group) => !isFinishedGroup(group));

    if (currentIdx < 0) {
        return pages.length - 1;
    }

    for (let i = 0; i < pages.length; i++) {
        const lastInPage = pages[i][pages[i].length - 1];

        if (groups.indexOf(lastInPage) >= currentIdx) {
            return i;
        }
    }

    return pages.length - 1;
}

const pagesSignature = computed(() =>
    fitPages.value.map((page) => page.map((g) => g.key).join('+')).join('|'),
);

watch(
    pagesSignature,
    () => {
        pageIndex.value = startPage();
    },
    { immediate: true },
);

watch(
    () => [props.fit, props.pageSeconds, fitPages.value.length] as const,
    () => {
        if (pageTimer !== null) {
            clearInterval(pageTimer);
            pageTimer = null;
        }

        if (props.fit && props.pageSeconds > 0 && fitPages.value.length > 1) {
            pageTimer = setInterval(() => {
                pageIndex.value =
                    (currentPage.value + 1) % fitPages.value.length;
            }, props.pageSeconds * 1000);
        }
    },
    { immediate: true },
);

const scale = computed(() => {
    if (!props.fit || natural.value.width === 0 || natural.value.height === 0) {
        return 1;
    }

    const height = available.value.height - (showHint.value ? HINT_HEIGHT : 0);

    return Math.min(
        available.value.width / natural.value.width,
        height / natural.value.height,
        MAX_SCALE,
    );
});

const fitStyle = computed(() => {
    if (!props.fit) {
        return undefined;
    }

    const height = available.value.height - (showHint.value ? HINT_HEIGHT : 0);
    const left = Math.max(
        (available.value.width - natural.value.width * scale.value) / 2,
        0,
    );
    const top = Math.max((height - natural.value.height * scale.value) / 2, 0);

    return {
        transform: `translate(${left}px, ${top}px) scale(${scale.value})`,
    };
});

const hintText = computed(() => {
    const parts: string[] = [];

    if (hiddenEarlyRounds.value && props.fitLabels.hidden) {
        parts.push(props.fitLabels.hidden);
    }

    if (fitPages.value.length > 1 && props.fitLabels.page) {
        parts.push(
            props.fitLabels.page
                .replace(':current', String(currentPage.value + 1))
                .replace(':total', String(fitPages.value.length)),
        );
    }

    return parts.join(' · ');
});

function measureFit() {
    if (!props.fit) {
        return;
    }

    const viewport = viewportRef.value;
    const container = containerRef.value;

    if (viewport) {
        available.value = {
            width: viewport.clientWidth,
            height: viewport.clientHeight,
        };
    }

    if (container) {
        natural.value = {
            width: container.offsetWidth,
            height: container.offsetHeight,
        };
    }

    let tallest = 0;

    for (const el of cardRefs.values()) {
        tallest = Math.max(tallest, el.offsetHeight);
    }

    if (tallest > 0 && tallest !== cardHeight.value) {
        cardHeight.value = tallest;
    }
}

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
        measureFit();
        recomputeConnectors();
    });
}

function recomputeConnectors() {
    const container = containerRef.value;

    if (!container) {
        return;
    }

    const containerBox = container.getBoundingClientRect();
    // An ancestor transform (fit mode) scales every client rect; divide by
    // the factor so the connector paths stay in the container's own units.
    const ratio =
        container.offsetWidth > 0
            ? containerBox.width / container.offsetWidth
            : 1;
    const unscale = (value: number) => value / ratio;

    connectorSize.value = {
        width: container.offsetWidth,
        height: container.offsetHeight,
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

        const startX = unscale(fromBox.right - containerBox.left);
        const startY = unscale(
            fromBox.top - containerBox.top + fromBox.height / 2,
        );
        const endX = unscale(toBox.left - containerBox.left);
        const endY = unscale(toBox.top - containerBox.top + toBox.height / 2);
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
    resizeObserver = new ResizeObserver(() => {
        measureFit();
        recomputeConnectors();
    });

    if (containerRef.value) {
        resizeObserver.observe(containerRef.value);
    }

    if (viewportRef.value && props.fit) {
        resizeObserver.observe(viewportRef.value);
    }

    measureFit();

    for (const el of cardRefs.values()) {
        resizeObserver.observe(el);
    }

    scheduleRecompute();
});

onUnmounted(() => {
    if (pageTimer !== null) {
        clearInterval(pageTimer);
    }

    resizeObserver?.disconnect();
    resizeObserver = null;
});
</script>

<template>
    <div
        ref="viewportRef"
        :class="fit ? 'relative h-full w-full overflow-hidden' : ''"
    >
        <div
            ref="containerRef"
            :class="
                fit
                    ? 'absolute top-0 left-0 w-max origin-top-left'
                    : 'relative overflow-x-auto'
            "
            :style="fitStyle"
        >
            <BracketConnector
                :width="connectorSize.width"
                :height="connectorSize.height"
                :paths="connectorPaths"
            />

            <div class="relative flex items-stretch gap-12 p-6">
                <BracketRound
                    v-for="group in visibleGroups"
                    :key="group.key"
                    :title="group.title"
                    :matches="group.matches"
                    :my-entry-id="myEntryId"
                    :can-go-live="canGoLive"
                    :match-status-labels="matchStatusLabels"
                    :bracket-state-labels="bracketStateLabels"
                    :report-labels="reportLabels"
                    :warmup-labels="warmupLabels"
                    :server-labels="serverLabels"
                    :server-link-status-labels="serverLinkStatusLabels"
                    :live-score-labels="liveScoreLabels"
                    :pending-reports="pendingReports"
                    :tournament-finished="tournamentFinished"
                    :register-card="registerCard"
                />
            </div>
        </div>

        <p
            v-if="showHint && hintText"
            class="absolute right-4 bottom-2 font-mono text-lg text-muted-foreground"
        >
            {{ hintText }}
        </p>
    </div>
</template>
