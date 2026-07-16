<script setup lang="ts">
import { computed } from 'vue';
import ConfettiOverlay from '@/components/scenes/ConfettiOverlay.vue';

type Prize = {
    id: number;
    title: string;
    winner: string | null;
};

type DrawResult = {
    prize: { id: number; title: string };
    winner: { registrationId: number; name: string };
};

// Two shapes reach this component: the rotation-configured scene's
// `{ prizes, lastDraw }` (ScenePayload::tombolaData), and the reveal-moment
// SceneOverride's `{ prize, winner }` (DrawTombola). Both are normalized
// into the same `reveal` shape below so a single template covers both —
// a live draw pushes the reveal directly, and a viewer tuning in mid-rotation
// still sees the most recent winner via `lastDraw`.
const props = defineProps<{
    data: {
        prizes?: Prize[];
        lastDraw?: DrawResult | null;
        prize?: { id: number; title: string };
        winner?: { registrationId: number; name: string };
    };
    labels: Record<string, string>;
}>();

const reveal = computed<DrawResult | null>(() => {
    if (props.data.prize && props.data.winner) {
        return { prize: props.data.prize, winner: props.data.winner };
    }

    return props.data.lastDraw ?? null;
});

const prizes = computed<Prize[]>(() => props.data.prizes ?? []);
</script>

<template>
    <div
        v-if="reveal"
        class="relative flex h-full w-full items-center justify-center"
    >
        <ConfettiOverlay />

        <div class="relative z-10 mx-auto max-w-5xl px-12 text-center">
            <h1
                class="text-8xl font-extrabold tracking-tight text-live uppercase"
            >
                {{ labels.tombola_winner_title }}
            </h1>
            <p class="mt-8 text-6xl font-bold text-foreground">
                {{ reveal.winner.name }}
            </p>
            <p class="mt-6 text-3xl text-muted-foreground">
                {{
                    labels.tombola_winner_prize.replace(
                        ':prize',
                        reveal.prize.title,
                    )
                }}
            </p>
        </div>
    </div>

    <div v-else class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ labels.tombola_title }}
        </h1>

        <p v-if="prizes.length === 0" class="text-3xl text-muted-foreground">
            {{ labels.tombola_empty }}
        </p>

        <ul v-else class="flex-1 space-y-4 overflow-auto">
            <li
                v-for="prize in prizes"
                :key="prize.id"
                class="flex items-center justify-between rounded-xl bg-card px-8 py-6 text-3xl text-foreground"
            >
                <span class="font-semibold">{{ prize.title }}</span>
                <span
                    v-if="prize.winner"
                    class="font-mono text-live tabular-nums"
                >
                    {{ labels.tombola_drawn_label }}: {{ prize.winner }}
                </span>
            </li>
        </ul>
    </div>
</template>
