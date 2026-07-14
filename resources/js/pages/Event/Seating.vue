<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    claim as claimSeat,
    release as releaseSeat,
} from '@/routes/events/seating';
import type { SeatDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    seats: SeatDto[];
    mySeatId: number | null;
    canClaim: boolean;
    labels: Record<string, string>;
}>();

const CELL = 64;
const maxX = computed(() => Math.max(1, ...props.seats.map((s) => s.x)) + 1);
const maxY = computed(() => Math.max(1, ...props.seats.map((s) => s.y)) + 1);

function claim(seat: SeatDto) {
    if (!props.canClaim || seat.occupant) {
        return;
    }

    router.post(
        claimSeat.url({ event: props.event.slug, seat: seat.id }),
        {},
        { preserveScroll: true },
    );
}

function release() {
    router.delete(releaseSeat.url({ event: props.event.slug }), {
        preserveScroll: true,
    });
}

function fill(seat: SeatDto): string {
    if (seat.id === props.mySeatId) {
        return 'var(--primary)';
    }

    return seat.occupant ? 'var(--muted)' : 'var(--card)';
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-5xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <p v-if="!canClaim" class="mt-4 text-sm text-muted-foreground">
            {{ labels.need_registration }}
        </p>
        <button
            v-else-if="mySeatId"
            class="mt-4 text-sm underline"
            @click="release"
        >
            {{ labels.release }}
        </button>

        <div class="mt-8 overflow-auto rounded-lg border border-border p-4">
            <svg :viewBox="`0 0 ${maxX * CELL} ${maxY * CELL}`" class="w-full">
                <g
                    v-for="seat in seats"
                    :key="seat.id"
                    :transform="`translate(${seat.x * CELL}, ${seat.y * CELL})`"
                    :class="canClaim && !seat.occupant ? 'cursor-pointer' : ''"
                    @click="claim(seat)"
                >
                    <rect
                        :width="CELL - 8"
                        :height="CELL - 8"
                        rx="6"
                        :fill="fill(seat)"
                        stroke="var(--border)"
                    />
                    <text
                        x="8"
                        y="20"
                        class="text-[10px]"
                        fill="var(--foreground)"
                    >
                        {{ seat.label }}
                    </text>
                    <text
                        v-if="seat.occupant"
                        x="8"
                        y="40"
                        class="text-[9px]"
                        fill="var(--muted-foreground)"
                    >
                        {{ seat.occupant }}
                    </text>
                </g>
            </svg>
        </div>
    </main>
</template>
