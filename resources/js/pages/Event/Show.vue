<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    index as eventsIndex,
    register as eventsRegister,
} from '@/routes/events';
import { discord as loginDiscord } from '@/routes/login';
import type { EventSummary } from '@/types';

const props = defineProps<{
    event: EventSummary;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
}>();

const page = usePage();
const isAuthenticated = computed(() => page.props.auth.user !== null);

const dateRange = computed(() => {
    if (!props.event.startsAt) {
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

const cta = computed<string | null>(() => {
    const map = props.labels as Record<string, unknown>;
    const ctas = (map.cta ?? {}) as Record<string, string>;

    return ctas[props.event.status] ?? null;
});
</script>

<template>
    <Head :title="event.name" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <p
            class="text-sm font-medium tracking-wide text-muted-foreground uppercase"
        >
            {{ statusLabels[event.status] }}
        </p>
        <h1 class="mt-2 text-4xl font-bold tracking-tight">{{ event.name }}</h1>

        <dl class="mt-8 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-muted-foreground">{{ labels.when }}</dt>
                <dd class="text-lg">{{ dateRange }}</dd>
            </div>
            <div v-if="event.location">
                <dt class="text-sm text-muted-foreground">
                    {{ labels.where }}
                </dt>
                <dd class="text-lg">{{ event.location }}</dd>
            </div>
        </dl>

        <div class="mt-10 flex flex-wrap gap-3">
            <Button
                v-if="event.status === 'registration' && isAuthenticated"
                as-child
                size="lg"
            >
                <Link :href="eventsRegister.url(event.slug)">{{ cta }}</Link>
            </Button>
            <Button
                v-else-if="event.status === 'registration'"
                as-child
                size="lg"
            >
                <Link :href="loginDiscord.url()">{{
                    labels.login_to_register
                }}</Link>
            </Button>
            <Button v-else-if="cta" size="lg" disabled aria-disabled="true">
                {{ cta }}
            </Button>
            <Button as-child variant="outline">
                <Link :href="eventsIndex()">{{ labels.to_archive }}</Link>
            </Button>
        </div>
    </main>
</template>
