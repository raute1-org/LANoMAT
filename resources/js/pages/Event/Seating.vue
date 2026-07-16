<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { pingOrga } from '@/routes/events';
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
    canPing: boolean;
    labels: Record<string, string>;
    orgaPingLabels: Record<string, string>;
}>();

const pingForm = useForm({
    words: '',
});

function submitPing() {
    pingForm.post(pingOrga.url({ event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => pingForm.reset(),
    });
}

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

function isClaimable(seat: SeatDto): boolean {
    return props.canClaim && !seat.occupant;
}

function stateLabel(seat: SeatDto): string {
    if (seat.id === props.mySeatId) {
        return props.labels.my_seat;
    }

    if (seat.occupant) {
        return props.labels.occupied_by.replace(':name', seat.occupant);
    }

    return props.labels.free;
}

function seatAriaLabel(seat: SeatDto): string {
    return `${seat.label} — ${stateLabel(seat)}`;
}

function onSeatKeydown(event: KeyboardEvent, seat: SeatDto) {
    if (event.key !== 'Enter' && event.key !== ' ') {
        return;
    }

    event.preventDefault();
    claim(seat);
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-5xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight text-foreground">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <p v-if="!canClaim" class="mt-4 text-sm text-muted-foreground">
            {{ labels.need_registration }}
        </p>
        <div v-else-if="mySeatId" class="mt-4 flex items-center gap-3">
            <span class="font-mono text-sm text-primary uppercase tabular-nums">
                {{ labels.my_seat }}
            </span>
            <button
                class="rounded-sm text-sm text-muted-foreground underline outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="release"
            >
                {{ labels.release }}
            </button>
        </div>

        <form
            v-if="canPing"
            class="mt-8 flex flex-col gap-2 rounded-lg border border-border bg-card p-4 sm:flex-row sm:items-end"
            @submit.prevent="submitPing"
        >
            <div class="grid flex-1 gap-2">
                <Label for="orga-ping-words">{{
                    orgaPingLabels.words_label
                }}</Label>
                <Input
                    id="orga-ping-words"
                    v-model="pingForm.words"
                    :placeholder="orgaPingLabels.words_placeholder"
                    maxlength="40"
                />
                <p
                    v-if="pingForm.errors.words"
                    class="text-sm text-destructive"
                >
                    {{ pingForm.errors.words }}
                </p>
            </div>
            <Button type="submit" :disabled="pingForm.processing">
                {{ orgaPingLabels.send }}
            </Button>
        </form>

        <!-- legend -->
        <div
            class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-2">
                <span
                    class="inline-block size-3 rounded-[3px] border border-border bg-primary"
                />
                {{ labels.my_seat }}
            </span>
            <span class="flex items-center gap-2">
                <span
                    class="inline-block size-3 rounded-[3px] border border-border bg-muted"
                />
                {{ labels.occupied }}
            </span>
            <span class="flex items-center gap-2">
                <span
                    class="inline-block size-3 rounded-[3px] border border-border bg-card"
                />
                {{ labels.free }}
            </span>
        </div>

        <div
            v-if="seats.length === 0"
            class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.empty }}
            </p>
        </div>
        <div
            v-else
            class="mt-4 overflow-auto rounded-lg border border-border p-4"
        >
            <svg :viewBox="`0 0 ${maxX * CELL} ${maxY * CELL}`" class="w-full">
                <g
                    v-for="seat in seats"
                    :key="seat.id"
                    :transform="`translate(${seat.x * CELL}, ${seat.y * CELL})`"
                    :class="isClaimable(seat) ? 'cursor-pointer' : ''"
                    :role="isClaimable(seat) ? 'button' : undefined"
                    :tabindex="isClaimable(seat) ? 0 : undefined"
                    :aria-label="seatAriaLabel(seat)"
                    @click="claim(seat)"
                    @keydown="onSeatKeydown($event, seat)"
                >
                    <title>{{ seatAriaLabel(seat) }}</title>
                    <rect
                        :width="CELL - 8"
                        :height="CELL - 8"
                        rx="6"
                        :fill="fill(seat)"
                        stroke="var(--border)"
                        class="outline-none focus-visible:stroke-ring"
                    />
                    <text
                        x="8"
                        y="20"
                        class="font-mono text-[10px] tabular-nums"
                        :fill="
                            seat.id === mySeatId
                                ? 'var(--primary-foreground)'
                                : 'var(--foreground)'
                        "
                    >
                        {{ seat.label }}
                    </text>
                    <text
                        v-if="seat.occupant"
                        x="8"
                        y="40"
                        class="text-[9px]"
                        :fill="
                            seat.id === mySeatId
                                ? 'var(--primary-foreground)'
                                : 'var(--muted-foreground)'
                        "
                    >
                        {{ seat.occupant }}
                    </text>
                </g>
            </svg>
        </div>
    </main>
</template>
