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

// Normalise the grid to its bounding box (seat coords are 1-based) so the
// beamer map has no stray empty row/column and stays centred — mirrors the
// participant Seating.vue fix.
const minX = computed(() =>
    seats.value.length ? Math.min(...seats.value.map((s) => s.x)) : 0,
);
const minY = computed(() =>
    seats.value.length ? Math.min(...seats.value.map((s) => s.y)) : 0,
);
const cols = computed(() =>
    seats.value.length
        ? Math.max(...seats.value.map((s) => s.x)) - minX.value + 1
        : 1,
);
const rows = computed(() =>
    seats.value.length
        ? Math.max(...seats.value.map((s) => s.y)) - minY.value + 1
        : 1,
);

function fill(seat: SeatDto): string {
    return seat.occupant ? 'var(--muted)' : 'var(--card)';
}

// SVG <text> neither wraps nor clips; ellipsis-truncate long occupant names
// to fit the tile (the #scene-seat-clip clipPath is the hard backstop).
function occupantLabel(seat: SeatDto): string {
    const name = seat.occupant ?? '';

    return name.length > 10 ? `${name.slice(0, 9)}…` : name;
}
</script>

<template>
    <div class="flex h-full w-full flex-col gap-6 px-8 py-6">
        <h1 class="text-4xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.seatmap_title }}
        </h1>

        <p v-if="seats.length === 0" class="text-3xl text-muted-foreground">
            {{ labels.seatmap_empty }}
        </p>

        <div v-else class="min-h-0 flex-1 overflow-auto">
            <svg
                :viewBox="`0 0 ${cols * CELL} ${rows * CELL}`"
                class="h-full w-full"
            >
                <defs>
                    <clipPath id="scene-seat-clip">
                        <rect :width="CELL - 10" :height="CELL - 10" rx="8" />
                    </clipPath>
                </defs>
                <g
                    v-for="seat in seats"
                    :key="seat.id"
                    :transform="`translate(${(seat.x - minX) * CELL}, ${(seat.y - minY) * CELL})`"
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
                        y="30"
                        class="font-mono text-[16px] font-semibold tabular-nums"
                        fill="var(--foreground)"
                    >
                        {{ seat.label }}
                    </text>
                    <text
                        v-if="seat.occupant"
                        x="10"
                        y="58"
                        clip-path="url(#scene-seat-clip)"
                        class="text-[13px]"
                        fill="var(--muted-foreground)"
                    >
                        {{ occupantLabel(seat) }}
                    </text>
                </g>
            </svg>
        </div>
    </div>
</template>
