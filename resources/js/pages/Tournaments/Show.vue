<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import BracketView from '@/components/bracket/BracketView.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import { useTournamentChannel } from '@/composables/useTournamentChannel';
import { index as tournamentsIndex } from '@/routes/tournaments';
import type {
    BracketMatchDto,
    MatchVoiceLinks,
    TournamentDetail,
} from '@/types';

const props = defineProps<{
    tournament: TournamentDetail;
    matches: BracketMatchDto[];
    myEntryId: number | null;
    myMatchVoiceLinks: MatchVoiceLinks;
    /** True for a helper/orga viewer — gates the match card's manual "Go" control. */
    canGoLive: boolean;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
    matchStatusLabels: Record<string, string>;
    reportLabels: Record<string, string>;
    warmupLabels: Record<string, string>;
    serverLabels: Record<string, string>;
    serverLinkStatusLabels: Record<string, string>;
    /** `gameservers.live_score` labels (Task 12: CS2 telemetry live score, roadmap 6.9). */
    liveScoreLabels: Record<string, string>;
    /** `voice.join` labels for the multi-provider voice-link cluster. */
    voiceLabels: Record<string, string>;
}>();

useTournamentChannel(props.tournament.id);

/** Default provider first so the rationed amber action always leads the cluster. */
const voiceLinks = computed(() =>
    [...props.myMatchVoiceLinks].sort(
        (a, b) => Number(b.isDefault) - Number(a.isDefault),
    ),
);

const isLive = computed(() => props.tournament.status === 'live');
const isFinished = computed(() => props.tournament.status === 'finished');
const isNotStarted = computed(
    () =>
        props.tournament.status === 'draft' ||
        props.tournament.status === 'enrollment' ||
        props.tournament.status === 'check_in',
);
</script>

<template>
    <Head :title="`${labels.title} — ${tournament.name}`" />

    <main class="mx-auto max-w-6xl px-4 py-12">
        <a
            :href="tournamentsIndex.url(tournament.event.slug)"
            class="text-sm text-muted-foreground hover:underline"
        >
            &larr; {{ labels.back_to_index }}
        </a>

        <div class="mt-2 flex items-center gap-3">
            <h1 class="text-3xl font-bold tracking-tight">
                {{ tournament.name }}
            </h1>
            <LiveIndicator
                v-if="isLive"
                variant="live"
                :label="statusLabels[tournament.status]"
            />
        </div>
        <p class="text-sm text-muted-foreground">
            {{ tournament.event.name }}
        </p>

        <div v-if="voiceLinks.length" class="mt-4">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ voiceLabels.heading }}
            </h2>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <template v-for="link in voiceLinks" :key="link.provider">
                    <Button
                        v-if="link.isDefault"
                        as="a"
                        :href="link.url"
                        variant="default"
                    >
                        {{ labels.join_voice }} — {{ link.label }}
                    </Button>
                    <a
                        v-else
                        :href="link.url"
                        class="rounded-sm text-sm text-muted-foreground underline outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {{ link.label }}
                    </a>
                </template>
            </div>
            <p
                v-if="voiceLinks.some((link) => link.isDefault)"
                class="mt-1 text-xs text-muted-foreground"
            >
                {{ voiceLabels.default_hint }}
            </p>
        </div>

        <div
            v-if="isNotStarted"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.not_started }}
            </p>
        </div>

        <template v-else>
            <div
                v-if="isFinished"
                class="mt-8 rounded-lg border border-border bg-card p-4"
            >
                <LiveIndicator variant="ok" :label="labels.finished" />
            </div>

            <div class="mt-8">
                <BracketView
                    :matches="matches"
                    :my-entry-id="myEntryId"
                    :can-go-live="canGoLive"
                    :match-status-labels="matchStatusLabels"
                    :report-labels="reportLabels"
                    :warmup-labels="warmupLabels"
                    :bracket-labels="labels"
                    :server-labels="serverLabels"
                    :server-link-status-labels="serverLinkStatusLabels"
                    :live-score-labels="liveScoreLabels"
                />
            </div>
        </template>
    </main>
</template>
