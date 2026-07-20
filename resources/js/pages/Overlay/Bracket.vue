<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BracketView from '@/components/bracket/BracketView.vue';
import OverlayFrame from '@/components/overlay/OverlayFrame.vue';
import { useTournamentChannel } from '@/composables/useTournamentChannel';
import type { BracketMatchDto } from '@/types';

const props = defineProps<{
    tournament: { id: number; name: string };
    matches: BracketMatchDto[];
    labels: {
        matchStatusLabels: Record<string, string>;
        reportLabels: Record<string, string>;
        bracketLabels: Record<string, string>;
        liveScoreLabels: Record<string, string>;
    };
}>();

// Same public `tournament.{id}` channel the participant tournament page
// subscribes to (`useTournamentChannel`); it reloads the `matches`/
// `tournament` Inertia props on every bracket-affecting event, which is
// exactly what an unattended OBS browser source needs — no user present to
// trigger a manual refresh.
useTournamentChannel(props.tournament.id);
</script>

<template>
    <Head :title="tournament.name" />

    <OverlayFrame>
        <!-- Render-only: no viewer is authenticated on a browser source, so
             there is no "my entry" to highlight and no report/confirm/Go
             control can ever be actionable here. -->
        <BracketView
            :matches="matches"
            :my-entry-id="null"
            :can-go-live="false"
            :match-status-labels="labels.matchStatusLabels"
            :report-labels="labels.reportLabels"
            :bracket-labels="labels.bracketLabels"
            :live-score-labels="labels.liveScoreLabels"
        />
    </OverlayFrame>
</template>
