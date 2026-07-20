<script setup lang="ts">
import LiveIndicator from '@/components/LiveIndicator.vue';
import type { NowPlayingTrackDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: {
        track?: NowPlayingTrackDto | null;
        upNext?: NowPlayingTrackDto[];
    };
    labels: Record<string, string>;
}>();

function upNext(): NowPlayingTrackDto[] {
    return props.data.upNext ?? [];
}

function artistLabel(track: NowPlayingTrackDto): string {
    return track.artist ?? props.labels.now_playing_artist_unknown;
}
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <div class="flex flex-wrap items-baseline justify-between gap-8">
            <h1
                class="min-w-0 truncate text-5xl font-bold tracking-tight text-foreground"
            >
                {{ config.headline ?? labels.now_playing_title }}
            </h1>
            <LiveIndicator
                v-if="data.track"
                class="shrink-0"
                :label="labels.now_playing_live_label"
            />
        </div>

        <div class="grid min-h-0 flex-1 grid-cols-3 gap-12">
            <section class="col-span-2 flex min-h-0 items-center gap-10">
                <template v-if="data.track">
                    <img
                        v-if="data.track.imageUrl"
                        :src="data.track.imageUrl"
                        alt=""
                        loading="lazy"
                        width="360"
                        height="360"
                        class="aspect-square w-1/3 shrink-0 rounded-2xl object-cover"
                    />
                    <div
                        v-else
                        class="aspect-square w-1/3 shrink-0 rounded-2xl bg-card"
                        aria-hidden="true"
                    />

                    <div class="min-w-0">
                        <p
                            class="line-clamp-3 text-6xl font-bold tracking-tight text-foreground"
                        >
                            {{ data.track.title }}
                        </p>
                        <p class="mt-4 truncate text-4xl text-muted-foreground">
                            {{ artistLabel(data.track) }}
                        </p>
                    </div>
                </template>

                <p v-else class="text-3xl text-muted-foreground">
                    {{ labels.now_playing_empty }}
                </p>
            </section>

            <section class="flex min-h-0 flex-col gap-4">
                <h2
                    class="text-2xl font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labels.now_playing_up_next_heading }}
                </h2>

                <p
                    v-if="upNext().length === 0"
                    class="text-2xl text-muted-foreground"
                >
                    {{ labels.now_playing_up_next_empty }}
                </p>

                <ul v-else class="flex-1 space-y-4 overflow-auto">
                    <li
                        v-for="(track, index) in upNext()"
                        :key="index"
                        class="flex items-center gap-4 rounded-xl bg-card px-6 py-4"
                    >
                        <img
                            v-if="track.imageUrl"
                            :src="track.imageUrl"
                            alt=""
                            loading="lazy"
                            width="64"
                            height="64"
                            class="aspect-square size-16 shrink-0 rounded-lg object-cover"
                        />
                        <div
                            v-else
                            class="aspect-square size-16 shrink-0 rounded-lg bg-muted"
                            aria-hidden="true"
                        />
                        <div class="min-w-0">
                            <p
                                class="truncate text-xl font-semibold text-foreground"
                            >
                                {{ track.title }}
                            </p>
                            <p class="truncate text-lg text-muted-foreground">
                                {{ artistLabel(track) }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
