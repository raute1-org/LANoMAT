<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { useModules } from '@/composables/useModules';
import { formatInstant } from '@/lib/datetime';
import { show as cateringShow } from '@/routes/catering';
import {
    index as eventsIndex,
    register as eventsRegister,
    seating as eventsSeating,
} from '@/routes/events';
import { index as filesIndex } from '@/routes/files';
import { index as galleryIndex } from '@/routes/gallery';
import { index as jukeboxIndex } from '@/routes/jukebox';
import { index as lfgIndex } from '@/routes/lfg';
import { index as pollsIndex, show as pollShow } from '@/routes/polls';
import { show as presenceShow } from '@/routes/presence';
import { show as recapShow } from '@/routes/recap';
import { index as serversIndex } from '@/routes/servers';
import type { EventSummary, NewsPostDto } from '@/types';

const props = defineProps<{
    event: EventSummary;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
    news: NewsPostDto[];
    registration: {
        ticketType: string;
        ticketLabel: string;
        status: string;
        paid: boolean;
        checkedIn: boolean;
    } | null;
}>();

const { has } = useModules();
const isLive = computed(() => props.event.status === 'live');

function formatPublishedAt(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return formatInstant(iso, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const dateRange = computed(() => {
    if (!props.event.startsAt) {
        return '';
    }

    const opts: Intl.DateTimeFormatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    };
    const start = formatInstant(props.event.startsAt, opts);
    const end = props.event.endsAt
        ? formatInstant(props.event.endsAt, opts)
        : null;

    return end ? `${start} – ${end}` : start;
});

const cta = computed<string | null>(() => {
    const map = props.labels as Record<string, unknown>;
    const ctas = (map.cta ?? {}) as Record<string, string>;

    return (
        ctas[
            props.event.availability === 'open'
                ? 'registration'
                : props.event.status
        ] ?? null
    );
});

const hintLabels = computed(() => {
    const map = props.labels as Record<string, unknown>;

    return (map.hint ?? {}) as Record<string, string>;
});

// Status-dependent call to action (see docs/design.md "Event status -> CTA").
// registered: the viewer holds an active registration; open: they can still
// sign up; the remaining states are plain hints with no action.
const registered = computed(() => props.registration !== null);
const canRegister = computed(
    () => props.event.availability === 'open' && !registered.value,
);
const freeSpotsHint = computed<string | null>(() =>
    canRegister.value &&
    props.event.freeSpots !== null &&
    props.event.freeSpots !== undefined
        ? hintLabels.value.free_spots.replace(
              ':count',
              String(props.event.freeSpots),
          )
        : null,
);
const statusHint = computed<string | null>(() => {
    if (registered.value) {
        return props.registration?.checkedIn
            ? hintLabels.value.checked_in
            : hintLabels.value.registered;
    }

    switch (props.event.availability) {
        case 'upcoming':
            return hintLabels.value.announced;
        case 'open':
            return props.event.status === 'live'
                ? hintLabels.value.live_open
                : null;
        case 'full':
            return hintLabels.value.full;
        default:
            return props.event.status === 'finished' ||
                props.event.status === 'archived'
                ? hintLabels.value.finished
                : null;
    }
});
const isPast = computed(
    () =>
        props.event.status === 'finished' || props.event.status === 'archived',
);

const countdownLabels = computed(() => {
    const map = props.labels as Record<string, unknown>;

    return (map.countdown ?? {}) as Record<string, string>;
});

// Pure client-side display ticker toward `hype.startsAt` — no write path.
// Recomputed every second from wall-clock time rather than counted down, so
// it stays correct even if the tab was backgrounded (no drift to correct).
interface CountdownParts {
    days: number;
    hours: number;
    minutes: number;
    seconds: number;
}

const now = ref(Date.now());
let countdownHandle: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    if (props.event.hype) {
        countdownHandle = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    }
});

onUnmounted(() => {
    if (countdownHandle) {
        clearInterval(countdownHandle);
    }
});

const countdown = computed<CountdownParts | null>(() => {
    const hype = props.event.hype;

    if (!hype) {
        return null;
    }

    const remainingMs = Math.max(
        0,
        new Date(hype.startsAt).getTime() - now.value,
    );
    const totalSeconds = Math.floor(remainingMs / 1000);

    return {
        days: Math.floor(totalSeconds / 86400),
        hours: Math.floor((totalSeconds % 86400) / 3600),
        minutes: Math.floor((totalSeconds % 3600) / 60),
        seconds: totalSeconds % 60,
    };
});

