<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { show as tournamentsShow } from '@/routes/tournaments';
import type { PresenceBoardDto } from '@/types';

interface PresenceLabels {
    title: string;
    checked_in_count: string;
    error: string;
    participants: {
        heading: string;
        empty: string;
        idle: string;
        seat_label: string;
    };
    free_slots: {
        heading: string;
        empty: string;
        open_unlimited: string;
        open_spots: string;
        join: string;
    };
    live_matches: {
        heading: string;
        empty: string;
        live_label: string;
    };
    filters: {
        free_slots_only: string;
        playing_only: string;
    };
}

const props = defineProps<{
    event: { name: string; slug: string };
    presence: PresenceBoardDto;
    labels: PresenceLabels;
}>();

function t<S extends keyof PresenceLabels>(
    section: S,
    key: keyof PresenceLabels[S],
): string {
    const group = props.labels[section];

    return String(group[key] ?? key);
}

function checkedInCountLabel(): string {
    return props.labels.checked_in_count.replace(
        ':count',
        String(props.presence.checkedInCount),
    );
}

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a subsequent client-driven navigation/refresh (mirrors
// Servers/Index.vue) rather than invented polling.
const isNavigating = ref(false);
const hasError = ref(false);

function onStart() {
    hasError.value = false;
    isNavigating.value = true;
}

function onFinish() {
    isNavigating.value = false;
}

function onError() {
    hasError.value = true;
}

let removeStart: (() => void) | undefined;
let removeFinish: (() => void) | undefined;
let removeError: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', onStart);
    removeFinish = router.on('finish', onFinish);
    removeError = router.on('error', onError);
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
    removeError?.();
});

// Quiet client-side filter toggles — no server round-trip, the board is
// already fully loaded.
const freeSlotsOnly = ref(false);
const playingOnly = ref(false);

const visibleParticipants = computed(() =>
    playingOnly.value
        ? props.presence.participants.filter((p) => p.isPlaying)
        : props.presence.participants,
);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function openSpotsLabel(openSpots: number | null): string {
    return openSpots === null
        ? t('free_slots', 'open_unlimited')
        : props.labels.free_slots.open_spots.replace(
              ':count',
              String(openSpots),
          );
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-3xl font-bold tracking-tight text-foreground">
                {{ labels.title }} — {{ event.name }}
            </h1>
            <p class="font-mono text-sm text-muted-foreground tabular-nums">
                {{ checkedInCountLabel() }}
            </p>
        </div>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-24 w-full rounded-lg" />
            <Skeleton class="h-24 w-full rounded-lg" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-lg border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">{{ labels.error }}</p>
        </div>

        <template v-else>
            <!-- filters -->
            <div class="mt-8 flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    :variant="freeSlotsOnly ? 'default' : 'outline'"
                    :aria-pressed="freeSlotsOnly"
                    @click="freeSlotsOnly = !freeSlotsOnly"
                >
                    {{ labels.filters.free_slots_only }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="playingOnly ? 'default' : 'outline'"
                    :aria-pressed="playingOnly"
                    @click="playingOnly = !playingOnly"
                >
                    {{ labels.filters.playing_only }}
                </Button>
            </div>

            <!-- participants -->
            <section class="mt-8">
                <h2
                    class="text-lg font-semibold tracking-tight text-foreground"
                >
                    {{ t('participants', 'heading') }}
                </h2>

                <div
                    v-if="visibleParticipants.length === 0"
                    class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t('participants', 'empty') }}
                    </p>
                </div>

                <ul v-else class="mt-4 space-y-2">
                    <li
                        v-for="participant in visibleParticipants"
                        :key="participant.name"
                        class="flex items-center gap-3 rounded-lg border border-border bg-card p-3"
                    >
                        <Avatar class="size-9">
                            <AvatarImage
                                v-if="participant.avatarUrl"
                                :src="participant.avatarUrl"
                                :alt="participant.name"
                            />
                            <AvatarFallback>{{
                                initials(participant.name)
                            }}</AvatarFallback>
                        </Avatar>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate font-medium text-foreground">
                                    {{ participant.name }}
                                </p>
                                <LiveIndicator
                                    v-if="participant.isPlaying"
                                    variant="live"
                                    :label="t('live_matches', 'live_label')"
                                />
                            </div>
                            <p class="truncate text-sm text-muted-foreground">
                                {{
                                    participant.activity ??
                                    t('participants', 'idle')
                                }}
                            </p>
                        </div>

                        <div
                            v-if="participant.seatLabel"
                            class="shrink-0 text-right"
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ t('participants', 'seat_label') }}
                            </p>
                            <p
                                class="font-mono text-sm text-foreground tabular-nums"
                            >
                                {{ participant.seatLabel }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- free slots -->
            <section v-if="!playingOnly" class="mt-10">
                <h2
                    class="text-lg font-semibold tracking-tight text-foreground"
                >
                    {{ t('free_slots', 'heading') }}
                </h2>

                <div
                    v-if="presence.freeSlots.length === 0"
                    class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t('free_slots', 'empty') }}
                    </p>
                </div>

                <div v-else class="mt-4 space-y-3">
                    <Card
                        v-for="slot in presence.freeSlots"
                        :key="slot.tournamentId"
                    >
                        <CardHeader>
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <CardTitle>{{ slot.name }}</CardTitle>
                                <span
                                    class="font-mono text-sm text-foreground tabular-nums"
                                    >{{ openSpotsLabel(slot.openSpots) }}</span
                                >
                            </div>
                            <CardDescription v-if="slot.game">{{
                                slot.game
                            }}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button as-child size="sm" variant="outline">
                                <Link
                                    :href="
                                        tournamentsShow.url(slot.tournamentId)
                                    "
                                    >{{ t('free_slots', 'join') }}</Link
                                >
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <!-- live matches -->
            <section v-if="!freeSlotsOnly" class="mt-10">
                <h2
                    class="text-lg font-semibold tracking-tight text-foreground"
                >
                    {{ t('live_matches', 'heading') }}
                </h2>

                <div
                    v-if="presence.liveMatches.length === 0"
                    class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t('live_matches', 'empty') }}
                    </p>
                </div>

                <div v-else class="mt-4 space-y-3">
                    <Card
                        v-for="match in presence.liveMatches"
                        :key="match.matchId"
                    >
                        <CardHeader>
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <CardTitle>{{ match.label }}</CardTitle>
                                <LiveIndicator
                                    variant="live"
                                    :label="t('live_matches', 'live_label')"
                                />
                            </div>
                            <CardDescription v-if="match.game">{{
                                match.game
                            }}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">
                                {{ match.players.join(' vs ') }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </template>
    </main>
</template>
