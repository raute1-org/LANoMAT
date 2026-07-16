<script setup lang="ts">
import { computed } from 'vue';
import type { SeatDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: { seats?: SeatDto[] };
    labels: Record<string, string>;
}>();

// Larger than the participant Seating.vue's CELL=64: this renders at beamer
// distance, read-only, so bigger cells keep labels legible from across a room.
const CELL = 96;

const seats = computed<SeatDto[]>(() => props.data.seats ?? []);
const maxX = computed(() => Math.max(1, ...seats.value.map((s) => s.x)) + 1);
const maxY = computed(() => Math.max(1, ...seats.value.map((s) => s.y)) + 1);

function fill(seat: SeatDto): string {
    return seat.occupant ? 'var(--muted)' : 'var(--card)';
}
</script>

<template>
    <div class="flex h-full w-full flex-col gap-6 px-8 py-6">
        <h1 class="text-4xl font-bold tracking-tight">
            {{ config.headline ?? labels.seatmap_title }}
        </h1>

        <p v-if="seats.length === 0" class="text-3xl text-white/80">
            {{ labels.seatmap_empty }}
        </p>

        <div v-else class="min-h-0 flex-1 overflow-auto">
            <svg
                :viewBox="`0 0 ${maxX * CELL} ${maxY * CELL}`"
                class="h-full w-full"
            >
                <g
                    v-for="seat in seats"
                    :key="seat.id"
                    :transform="`translate(${seat.x * CELL}, ${seat.y * CELL})`"
                >
                    <title>{{ seat.label }}</title>
                    <rect
                        :width="CELL - 10"
                        :height="CELL - 10"
                        rx="8"
                        :fill="fill(seat)"
                        stroke="var(--border)"
                        stroke-width="2"
                    />
                    <text
                        x="10"
                        y="28"
                        class="text-[14px]"
                        fill="var(--foreground)"
                    >
                        {{ seat.label }}
                    </text>
                    <text
                        v-if="seat.occupant"
                        x="10"
                        y="56"
                        class="text-[13px]"
                        fill="var(--muted-foreground)"
                    >
                        {{ seat.occupant }}
                    </text>
                </g>
            </svg>
        </div>
    </div>
</template>
