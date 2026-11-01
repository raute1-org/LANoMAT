<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CircleCheck, Clock, TicketX } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import UserAvatar from '@/components/UserAvatar.vue';
import { formatInstant } from '@/lib/datetime';
import {
    register as eventsRegister,
    seating as eventsSeating,
    show as eventsShow,
} from '@/routes/events';
import { store as storeRegistration } from '@/routes/events/register';
import { index as tournamentsIndex } from '@/routes/tournaments';

type State = 'ready' | 'already' | 'not_registered' | 'not_open' | 'over';

const props = defineProps<{
    state: State;
    event: { name: string; slug: string };
    participant: { name: string; avatarUrl: string | null };
    checkedInAt: string | null;
    opensAt: string | null;
    availability: 'upcoming' | 'open' | 'full' | 'closed';
    freeSpots: number | null;
    ticketType: string;
    labels: Record<string, string>;
}>();

const page = usePage();

// The token lives only in the URL (never in props): post back to this very page.
const checkinForm = useForm({});
const registerForm = useForm({ ticket_type: props.ticketType });
const failed = ref(false);

const timeOptions: Intl.DateTimeFormatOptions = {
    hour: '2-digit',
    minute: '2-digit',
};
const dayOptions: Intl.DateTimeFormatOptions = {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
};

const checkedInTime = computed(() =>
    props.checkedInAt ? formatInstant(props.checkedInAt, timeOptions) : '',
);
const opensLabel = computed(() => {
    if (!props.opensAt) {
        return props.labels.not_open_unknown;
    }

    const at = props.opensAt;

    return props.labels.not_open.replace(
        ':time',
        `${formatInstant(at, dayOptions)}, ${formatInstant(at, timeOptions)}`,
    );
});
const spotsLabel = computed(() =>
    props.freeSpots === null
        ? null
        : props.labels.not_registered_spots.replace(
              ':count',
              String(props.freeSpots),
          ),
);

// Replaces Inertia's default error modal with the inline error state.
function markFailed(): false {
    failed.value = true;

    return false;
}

function checkIn() {
    failed.value = false;
    checkinForm.post(page.url, {
        preserveScroll: true,
        onError: markFailed,
        onHttpException: markFailed,
        onNetworkError: markFailed,
    });
}

function register() {
    failed.value = false;
    registerForm.post(storeRegistration.url({ event: props.event.slug }), {
        preserveScroll: true,
        onError: markFailed,
        onHttpException: markFailed,
        onNetworkError: markFailed,
    });
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="narrow"
        :title="labels.title"
        :description="event.name"
    >
        <div
            class="mt-6 flex items-center gap-3 rounded-md border border-border bg-card p-4"
            data-test="checkin-participant"
        >
            <UserAvatar
                :name="participant.name"
                :src="participant.avatarUrl"
                :size="40"
            />
            <p class="min-w-0 truncate text-sm font-medium text-foreground">
                {{ labels.greeting.replace(':name', participant.name) }}
            </p>
        </div>

        <div
            v-if="failed"
            class="mt-6 rounded-md border border-destructive/50 bg-destructive/10 p-6 text-center text-sm text-destructive"
            role="alert"
            data-test="checkin-error"
        >
            {{ labels.error }}
        </div>

        <!-- ready: the one primary action -->
        <section
            v-if="state === 'ready'"
            class="mt-6 space-y-6"
            data-test="checkin-ready"
        >
            <p class="text-base text-muted-foreground">{{ labels.ready }}</p>
            <Button
                size="lg"
                class="h-16 w-full text-lg"
                :disabled="checkinForm.processing"
                data-test="checkin-submit"
                @click="checkIn"
            >
                <Spinner v-if="checkinForm.processing" class="size-5" />
                {{ labels.submit }}
            </Button>
        </section>

        <!-- already / success -->
        <section
            v-else-if="state === 'already'"
            class="mt-6 space-y-6"
            data-test="checkin-already"
        >
            <div
                class="flex flex-col items-center gap-3 rounded-md border border-border bg-card p-8 text-center"
            >
                <CircleCheck class="size-12 text-ok" aria-hidden="true" />
                <p class="text-lg font-semibold tracking-tight text-foreground">
                    {{ labels.done }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ labels.already.replace(':time', checkedInTime) }}
                </p>
            </div>
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ labels.next }}
                </h2>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <Button as-child variant="outline" size="lg">
                        <Link :href="eventsSeating.url(event.slug)">{{
                            labels.to_seating
                        }}</Link>
                    </Button>
                    <Button as-child variant="outline" size="lg">
                        <Link :href="tournamentsIndex.url(event.slug)">{{
                            labels.to_tournaments
                        }}</Link>
                    </Button>
                </div>
            </div>
        </section>

        <!-- not registered -->
        <section
            v-else-if="state === 'not_registered'"
            class="mt-6 space-y-6"
            data-test="checkin-not-registered"
        >
            <template v-if="availability === 'open'">
                <div class="space-y-1">
                    <h2 class="text-lg font-semibold tracking-tight">
                        {{ labels.not_registered_title }}
                    </h2>
                    <p class="text-base text-muted-foreground">
                        {{ labels.not_registered }}
                    </p>
                    <p v-if="spotsLabel" class="text-sm text-muted-foreground">
                        {{ spotsLabel }}
                    </p>
                </div>
                <Button
                    size="lg"
                    class="h-16 w-full text-lg"
                    :disabled="registerForm.processing"
                    data-test="checkin-register"
                    @click="register"
                >
                    <Spinner v-if="registerForm.processing" class="size-5" />
                    {{ labels.register }}
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link :href="eventsRegister.url(event.slug)">{{
                        labels.choose_ticket
                    }}</Link>
                </Button>
            </template>
            <div
                v-else
                class="flex flex-col items-center gap-3 rounded-md border border-dashed border-border p-6 text-center"
            >
                <TicketX
                    class="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="text-lg font-semibold tracking-tight">
                    {{
                        availability === 'full'
                            ? labels.full_title
                            : labels.not_registered_title
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        availability === 'full'
                            ? labels.full
                            : labels.not_registered_closed
                    }}
                </p>
            </div>
        </section>

        <!-- not open yet -->
        <section
            v-else-if="state === 'not_open'"
            class="mt-6 flex flex-col items-center gap-3 rounded-md border border-dashed border-border p-6 text-center"
            data-test="checkin-not-open"
        >
            <Clock class="size-8 text-muted-foreground" aria-hidden="true" />
            <p class="text-lg font-semibold tracking-tight">
                {{ labels.not_open_title }}
            </p>
            <p class="text-sm text-muted-foreground">{{ opensLabel }}</p>
        </section>

        <!-- over -->
        <section
            v-else
            class="mt-6 flex flex-col items-center gap-3 rounded-md border border-dashed border-border p-6 text-center"
            data-test="checkin-over"
        >
            <p class="text-lg font-semibold tracking-tight">
                {{ labels.over_title }}
            </p>
            <p class="text-sm text-muted-foreground">{{ labels.over }}</p>
            <Button as-child variant="outline" size="sm">
                <Link :href="eventsShow.url(event.slug)">{{
                    labels.to_event
                }}</Link>
            </Button>
        </section>
    </PageContainer>
</template>
