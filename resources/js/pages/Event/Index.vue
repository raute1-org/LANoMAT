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
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.archive_title }}
        </h1>

        <p v-if="events.length === 0" class="mt-6 text-muted-foreground">
            {{ labels.archive_empty }}
        </p>

        <ul v-else class="mt-8 divide-y divide-border">
            <li v-for="event in events" :key="event.slug" class="py-4">
                <Link
                    :href="eventsShow(event.slug)"
                    class="flex items-baseline justify-between hover:underline"
                >
                    <span class="text-lg font-medium">{{ event.name }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        year(event.startsAt)
                    }}</span>
                </Link>
            </li>
        </ul>
    </main>
</template>
