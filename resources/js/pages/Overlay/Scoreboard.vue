<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import OverlayFrame from '@/components/overlay/OverlayFrame.vue';
import SceneScoreboard from '@/components/scenes/SceneScoreboard.vue';
import { useMatchScore } from '@/composables/useMatchScore';

const props = defineProps<{
    tournamentId: number;
    matchId: number;
    data: {
        tournament?: string;
        team1?: string;
        team2?: string;
        score1?: number;
        score2?: number;
    };
    labels: Record<string, string>;
}>();

// Live state captured directly from the `.match.score_updated` broadcast
// payload (see useMatchScore.ts) — NOT a `router.reload()`, because the CS2
// `round` counter only ever exists in that payload, never persisted on the
// match. Starts empty and is populated by the first tick.
const live = useMatchScore(props.tournamentId, props.matchId);

// Renders the initial server-seeded snapshot until the first live tick
// arrives, then prefers the live values — `tournament` never changes over
// the channel, so it always comes from the initial seed.
const data = computed(() => ({
    tournament: props.data.tournament,
    team1: live.team1 ?? props.data.team1,
    team2: live.team2 ?? props.data.team2,
    score1: live.score1 ?? props.data.score1,
    score2: live.score2 ?? props.data.score2,
    round: live.round,
}));
</script>

<template>
    <Head :title="data.tournament ?? labels.scoreboard_title" />

    <OverlayFrame>
        <SceneScoreboard :data="data" :labels="labels" />
    </OverlayFrame>
</template>
