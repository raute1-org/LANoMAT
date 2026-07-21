<script setup lang="ts">
// The public post-LAN recap page (M12 Task 9): headline activity numbers,
// the tournament podium, and top gallery highlights for an event that has
// wrapped (Finished/Archived — see RecapPageController). No auth required,
// same visibility rule as Presence/Index.vue and the rest of the public
// participant UI. Inertia renders this synchronously on first load, so there
// is no loading/error state to model here (mirrors Presence/Index.vue's
// reasoning) — every section instead has its own empty state for the
// (very normal) case of a quiet LAN with no tournaments/photos yet.
import { Head } from '@inertiajs/vue3';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { RecapBoardDto } from '@/types';

interface RecapLabels {
    title: string;
    stats: {
        participants: string;
        tournaments: string;
        matches_played: string;
        songs_played: string;
    };
    podium: {
        heading: string;
        empty: string;
    };
    photos: {
        heading: string;
        empty: string;
    };
    mvp: {
        heading: string;
    };
}

const props = defineProps<{
    event: { name: string; slug: string };
    recap: RecapBoardDto;
    labels: RecapLabels;
}>();

const stats = [
    { key: 'participantCount', label: () => props.labels.stats.participants },
    { key: 'tournamentCount', label: () => props.labels.stats.tournaments },
    { key: 'matchesPlayed', label: () => props.labels.stats.matches_played },
    { key: 'songsPlayed', label: () => props.labels.stats.songs_played },
] as const;

function statValue(key: (typeof stats)[number]['key']): number {
    return props.recap[key] ?? 0;
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight text-foreground">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <!-- headline numbers -->
        <div class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                v-for="stat in stats"
                :key="stat.key"
                class="flex flex-col items-center gap-1 rounded-lg border border-border bg-card py-6"
            >
                <span
                    class="font-mono text-4xl font-semibold text-foreground tabular-nums"
                >
                    {{ statValue(stat.key) }}
                </span>
                <span class="text-xs text-muted-foreground">{{
                    stat.label()
                }}</span>
            </div>
        </div>

        <!-- MVP, once the closed poll feeds it (Task 13) -->
        <section v-if="recap.mvp" class="mt-10">
            <h2 class="text-lg font-semibold tracking-tight text-foreground">
                {{ labels.mvp.heading }}
            </h2>
            <p class="mt-4 text-2xl font-bold text-live">
                {{ recap.mvp.name }}
            </p>
        </section>

        <!-- podium -->
        <section class="mt-10">
            <h2 class="text-lg font-semibold tracking-tight text-foreground">
                {{ labels.podium.heading }}
            </h2>

            <div
                v-if="recap.podiums.length === 0"
                class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.podium.empty }}
                </p>
            </div>

            <ol v-else class="mt-4 space-y-2">
                <li
                    v-for="podium in recap.podiums"
                    :key="podium.tournamentName"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-live">{{
                                podium.winnerName
                            }}</CardTitle>
                            <CardDescription>{{
                                podium.tournamentName
                            }}</CardDescription>
                        </CardHeader>
                    </Card>
                </li>
            </ol>
        </section>

        <!-- top photos -->
        <section class="mt-10">
            <h2 class="text-lg font-semibold tracking-tight text-foreground">
                {{ labels.photos.heading }}
            </h2>

            <div
                v-if="recap.topPhotos.length === 0"
                class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.photos.empty }}
                </p>
            </div>

            <div v-else class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <figure
                    v-for="photo in recap.topPhotos"
                    :key="photo.url"
                    class="overflow-hidden rounded-lg border border-border"
                >
                    <img
                        :src="photo.url"
                        alt=""
                        loading="lazy"
                        width="320"
                        height="320"
                        class="aspect-square w-full object-cover"
                    />
                    <figcaption
                        v-if="photo.caption"
                        class="truncate px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ photo.caption }}
                    </figcaption>
                </figure>
            </div>
        </section>
    </main>
</template>
