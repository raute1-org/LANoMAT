<script setup lang="ts">
// The beamer post-LAN recap board (M12 Task 9): the night's headline
// numbers, the tournament podium, and top photo highlights — the same
// `RecapProjection` board as the public `/events/{event}/recap` page (see
// ScenePayload::recapData()), rendered in the "loud beamer" register
// (near-black, huge mono numbers) that mirrors SceneNowPlaying/ScenePresence's
// structure. Recap is post-event static data: there is no live update to
// react to, so unlike those scenes this component never expects mid-viewing
// changes — the existing scene rotation/`.scenes.updated` reload is enough.
import type { RecapBoardDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: Partial<RecapBoardDto>;
    labels: Record<string, string>;
}>();

function podiums() {
    return props.data.podiums ?? [];
}

function topPhotos() {
    return props.data.topPhotos ?? [];
}
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <div class="flex flex-wrap items-baseline justify-between gap-8">
            <h1
                class="min-w-0 truncate text-5xl font-bold tracking-tight text-foreground"
            >
                {{ config.headline ?? labels.recap_title }}
            </h1>
        </div>

        <!-- headline numbers -->
        <div class="grid grid-cols-4 gap-8">
            <div
                class="flex flex-col items-center gap-2 rounded-xl bg-card py-6"
            >
                <span
                    class="font-mono text-7xl font-semibold text-foreground tabular-nums"
                >
                    {{ data.participantCount ?? 0 }}
                </span>
                <span
                    class="text-lg font-normal tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.recap_participants_label }}
                </span>
            </div>
            <div
                class="flex flex-col items-center gap-2 rounded-xl bg-card py-6"
            >
                <span
                    class="font-mono text-7xl font-semibold text-foreground tabular-nums"
                >
                    {{ data.tournamentCount ?? 0 }}
                </span>
                <span
                    class="text-lg font-normal tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.recap_tournaments_label }}
                </span>
            </div>
            <div
                class="flex flex-col items-center gap-2 rounded-xl bg-card py-6"
            >
                <span
                    class="font-mono text-7xl font-semibold text-foreground tabular-nums"
                >
                    {{ data.matchesPlayed ?? 0 }}
                </span>
                <span
                    class="text-lg font-normal tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.recap_matches_label }}
                </span>
            </div>
            <div
                class="flex flex-col items-center gap-2 rounded-xl bg-card py-6"
            >
                <span
                    class="font-mono text-7xl font-semibold text-foreground tabular-nums"
                >
                    {{ data.songsPlayed ?? 0 }}
                </span>
                <span
                    class="text-lg font-normal tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.recap_songs_label }}
                </span>
            </div>
        </div>

        <div class="grid min-h-0 flex-1 grid-cols-2 gap-12">
            <section class="flex min-h-0 flex-col gap-4">
                <h2
                    class="text-2xl font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.recap_podium_heading }}
                </h2>

                <p
                    v-if="podiums().length === 0"
                    class="text-3xl text-muted-foreground"
                >
                    {{ labels.recap_podium_empty }}
                </p>

                <ul v-else class="flex-1 space-y-4 overflow-auto">
                    <li
                        v-for="podium in podiums()"
                        :key="podium.tournamentName"
                        class="rounded-xl bg-card px-8 py-6"
                    >
                        <p
                            class="min-w-0 truncate text-3xl font-extrabold text-live uppercase"
                        >
                            {{ podium.winnerName }}
                        </p>
                        <p class="mt-1 truncate text-xl text-muted-foreground">
                            {{ podium.tournamentName }}
                        </p>
                    </li>
                </ul>
            </section>

            <section class="flex min-h-0 flex-col gap-4">
                <h2
                    class="text-2xl font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.recap_photos_heading }}
                </h2>

                <p
                    v-if="topPhotos().length === 0"
                    class="text-3xl text-muted-foreground"
                >
                    {{ labels.recap_photos_empty }}
                </p>

                <div
                    v-else
                    class="grid flex-1 grid-cols-3 gap-4 overflow-hidden"
                >
                    <img
                        v-for="photo in topPhotos().slice(0, 6)"
                        :key="photo.url"
                        :src="photo.url"
                        alt=""
                        loading="lazy"
                        width="320"
                        height="320"
                        class="aspect-square w-full rounded-lg object-cover"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
