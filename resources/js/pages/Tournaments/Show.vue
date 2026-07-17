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
    MatchVoiceLink,
    TournamentDetail,
} from '@/types';

const props = defineProps<{
    tournament: TournamentDetail;
    matches: BracketMatchDto[];
    myEntryId: number | null;
    myMatchVoiceLink: MatchVoiceLink;
    /** True for a helper/orga viewer — gates the match card's manual "Go" control. */
    canGoLive: boolean;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
    matchStatusLabels: Record<string, string>;
    reportLabels: Record<string, string>;
    warmupLabels: Record<string, string>;
    serverLabels: Record<string, string>;
    serverLinkStatusLabels: Record<string, string>;
}>();

useTournamentChannel(props.tournament.id);

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

        <div v-if="myMatchVoiceLink" class="mt-4">
            <Button as="a" :href="myMatchVoiceLink" variant="outline">
                {{ labels.join_voice }}
            </Button>
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
                />
            </div>
        </template>
    </main>
</template>
