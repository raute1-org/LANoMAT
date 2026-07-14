<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BracketView from '@/components/bracket/BracketView.vue';
import { useTournamentChannel } from '@/composables/useTournamentChannel';
import { index as tournamentsIndex } from '@/routes/tournaments';
import type { BracketMatchDto, TournamentDetail } from '@/types';

const props = defineProps<{
    tournament: TournamentDetail;
    matches: BracketMatchDto[];
    myEntryId: number | null;
    labels: Record<string, string>;
    matchStatusLabels: Record<string, string>;
    reportLabels: Record<string, string>;
}>();

useTournamentChannel(props.tournament.id);
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

        <h1 class="mt-2 text-3xl font-bold tracking-tight">
            {{ tournament.name }}
        </h1>
        <p class="text-sm text-muted-foreground">
            {{ tournament.event.name }}
        </p>

        <div class="mt-8">
            <BracketView
                :matches="matches"
                :my-entry-id="myEntryId"
                :match-status-labels="matchStatusLabels"
                :report-labels="reportLabels"
                :bracket-labels="labels"
            />
        </div>
    </main>
</template>
