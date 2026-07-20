<script setup lang="ts">
// Minimal placeholder for the M11 Task-6 backend endpoints — the real board
// UI (search, queue cards, vote/skip buttons, live reload on
// jukebox.updated) lands in Task 7. This stub only proves the controller's
// Inertia props reach the page; it renders no interactive controls yet.
import { Head } from '@inertiajs/vue3';

interface QueueItem {
    id: number;
    title: string;
    artist: string | null;
    imageUrl: string | null;
    voteCount: number;
    hasVoted: boolean;
    addedByName: string | null;
}

interface NowPlaying {
    title: string;
    artist: string | null;
    imageUrl: string | null;
    durationSeconds: number | null;
}

defineProps<{
    event: { id: number; name: string; slug: string };
    nowPlaying: NowPlaying | null;
    queue: QueueItem[];
    skipThreshold: number;
    skipVotes: number;
    canParticipate: boolean;
    canModerate: boolean;
    labels: Record<string, string>;
}>();
</script>

<template>
    <Head :title="labels.title" />

    <div class="mx-auto max-w-3xl space-y-6 p-6">
        <h1 class="font-mono text-2xl font-semibold">{{ labels.title }}</h1>

        <section>
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ labels.now_playing }}
            </h2>
            <p v-if="nowPlaying">
                {{ nowPlaying.title }} — {{ nowPlaying.artist }}
            </p>
            <p v-else class="text-muted-foreground">{{ labels.empty_queue }}</p>
        </section>

        <section>
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ labels.queue }}
            </h2>
            <p v-if="queue.length === 0" class="text-muted-foreground">
                {{ labels.empty_queue }}
            </p>
            <ul v-else class="space-y-2">
                <li v-for="item in queue" :key="item.id">
                    {{ item.title }} — {{ item.artist }} ({{ item.voteCount }})
                </li>
            </ul>
        </section>
    </div>
</template>
