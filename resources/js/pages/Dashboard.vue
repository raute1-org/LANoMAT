<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';
import { edit as editConnections } from '@/routes/connections';
import {
    index as eventsIndex,
    register as eventsRegister,
    seating as eventsSeating,
    show as eventsShow,
} from '@/routes/events';
import { index as teamsIndex, show as teamsShow } from '@/routes/teams';

type DashboardEvent = {
    name: string;
    slug: string;
    status: 'announced' | 'registration' | 'live';
    startsAt: string | null;
    endsAt: string | null;
    location: string | null;
    availability: 'upcoming' | 'open' | 'full' | 'closed';
};

const props = defineProps<{
    event: DashboardEvent | null;
    registration: {
        ticketType: string;
        ticketLabel: string;
        status: string;
        paid: boolean;
        checkedIn: boolean;
        arrival: {
            arrivalLabel: string | null;
            departureLabel: string | null;
            editable: boolean;
        };
    } | null;
    seat: { id: number; label: string } | null;
    teams: { id: number; name: string; tag: string; role: string }[];
    steamLinked: boolean;
    statusLabels: Record<string, string>;
    labels: Record<string, any>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a later client-driven refresh — same router-lifecycle mirror
// as settings/Connections.vue.
const isNavigating = ref(false);
const hasError = ref(false);

let removeStart: (() => void) | undefined;
let removeFinish: (() => void) | undefined;
let removeError: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', () => {
        hasError.value = false;
        isNavigating.value = true;
    });
    removeFinish = router.on('finish', () => {
        isNavigating.value = false;
    });
    removeError = router.on('error', () => {
        hasError.value = true;
    });
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
    removeError?.();
});

const dateRange = computed(() => {
    if (!props.event?.startsAt) {
        return '';
    }

    const opts: Intl.DateTimeFormatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    };
    const start = new Date(props.event.startsAt).toLocaleString('de-DE', opts);
    const end = props.event.endsAt
        ? new Date(props.event.endsAt).toLocaleString('de-DE', opts)
        : null;

    return end ? `${start} – ${end}` : start;
});

// Static (non-ticking) countdown, only before the LAN starts.
const countdown = computed<string | null>(() => {
    if (!props.event?.startsAt || props.event.status === 'live') {
        return null;
    }

    const remainingMs = new Date(props.event.startsAt).getTime() - Date.now();

    if (remainingMs <= 0) {
        return null;
    }

    const hoursTotal = Math.floor(remainingMs / 3_600_000);

    if (hoursTotal < 1) {
        return props.labels.event.countdown_soon;
    }

    return props.labels.event.countdown
        .replace(':days', String(Math.floor(hoursTotal / 24)))
        .replace(':hours', String(hoursTotal % 24));
});

