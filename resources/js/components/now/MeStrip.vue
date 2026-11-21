<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { show as eventShow } from '@/routes/events';
import { useNowLabels } from './useNowLabels';

defineProps<{
    me: {
        registration: { checkedIn?: boolean } | null;
        seat: string | null;
        team: string | null;
    };
    eventSlug: string;
    newsCount: number;
}>();

const t = useNowLabels();
const linkClass =
    'rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-md border border-dashed border-border px-4 py-2.5 text-sm text-muted-foreground"
    >
        <span v-if="me.seat">
            {{ t('seat') }}
            <span class="font-mono text-foreground tabular-nums">{{
                me.seat
            }}</span>
        </span>
        <span
            v-if="me.registration?.checkedIn"
            class="inline-flex items-center gap-1.5"
        >
            <LiveIndicator variant="ok" :pulse="false" />
            {{ t('checked_in') }}
        </span>
        <span v-if="me.team">
            {{ t('team') }}
            <span class="font-medium text-foreground">{{ me.team }}</span>
        </span>
        <Link
            v-if="newsCount > 0"
            :href="eventShow.url(eventSlug)"
            :class="linkClass"
        >
            {{ t('news_count', { count: newsCount }) }}
        </Link>
        <Link :href="eventShow.url(eventSlug)" :class="linkClass">{{
            t('event_info')
        }}</Link>
    </div>
</template>
