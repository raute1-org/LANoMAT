<script setup lang="ts">
import LiveIndicator from '@/components/LiveIndicator.vue';

// The CS2 live-stats scoreboard moment (Task 12, roadmap 6.9): a MatchZy/
// G5API round/score webhook drives this synthetic override scene, mirroring
// SceneGong/SceneWinner's bridge from a narrow tournament channel event onto
// the beamer's wider event channel. This is the loud-beamer register — huge
// score digits are the whole point, scannable from across the room — while
// the calm match-page readout (GameServers/live_score labels) covers the
// same data for someone looking at their own screen.
//
// Scores/round are machine data (Signalpult: font-mono tabular-nums), and
// LiveIndicator's pulsing dot marks this as happening right now. Respects
// `prefers-reduced-motion` via LiveIndicator's own motion-reduce handling —
// this scene adds no animation of its own beyond that.
defineProps<{
    data: {
        tournament?: string;
        team1?: string;
        team2?: string;
        score1?: number;
        score2?: number;
        round?: number;
    };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="flex h-full w-full items-center justify-center">
        <div class="mx-auto max-w-5xl px-12 text-center">
            <div class="flex items-center justify-center gap-3">
                <LiveIndicator
                    variant="live"
                    :label="labels.scoreboard_title"
                />
            </div>

            <div class="mt-10 grid grid-cols-[1fr_auto_1fr] items-center gap-8">
                <div class="text-right">
                    <p class="truncate text-4xl font-bold text-foreground">
                        {{ data.team1 ?? '—' }}
                    </p>
                </div>
                <p
                    class="font-mono text-9xl font-extrabold tracking-tight text-live tabular-nums"
                >
                    {{ data.score1 ?? 0 }}:{{ data.score2 ?? 0 }}
                </p>
                <div class="text-left">
                    <p class="truncate text-4xl font-bold text-foreground">
                        {{ data.team2 ?? '—' }}
                    </p>
                </div>
            </div>

            <p
                v-if="data.round !== undefined"
                class="mt-8 font-mono text-2xl text-muted-foreground tabular-nums"
            >
                {{
                    labels.scoreboard_round?.replace(
                        ':number',
                        String(data.round),
                    )
                }}
            </p>
            <p
                v-if="data.tournament"
                class="mt-2 text-xl text-muted-foreground"
            >
                {{ data.tournament }}
            </p>
        </div>
    </div>
</template>