const ticketNote = computed<string>(() => {
    const t = props.labels.ticket;

    if (props.registration) {
        return props.registration.status === 'pending'
            ? t.pending
            : t.confirmed;
    }

    switch (props.event?.availability) {
        case 'open':
            return t.none_open;
        case 'full':
            return t.none_full;
        case 'upcoming':
            return t.none_upcoming;
        default:
            return t.none_closed;
    }
});
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="wide"
        :title="labels.title"
        :description="labels.description"
        class="space-y-8"
    >
        <!-- loading: only shown mid-navigation, e.g. on a client-side refresh -->
        <div
            v-if="isNavigating"
            class="grid gap-4 md:grid-cols-2"
            data-test="dashboard-loading"
        >
            <Skeleton class="h-48 w-full rounded-md md:col-span-2" />
            <Skeleton class="h-32 w-full rounded-md" />
            <Skeleton class="h-32 w-full rounded-md" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center"
            data-test="dashboard-error"
        >
            <p class="text-sm text-destructive">{{ labels.load_error }}</p>
        </div>

        <template v-else>
            <!-- current / next event -->
            <section
                class="rounded-md border border-border bg-card p-5"
                data-test="dashboard-event"
            >
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ labels.event.heading }}
                </h2>

                <p
                    v-if="!event"
                    class="mt-3 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                    data-test="dashboard-event-empty"
                >
                    {{ labels.event.empty }}
                </p>

                <template v-else>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <LiveIndicator
                            v-if="event.status === 'live'"
                            :label="statusLabels[event.status]"
                        />
                        <span v-else class="text-sm text-muted-foreground">{{
                            statusLabels[event.status]
                        }}</span>
                        <span
                            v-if="countdown"
                            class="font-mono text-sm text-foreground tabular-nums"
                            >{{ countdown }}</span
                        >
                    </div>
                    <p
                        class="mt-2 text-2xl font-semibold tracking-tight text-foreground"
                    >
                        {{ event.name }}
                    </p>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm text-muted-foreground">
                                {{ labels.event.when }}
                            </dt>
                            <dd
                                class="mt-1 font-mono text-sm text-foreground tabular-nums"
                            >
                                {{ dateRange }}
                            </dd>
                        </div>
                        <div v-if="event.location">
                            <dt class="text-sm text-muted-foreground">
                                {{ labels.event.where }}
                            </dt>
                            <dd class="mt-1 text-sm text-foreground">
                                {{ event.location }}
                            </dd>
                        </div>
                    </dl>
                    <div class="mt-5">
                        <Button as-child variant="outline" size="sm">
                            <Link :href="eventsShow.url(event.slug)">{{
                                labels.event.open
                            }}</Link>
                        </Button>
                    </div>
                </template>
            </section>

            <div v-if="event" class="grid gap-4 md:grid-cols-2">
                <!-- ticket -->
                <section
                    class="rounded-md border border-border bg-card p-5"
                    data-test="dashboard-ticket"
                >
                    <h2 class="text-lg font-semibold tracking-tight">
                        {{ labels.ticket.heading }}
                    </h2>
                    <p class="mt-3 text-sm text-foreground">
                        {{ ticketNote }}
                    </p>
                    <ul
                        v-if="registration"
                        class="mt-2 space-y-1 text-sm text-muted-foreground"
                    >
                        <li>
                            {{ labels.ticket.type }}:
                            {{ registration.ticketLabel }}
                        </li>
                        <li>
                            {{
                                registration.paid
                                    ? labels.ticket.paid
                                    : labels.ticket.unpaid
                            }}
                        </li>
                        <li>
                            {{
                                registration.checkedIn
                                    ? labels.ticket.checked_in
                                    : labels.ticket.not_checked_in
                            }}
                        </li>
                        <li data-test="dashboard-arrival">
                            {{ labels.ticket.arrival }}:
                            {{
                                registration.arrival.arrivalLabel ??
                                labels.ticket.arrival_open
                            }}
                        </li>
                        <li v-if="registration.arrival.departureLabel">
                            {{ labels.ticket.departure }}:
                            {{ registration.arrival.departureLabel }}
                        </li>
                    </ul>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button
                            v-if="registration"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link :href="eventsRegister.url(event.slug)">{{
                                labels.ticket.show
                            }}</Link>
                        </Button>
                        <Button
                            v-if="registration && registration.arrival.editable"
                            as-child
                            variant="outline"
                            size="sm"
                            data-test="dashboard-arrival-edit"
                        >
                            <Link
                                :href="
                                    eventsRegister.url(event.slug) + '#arrival'
                                "
                                >{{
                                    registration.arrival.arrivalLabel
                                        ? labels.ticket.arrival_change
                                        : labels.ticket.arrival_set
                                }}</Link
                            >
                        </Button>
                        <Button
                            v-else-if="event.availability === 'open'"
                            as-child
                            size="sm"
                        >
                            <Link :href="eventsRegister.url(event.slug)">{{
                                labels.ticket.register
                            }}</Link>
                        </Button>
                    </div>
                </section>

                <!-- seat -->
                <section
                    class="rounded-md border border-border bg-card p-5"
                    data-test="dashboard-seat"
                >
                    <h2 class="text-lg font-semibold tracking-tight">
                        {{ labels.seat.heading }}
                    </h2>
                    <p
                        v-if="seat"
                        class="mt-3 font-mono text-2xl font-semibold text-foreground tabular-nums"
                    >
                        {{ seat.label }}
                    </p>
                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        {{
                            registration
                                ? labels.seat.none
                                : labels.seat.none_no_registration
                        }}
                    </p>
                    <div v-if="registration" class="mt-4">
                        <Button
                            as-child
                            :variant="seat ? 'outline' : 'default'"
                            size="sm"
                        >
                            <Link :href="eventsSeating.url(event.slug)">{{
                                seat ? labels.seat.show : labels.seat.choose
                            }}</Link>
                        </Button>
                    </div>
                </section>
            </div>

            <!-- teams -->
            <section
                class="rounded-md border border-border bg-card p-5"
                data-test="dashboard-teams"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold tracking-tight">
                        {{ labels.teams.heading }}
                    </h2>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="teamsIndex()">{{ labels.teams.all }}</Link>
                    </Button>
                </div>
                <p
                    v-if="teams.length === 0"
                    class="mt-3 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                    data-test="dashboard-teams-empty"
                >
                    {{ labels.teams.empty }}
                </p>
                <ul v-else class="mt-3 divide-y divide-border">
                    <li
                        v-for="team in teams"
                        :key="team.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <Link
                            :href="teamsShow.url(team.id)"
                            class="rounded text-sm font-medium text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {{ team.name }}
                            <span
                                class="ml-1 font-mono text-xs text-muted-foreground"
                                >[{{ team.tag }}]</span
                            >
                        </Link>
                        <span class="text-xs text-muted-foreground">{{
                            labels.teams.role[team.role]
                        }}</span>
                    </li>
                </ul>
            </section>

            <!-- quick links + gentle Steam nudge -->
            <section data-test="dashboard-links">
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ labels.links.heading }}
                </h2>
                <div class="mt-3 flex flex-wrap gap-3">
                    <Button as-child variant="outline" size="sm">
                        <Link :href="eventsIndex()">{{
                            labels.links.events
                        }}</Link>
                    </Button>
                    <Button as-child variant="outline" size="sm">
                        <Link :href="teamsIndex()">{{
                            labels.links.teams
                        }}</Link>
                    </Button>
                    <Button as-child variant="outline" size="sm">
                        <Link :href="editConnections()">{{
                            labels.links.connections
                        }}</Link>
                    </Button>
                </div>
                <div
                    v-if="!steamLinked"
                    class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-dashed border-border p-4"
                    data-test="dashboard-steam-nudge"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ labels.links.steam_nudge }}
                    </p>
                    <Button as-child size="sm" variant="outline">
                        <Link :href="editConnections()">{{
                            labels.links.steam_link
                        }}</Link>
                    </Button>
                </div>
            </section>
        </template>
    </PageContainer>
</template>
