<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ZoomIn, ZoomOut } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    pingOrga,
    register as eventsRegister,
    show as eventsShow,
} from '@/routes/events';
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
    claimState: 'ok' | 'no_registration' | 'pending' | 'event_over';
    registrationState: 'upcoming' | 'open' | 'full' | 'closed';
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

// Seat coordinates are 1-based (and could in theory be sparse), so normalise
// the grid to its own bounding box: tiles are drawn from (x - minX, y - minY)
// and the viewBox is sized to exactly cols x rows — no stray empty
// row/column, and the grid stays centred in its container.
const minX = computed(() =>
    props.seats.length ? Math.min(...props.seats.map((s) => s.x)) : 0,
);
const minY = computed(() =>
    props.seats.length ? Math.min(...props.seats.map((s) => s.y)) : 0,
);
const cols = computed(() =>
    props.seats.length
        ? Math.max(...props.seats.map((s) => s.x)) - minX.value + 1
        : 1,
);
const rows = computed(() =>
    props.seats.length
        ? Math.max(...props.seats.map((s) => s.y)) - minY.value + 1
        : 1,
);

// --- selection: tapping a seat opens a details panel (footer), the claim is a
// separate, deliberate button — no accidental claims from a mis-tap on a
// phone, and the full occupant name has room to be read.
const selectedSeatId = ref<number | null>(null);
const announcement = ref('');
const selectedSeat = computed(
    () => props.seats.find((s) => s.id === selectedSeatId.value) ?? null,
);

function select(seat: SeatDto) {
    selectedSeatId.value = seat.id;
}

function focusSeat(seatId: number) {
    void nextTick(() => {
        document
            .querySelector<SVGGElement>(`[data-seat-id="${seatId}"]`)
            ?.focus();
    });
}

function claim(seat: SeatDto) {
    if (!props.canClaim || seat.occupant) {
        return;
    }

    router.post(
        claimSeat.url({ event: props.event.slug, seat: seat.id }),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                // The claimed tile re-renders in place; keep keyboard focus on
                // it and announce the outcome (the error case is announced by
                // the toast, so only confirm when the seat is really ours).
                if (props.mySeatId === seat.id) {
                    announcement.value = props.labels.claimed.replace(
                        ':seat',
                        seat.label,
                    );
                    focusSeat(seat.id);
                }
            },
        },
    );
}

