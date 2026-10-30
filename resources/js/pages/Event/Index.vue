<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { formatInstant } from '@/lib/datetime';
import {
    register as eventsRegister,
    show as eventsShow,
} from '@/routes/events';
import type { EventSummary } from '@/types';

defineProps<{
    /** Announced, registration and live events (not drafts, not over). */
    current: EventSummary[];
    /** Finished and archived events, newest first. */
    events: EventSummary[];
    labels: Record<string, string>;
}>();

function year(iso: string | null): string {
    return iso ? new Date(iso).getFullYear().toString() : '';
}

function when(iso: string | null): string {
    return iso
        ? formatInstant(iso, {
              weekday: 'short',
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
          })
        : '';
}
</script>

<template>
    <Head :title="labels.index_title" />

    <PageContainer width="default" :title="labels.index_title">
        <section aria-labelledby="events-current" class="mt-8">
            <h2
                id="events-current"
                class="text-sm font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ labels.current_title }}
            </h2>

            <div
                v-if="current.length === 0"
                class="mt-3 rounded-lg border border-dashed border-border p-6 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.current_empty }}
                </p>
            </div>

            <ul v-else class="mt-3 divide-y divide-border">
                <li
                    v-for="event in current"
                    :key="event.slug"
                    class="flex flex-wrap items-center justify-between gap-3 py-4"
                >
                    <Link
                        :href="eventsShow(event.slug)"
                        class="min-w-0 rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span class="block text-lg font-medium text-foreground">
                            {{ event.name }}
                        </span>
                        <span
                            class="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-muted-foreground"
                        >
                            <LiveIndicator
                                v-if="event.status === 'live'"
                                variant="live"
                                :label="labels.current_live"
                            />
                            <span class="tabular-nums">{{
                                when(event.startsAt)
                            }}</span>
                            <span v-if="event.location">{{
                                event.location
                            }}</span>
                        </span>
                    </Link>
                    <Button
                        v-if="
                            event.availability === 'open' &&
                            event.status !== 'announced'
                        "
                        as-child
                    >
                        <Link :href="eventsRegister(event.slug)">{{
                            labels.current_register
                        }}</Link>
                    </Button>
                    <Button v-else variant="outline" as-child>
                        <Link :href="eventsShow(event.slug)">{{
                            labels.current_details
                        }}</Link>
                    </Button>
                </li>
            </ul>
        </section>

        <section aria-labelledby="events-archive" class="mt-10">
            <h2
                id="events-archive"
                class="text-sm font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ labels.archive_title }}
            </h2>

            <div
                v-if="events.length === 0"
                class="mt-3 rounded-lg border border-dashed border-border p-6 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.archive_empty }}
                </p>
            </div>

            <ul v-else class="mt-3 divide-y divide-border">
                <li v-for="event in events" :key="event.slug" class="py-4">
                    <Link
                        :href="eventsShow(event.slug)"
                        class="flex items-baseline justify-between rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span class="text-lg font-medium text-foreground">{{
                            event.name
                        }}</span>
                        <span
                            class="font-mono text-sm text-muted-foreground tabular-nums"
                            >{{ year(event.startsAt) }}</span
                        >
                    </Link>
                </li>
            </ul>
        </section>
    </PageContainer>
</template>