function pad(value: number): string {
    return value.toString().padStart(2, '0');
}
</script>

<template>
    <Head :title="event.name" />

    <PageContainer width="default">
        <LiveIndicator
            v-if="isLive"
            variant="live"
            :label="statusLabels[event.status]"
        />
        <p
            v-else
            class="text-sm font-medium tracking-wide text-muted-foreground uppercase"
        >
            {{ statusLabels[event.status] }}
        </p>
        <h1
            class="mt-3 font-sans text-4xl font-bold tracking-tight text-foreground sm:text-5xl"
        >
            {{ event.name }}
        </h1>

        <dl class="mt-8 grid gap-6 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-muted-foreground">{{ labels.when }}</dt>
                <dd class="mt-1 font-mono text-lg text-foreground tabular-nums">
                    {{ dateRange }}
                </dd>
            </div>
            <div v-if="event.location">
                <dt class="text-sm text-muted-foreground">
                    {{ labels.where }}
                </dt>
                <dd class="mt-1 text-lg text-foreground">
                    {{ event.location }}
                </dd>
            </div>
        </dl>

        <div
            v-if="statusHint"
            class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-md border border-border bg-card p-4"
            data-test="event-status-hint"
        >
            <p
                class="text-sm"
                :class="
                    registered
                        ? 'font-medium text-foreground'
                        : 'text-muted-foreground'
                "
            >
                {{ statusHint }}
            </p>
            <template v-if="registered">
                <Button as-child size="sm" variant="outline">
                    <Link :href="eventsRegister.url(event.slug)">{{
                        labels.to_ticket
                    }}</Link>
                </Button>
                <Button v-if="!isPast" as-child size="sm" variant="outline">
                    <Link :href="eventsSeating.url(event.slug)">{{
                        labels.to_seating
                    }}</Link>
                </Button>
            </template>
            <Button
                v-if="isPast && has('recap')"
                as-child
                size="sm"
                variant="outline"
            >
                <Link :href="recapShow.url(event.slug)">{{
                    labels.to_recap
                }}</Link>
            </Button>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <Button v-if="canRegister" as-child size="lg">
                <Link :href="eventsRegister.url(event.slug)">{{ cta }}</Link>
            </Button>
            <p
                v-if="freeSpotsHint"
                class="self-center text-sm text-muted-foreground"
                data-test="event-free-spots"
            >
                {{ freeSpotsHint }}
            </p>
            <Button v-if="has('presence')" as-child variant="outline">
                <Link :href="presenceShow.url(event.slug)">{{
                    labels.to_presence
                }}</Link>
            </Button>
            <Button v-if="has('jukebox')" as-child variant="outline">
                <Link :href="jukeboxIndex.url(event.slug)">{{
                    labels.to_jukebox
                }}</Link>
            </Button>
            <Button v-if="has('gallery')" as-child variant="outline">
                <Link :href="galleryIndex.url(event.slug)">{{
                    labels.to_gallery
                }}</Link>
            </Button>
            <Button v-if="has('catering')" as-child variant="outline">
                <Link :href="cateringShow.url(event.slug)">{{
                    labels.to_catering
                }}</Link>
            </Button>
            <Button v-if="has('voting')" as-child variant="outline">
                <Link :href="pollsIndex.url(event.slug)">{{
                    labels.to_polls
                }}</Link>
            </Button>
            <Button v-if="has('lfg')" as-child variant="outline">
                <Link :href="lfgIndex.url(event.slug)">{{
                    labels.to_lfg
                }}</Link>
            </Button>
            <Button v-if="has('files')" as-child variant="outline">
                <Link :href="filesIndex.url(event.slug)">{{
                    labels.to_files
                }}</Link>
            </Button>
            <Button v-if="has('game_servers')" as-child variant="outline">
                <Link :href="serversIndex.url(event.slug)">{{
                    labels.to_servers
                }}</Link>
            </Button>
            <Button as-child variant="outline">
                <Link :href="eventsIndex()">{{ labels.to_archive }}</Link>
            </Button>
        </div>

        <section
            v-if="event.hype && countdown"
            class="mt-14 rounded-md border border-border bg-card p-5"
        >
            <LiveIndicator variant="live" :label="countdownLabels.running" />

            <dl class="mt-4 flex flex-wrap items-baseline gap-x-1.5 gap-y-2">
                <div class="flex items-baseline gap-1.5">
                    <dd
                        class="font-mono text-3xl font-semibold text-foreground tabular-nums sm:text-4xl"
                    >
                        {{ pad(countdown.days) }}
                    </dd>
                    <dt class="text-xs text-muted-foreground uppercase">
                        {{ countdownLabels.days }}
                    </dt>
                </div>
                <span
                    class="font-mono text-3xl text-muted-foreground sm:text-4xl"
                    aria-hidden="true"
                    >:</span
                >
                <div class="flex items-baseline gap-1.5">
                    <dd
                        class="font-mono text-3xl font-semibold text-foreground tabular-nums sm:text-4xl"
                    >
                        {{ pad(countdown.hours) }}
                    </dd>
                    <dt class="text-xs text-muted-foreground uppercase">
                        {{ countdownLabels.hours }}
                    </dt>
                </div>
                <span
                    class="font-mono text-3xl text-muted-foreground sm:text-4xl"
                    aria-hidden="true"
                    >:</span
                >
                <div class="flex items-baseline gap-1.5">
                    <dd
                        class="font-mono text-3xl font-semibold text-foreground tabular-nums sm:text-4xl"
                    >
                        {{ pad(countdown.minutes) }}
                    </dd>
                    <dt class="text-xs text-muted-foreground uppercase">
                        {{ countdownLabels.minutes }}
                    </dt>
                </div>
                <span
                    class="font-mono text-3xl text-muted-foreground sm:text-4xl"
                    aria-hidden="true"
                    >:</span
                >
                <div class="flex items-baseline gap-1.5">
                    <dd
                        class="font-mono text-3xl font-semibold text-live tabular-nums sm:text-4xl"
                    >
                        {{ pad(countdown.seconds) }}
                    </dd>
                    <dt class="text-xs text-muted-foreground uppercase">
                        {{ countdownLabels.seconds }}
                    </dt>
                </div>
            </dl>

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ labels.who_is_coming }}
                    </dt>
                    <dd
                        class="mt-1 font-mono text-lg text-foreground tabular-nums"
                    >
                        {{ event.hype.registrationCount }}
                        <span class="font-sans text-sm text-muted-foreground">{{
                            labels.who_is_coming_count
                        }}</span>
                    </dd>
                </div>
                <div v-if="has('voting') && event.hype.activePoll">
                    <dt class="text-sm text-muted-foreground">
                        {{ labels.active_poll_teaser }}
                    </dt>
                    <dd class="mt-1 text-lg text-foreground">
                        <Link
                            :href="
                                pollShow.url({ id: event.hype.activePoll.id })
                            "
                            class="underline decoration-muted-foreground/50 underline-offset-4 hover:decoration-foreground"
                        >
                            {{ event.hype.activePoll.question }}
                        </Link>
                    </dd>
                </div>
            </div>
        </section>

        <section v-if="event.arrivalInfo" class="mt-10">
            <h2 class="text-lg font-semibold tracking-tight text-foreground">
                {{ labels.arrival_heading }}
            </h2>
            <p class="mt-3 text-sm whitespace-pre-line text-muted-foreground">
                {{ event.arrivalInfo }}
            </p>
        </section>

        <section v-if="has('news') && news.length > 0" class="mt-14">
            <h2 class="text-lg font-semibold tracking-tight text-foreground">
                {{ labels.news_heading }}
            </h2>
            <div class="mt-4 space-y-3">
                <article
                    v-for="post in news"
                    :key="post.id"
                    class="rounded-md border border-border bg-card p-4"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                    >
                        <h3 class="font-medium text-foreground">
                            {{ post.title }}
                        </h3>
                        <time
                            v-if="post.publishedAt"
                            class="font-mono text-xs text-muted-foreground tabular-nums"
                            :datetime="post.publishedAt"
                        >
                            {{ formatPublishedAt(post.publishedAt) }}
                        </time>
                    </div>
                    <p
                        class="mt-1.5 line-clamp-3 text-sm text-muted-foreground"
                    >
                        {{ post.body }}
                    </p>
                </article>
            </div>
        </section>
    </PageContainer>
</template>