function release() {
    const seatId = props.mySeatId;

    router.delete(releaseSeat.url({ event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => {
            announcement.value = props.labels.released;

            if (seatId !== null) {
                focusSeat(seatId);
            }
        },
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

// --- sizing: seats keep a readable, touchable size at any viewport. The base
// cell scales with the container (as before on desktop) but never drops below
// MIN_CELL_PX, so a phone gets a horizontally scrollable plan instead of a
// shrunken one. Zoom buttons scale it further.
const MIN_CELL_PX = 60;
const MAX_CELL_PX = 112;
const ZOOM_MIN = 0.8;
const ZOOM_MAX = 2;
const ZOOM_STEP = 0.2;

const host = ref<HTMLElement | null>(null);
const scroller = ref<HTMLElement | null>(null);
const hostWidth = ref(0);
const zoom = ref(1);
const overflowing = ref(false);

const baseCellPx = computed(() => {
    const fit = (hostWidth.value - 34) / cols.value;

    return Math.min(MAX_CELL_PX, Math.max(MIN_CELL_PX, fit || MIN_CELL_PX));
});
const cellPx = computed(() => baseCellPx.value * zoom.value);

function measure() {
    hostWidth.value = host.value?.clientWidth ?? 0;
    void nextTick(() => {
        const el = scroller.value;
        overflowing.value = el ? el.scrollWidth > el.clientWidth + 1 : false;
    });
}

function zoomBy(delta: number) {
    zoom.value = Math.min(
        ZOOM_MAX,
        Math.max(ZOOM_MIN, Math.round((zoom.value + delta) * 10) / 10),
    );
    measure();
}

let observer: ResizeObserver | null = null;

onMounted(() => {
    measure();

    if (host.value && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(measure);
        observer.observe(host.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

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

// SVG <text> neither wraps nor clips on its own, so a long occupant name
// spills past the tile. Ellipsis-truncate it for a clean look; the
// #seat-clip clipPath in the template is the hard backstop that guarantees
// nothing ever renders outside the tile (the full name stays in the title/
// aria-label above for accessibility).
function occupantLabel(seat: SeatDto): string {
    const name = seat.occupant ?? '';

    // Budgeted to fit the tile at the occupant font size; the #seat-clip
    // clipPath is the hard backstop for anything still too wide.
    return name.length > 10 ? `${name.slice(0, 9)}…` : name;
}

function onSeatKeydown(event: KeyboardEvent, seat: SeatDto) {
    if (event.key !== 'Enter' && event.key !== ' ') {
        return;
    }

    event.preventDefault();
    select(seat);
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="wide"
        :title="`${labels.title} — ${event.name}`"
        :back="{ href: eventsShow.url(event.slug), label: 'Zurück zum Event' }"
    >
        <div v-if="!canClaim" class="mt-4 space-y-3" data-test="seating-hint">
            <p class="text-sm text-muted-foreground">
                {{
                    claimState === 'pending'
                        ? labels.confirm_registration_first
                        : claimState === 'event_over'
                          ? labels.event_over
                          : registrationState === 'open'
                            ? labels.need_registration
                            : registrationState === 'upcoming'
                              ? labels.opens_with_registration
                              : registrationState === 'full'
                                ? labels.registration_full
                                : labels.registration_closed
                }}
            </p>
            <Button
                v-if="
                    registrationState === 'open' &&
                    claimState === 'no_registration'
                "
                as-child
                size="sm"
            >
                <Link :href="eventsRegister.url(event.slug)">{{
                    labels.to_registration
                }}</Link>
            </Button>
        </div>
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
        <div v-else ref="host" class="mt-4 w-full">
            <div class="mb-2 flex items-center justify-between gap-3">
                <p
                    class="text-xs text-muted-foreground"
                    data-test="seating-scroll-hint"
                >
                    <template v-if="overflowing">{{
                        labels.scroll_hint
                    }}</template>
                    <template v-else>{{ labels.select_hint }}</template>
                </p>
                <div
                    class="flex items-center gap-1"
                    role="group"
                    :aria-label="labels.zoom"
                >
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="outline"
                        :aria-label="labels.zoom_out"
                        :disabled="zoom <= ZOOM_MIN"
                        @click="zoomBy(-ZOOM_STEP)"
                    >
                        <ZoomOut class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="outline"
                        :aria-label="labels.zoom_in"
                        :disabled="zoom >= ZOOM_MAX"
                        @click="zoomBy(ZOOM_STEP)"
                    >
                        <ZoomIn class="size-4" />
                    </Button>
                </div>
            </div>
            <div
                ref="scroller"
                class="mx-auto w-fit max-w-full overflow-auto rounded-lg border border-border p-4"
                tabindex="-1"
            >
                <svg
                    :viewBox="`0 0 ${cols * CELL} ${rows * CELL}`"
                    :style="{
                        width: `${cols * cellPx}px`,
                        height: `${rows * cellPx}px`,
                    }"
                    class="block max-w-none"
                    role="group"
                    :aria-label="labels.title"
                >
                    <defs>
                        <!-- Clip seat text to the tile so a long occupant name
                        can never render outside its seat (see occupantLabel). -->
                        <clipPath id="seat-clip">
                            <rect :width="CELL - 8" :height="CELL - 8" rx="6" />
                        </clipPath>
                    </defs>
                    <g
                        v-for="seat in seats"
                        :key="seat.id"
                        :data-seat-id="seat.id"
                        :transform="`translate(${(seat.x - minX) * CELL}, ${(seat.y - minY) * CELL})`"
                        class="group cursor-pointer outline-none"
                        role="button"
                        tabindex="0"
                        :aria-pressed="seat.id === selectedSeatId"
                        :aria-label="seatAriaLabel(seat)"
                        @click="select(seat)"
                        @keydown="onSeatKeydown($event, seat)"
                    >
                        <title>{{ seatAriaLabel(seat) }}</title>
                        <rect
                            :width="CELL - 8"
                            :height="CELL - 8"
                            rx="6"
                            :fill="fill(seat)"
                            :stroke="
                                seat.id === selectedSeatId
                                    ? 'var(--foreground)'
                                    : 'var(--border)'
                            "
                            :stroke-width="seat.id === selectedSeatId ? 3 : 1"
                            class="group-focus-visible:stroke-ring group-focus-visible:[stroke-width:4]"
                        />
                        <text
                            x="7"
                            y="23"
                            class="font-mono text-[13px] tabular-nums"
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
                            x="7"
                            y="43"
                            clip-path="url(#seat-clip)"
                            class="text-[11px]"
                            :fill="
                                seat.id === mySeatId
                                    ? 'var(--primary-foreground)'
                                    : 'var(--muted-foreground)'
                            "
                        >
                            {{ occupantLabel(seat) }}
                        </text>
                    </g>
                </svg>
            </div>
        </div>

        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <!-- Details of the selected seat: a footer (sticky on small screens)
        instead of a tooltip, so a tap reveals the full name and the action. -->
        <div
            v-if="selectedSeat"
            class="sticky bottom-0 z-10 mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-border bg-card p-3 shadow-sm"
            data-test="seat-details"
        >
            <div class="min-w-32 flex-1">
                <p class="font-mono text-sm font-medium tabular-nums">
                    {{ selectedSeat.label }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ stateLabel(selectedSeat) }}
                </p>
            </div>
            <Button
                v-if="isClaimable(selectedSeat)"
                type="button"
                @click="claim(selectedSeat)"
            >
                {{ labels.claim }}
            </Button>
            <Button
                v-else-if="selectedSeat.id === mySeatId && canClaim"
                type="button"
                variant="outline"
                @click="release"
            >
                {{ labels.release }}
            </Button>
            <Button
                type="button"
                variant="ghost"
                @click="selectedSeatId = null"
            >
                {{ labels.close }}
            </Button>
        </div>
    </PageContainer>
</template>
