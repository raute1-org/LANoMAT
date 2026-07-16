<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { show as eventsShow } from '@/routes/events';
import type { EventSummary } from '@/types';

defineProps<{
    events: EventSummary[];
    labels: Record<string, string>;
}>();

function year(iso: string | null): string {
    return iso ? new Date(iso).getFullYear().toString() : '';
}
</script>

<template>
    <Head :title="labels.archive_title" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight text-foreground">
            {{ labels.archive_title }}
        </h1>

        <div
            v-if="events.length === 0"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.no_current_event }}
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ labels.archive_empty }}
            </p>
        </div>

        <ul v-else class="mt-8 divide-y divide-border">
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
    </main>
</template>
