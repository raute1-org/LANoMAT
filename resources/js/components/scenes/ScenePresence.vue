<script setup lang="ts">
import LiveIndicator from '@/components/LiveIndicator.vue';
import type { FreeSlotDto, LiveMatchPresenceDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: {
        checkedInCount?: number;
        liveMatches?: LiveMatchPresenceDto[];
        freeSlots?: FreeSlotDto[];
    };
    labels: Record<string, string>;
}>();

function liveMatches(): LiveMatchPresenceDto[] {
    return props.data.liveMatches ?? [];
}

function freeSlots(): FreeSlotDto[] {
    return props.data.freeSlots ?? [];
}

function openSpotsLabel(slot: FreeSlotDto): string {
    if (slot.openSpots === null) {
        return props.labels.presence_open_unlimited;
    }

    return props.labels.presence_open_spots.replace(
        ':count',
        String(slot.openSpots),
    );
}
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <div class="flex flex-wrap items-baseline justify-between gap-8">
            <h1
                class="min-w-0 truncate text-5xl font-bold tracking-tight text-foreground"
            >
                {{ config.headline ?? labels.presence_title }}
            </h1>
            <p class="flex shrink-0 items-baseline gap-3">
                <span
                    class="font-mono text-8xl font-semibold text-foreground tabular-nums"
                >
                    {{ data.checkedInCount ?? 0 }}
                </span>
                <span
                    class="text-2xl font-normal text-muted-foreground uppercase"
                >
                    {{ labels.presence_checked_in_label }}
                </span>
            </p>
        </div>

        <div class="grid min-h-0 flex-1 grid-cols-2 gap-12">
            <section class="flex min-h-0 flex-col gap-4">
                <h2
                    class="text-2xl font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.presence_live_matches_heading }}
                </h2>

                <p
                    v-if="liveMatches().length === 0"
                    class="text-3xl text-muted-foreground"
                >
                    {{ labels.presence_live_matches_empty }}
                </p>

                <ul v-else class="flex-1 space-y-4 overflow-auto">
                    <li
                        v-for="match in liveMatches()"
                        :key="match.matchId"
                        class="rounded-xl bg-card px-8 py-6"
                    >
                        <div class="flex items-center justify-between gap-6">
                            <p
                                class="min-w-0 truncate text-3xl font-semibold text-foreground"
                            >
                                {{ match.label }}
                            </p>
                            <LiveIndicator
                                class="shrink-0"
                                :label="labels.presence_live_label"
                            />
                        </div>
                        <p
                            v-if="match.game"
                            class="mt-1 truncate text-xl text-muted-foreground"
                        >
                            {{ match.game }}
                        </p>
                    </li>
                </ul>
            </section>

            <section class="flex min-h-0 flex-col gap-4">
                <h2
                    class="text-2xl font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.presence_free_slots_heading }}
                </h2>

                <p
                    v-if="freeSlots().length === 0"
                    class="text-3xl text-muted-foreground"
                >
                    {{ labels.presence_free_slots_empty }}
                </p>

                <ul v-else class="flex-1 space-y-4 overflow-auto">
                    <li
                        v-for="slot in freeSlots()"
                        :key="slot.tournamentId"
                        class="flex items-center justify-between gap-6 rounded-xl bg-card px-8 py-6"
                    >
                        <div class="min-w-0">
                            <p
                                class="truncate text-3xl font-semibold text-foreground"
                            >
                                {{ slot.name }}
                            </p>
                            <p
                                v-if="slot.game"
                                class="mt-1 truncate text-xl text-muted-foreground"
                            >
                                {{ slot.game }}
                            </p>
                        </div>
                        <p
                            class="shrink-0 font-mono text-2xl font-semibold text-foreground tabular-nums"
                        >
                            {{ openSpotsLabel(slot) }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
