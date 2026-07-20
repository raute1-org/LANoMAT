<script setup lang="ts">
/**
 * The participant jukebox board: a public, per-event "LAN-Radio" queue that
 * checked-in participants steer by up-voting. Mirrors Presence/Index.vue's
 * structure — a public event page with useEventChannel realtime, LiveIndicator
 * for the now-playing signature, and all four states. See docs/design.md.
 *
 * The broadcast payload on `.jukebox.updated` is intentionally empty — a
 * partial reload re-fetches the authorized `nowPlaying`/`queue`/`skipVotes`
 * props rather than trusting client-held state.
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import NowPlayingCard from '@/components/jukebox/NowPlayingCard.vue';
import QueueRow from '@/components/jukebox/QueueRow.vue';
import SearchBox from '@/components/jukebox/SearchBox.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useEventChannel } from '@/composables/useEventChannel';
import {
    add as addRoute,
    remove as removeRoute,
    skip as skipRoute,
    skipVote as skipVoteRoute,
    vote as voteRoute,
} from '@/routes/jukebox';
import type {
    NowPlayingDto,
    QueueItemDto,
    TrackSearchResultDto,
} from '@/types';

interface JukeboxLabels {
    title: string;
    now_playing: string;
    queue: string;
    empty_queue: string;
    empty_queue_invite: string;
    nothing_playing: string;
    search_placeholder: string;
    search_error: string;
    search_no_results: string;
    search_hint: string;
    add: string;
    added_by: string;
    vote: string;
    vote_count: string;
    skip_vote: string;
    skip_votes_label: string;
    skip: string;
    remove: string;
    not_checked_in_notice: string;
    guest_notice: string;
    error: string;
}

const props = defineProps<{
    event: { id: number; name: string; slug: string };
    nowPlaying: NowPlayingDto | null;
    queue: QueueItemDto[];
    skipThreshold: number;
    skipVotes: number;
    canParticipate: boolean;
    canModerate: boolean;
    labels: JukeboxLabels;
}>();

const page = usePage();
const isAuthenticated = computed(() => page.props.auth.user !== null);

// Live board: any queue/vote/skip mutation broadcasts a bare signal on the
// public `event.{id}` channel; reload just the affected props rather than
// trust client-held state — mirrors Presence/Index.vue's `.presence.updated`
// handling and Polls/Show.vue's live tallies.
useEventChannel(props.event.id, ['.jukebox.updated'], () => {
    router.reload({ only: ['nowPlaying', 'queue', 'skipVotes'] });
});

// Elapsed-time ticker for the now-playing progress bar — purely cosmetic
// client-side interpolation between reloads; the server remains the source
// of truth for what's actually playing (JukeboxTickCommand advances state).
const elapsedSeconds = ref(0);
let tickHandle: ReturnType<typeof setInterval> | undefined;

function resetTicker(): void {
    elapsedSeconds.value = 0;
}

onMounted(() => {
    tickHandle = setInterval(() => {
        elapsedSeconds.value += 1;
    }, 1000);
});

onUnmounted(() => {
    if (tickHandle) {
        clearInterval(tickHandle);
    }
});

// Inertia renders this page synchronously on first load, so "loading"/
// "error" only apply to a subsequent client-driven navigation/reload —
// mirrors Presence/Index.vue.
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
let removeErrorListener: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', onStart);
    removeFinish = router.on('finish', onFinish);
    removeErrorListener = router.on('error', onError);
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
    removeErrorListener?.();
});

function addTrack(track: TrackSearchResultDto): void {
    router.post(
        addRoute.url(props.event.slug),
        {
            uri: track.uri,
            title: track.title,
            artist: track.artist,
            duration_seconds: track.durationSeconds,
            image_url: track.imageUrl,
        },
        { preserveScroll: true },
    );
}

function toggleVote(item: QueueItemDto): void {
    router.post(
        voteRoute.url(item.id),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function removeItem(item: QueueItemDto): void {
    router.delete(removeRoute.url(item.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

function castSkipVote(): void {
    if (!props.nowPlaying) {
        return;
    }

    router.post(
        skipVoteRoute.url(props.nowPlaying.id),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function skipNow(): void {
    router.post(
        skipRoute.url(props.event.slug),
        {},
        {
            preserveScroll: true,
            onSuccess: resetTicker,
        },
    );
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight text-foreground">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-24 w-full rounded-lg" />
            <Skeleton class="h-16 w-full rounded-lg" />
            <Skeleton class="h-16 w-full rounded-lg" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-lg border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">{{ labels.error }}</p>
        </div>

        <template v-else>
            <!-- non-checked-in / guest notice: an invitation, not an apology -->
            <p
                v-if="!canParticipate"
                class="mt-4 rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
            >
                {{
                    isAuthenticated
                        ? labels.not_checked_in_notice
                        : labels.guest_notice
                }}
            </p>

            <!-- now playing -->
            <section class="mt-8">
                <NowPlayingCard
                    v-if="nowPlaying"
                    :now-playing="nowPlaying"
                    :elapsed-seconds="elapsedSeconds"
                    :skip-votes="skipVotes"
                    :skip-threshold="skipThreshold"
                    :can-participate="canParticipate"
                    :can-moderate="canModerate"
                    :labels="labels"
                    @skip-vote="castSkipVote"
                    @skip="skipNow"
                />
                <div
                    v-else
                    class="rounded-lg border border-dashed border-border p-8 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ labels.nothing_playing }}
                    </p>
                </div>
            </section>

            <!-- search-to-add: only for checked-in participants -->
            <section v-if="canParticipate" class="mt-10">
                <h2
                    class="text-lg font-semibold tracking-tight text-foreground"
                >
                    {{ labels.add }}
                </h2>
                <div class="mt-4">
                    <SearchBox
                        :event-slug="event.slug"
                        :labels="labels"
                        @add="addTrack"
                    />
                </div>
            </section>

            <!-- queue -->
            <section class="mt-10">
                <h2
                    class="text-lg font-semibold tracking-tight text-foreground"
                >
                    {{ labels.queue }}
                </h2>

                <div
                    v-if="queue.length === 0"
                    class="mt-4 rounded-lg border border-dashed border-border p-8 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            canParticipate
                                ? labels.empty_queue_invite
                                : labels.empty_queue
                        }}
                    </p>
                </div>

                <ul v-else class="mt-4 space-y-2">
                    <QueueRow
                        v-for="item in queue"
                        :key="item.id"
                        :item="item"
                        :can-participate="canParticipate"
                        :can-moderate="canModerate"
                        :labels="labels"
                        @vote="toggleVote(item)"
                        @remove="removeItem(item)"
                    />
                </ul>
            </section>
        </template>
    </main>
</template>
