<script setup lang="ts">
import LiveIndicator from '@/components/LiveIndicator.vue';
import Panel from '@/components/Panel.vue';
import { formatClock } from '@/lib/datetime';
import { index as scheduleIndex } from '@/routes/schedule';
import type { ScheduleItemDto } from '@/types/now';
import { useNowLabels } from './useNowLabels';

defineProps<{
    schedule: {
        current: ScheduleItemDto | null;
        upcoming: ScheduleItemDto[];
    };
    eventSlug: string;
}>();

const t = useNowLabels();
</script>

<template>
    <Panel
        :title="t('next')"
        :link="{ href: scheduleIndex.url(eventSlug), label: t('schedule') }"
    >
        <p
            v-if="!schedule.current && schedule.upcoming.length === 0"
            class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
        >
            {{ t('next_empty') }}
        </p>
        <ol v-else class="divide-y divide-border border-t border-border">
            <li
                v-if="schedule.current"
                class="flex items-center gap-3 px-4 py-2.5 text-sm"
            >
                <span class="w-16 shrink-0 font-mono text-xs text-foreground">{{
                    t('now')
                }}</span>
                <span class="min-w-0 flex-1 font-medium text-foreground">{{
                    schedule.current.title
                }}</span>
                <LiveIndicator />
            </li>
            <li
                v-for="item in schedule.upcoming"
                :key="`${item.startsAt}-${item.title}`"
                class="flex items-center gap-3 px-4 py-2.5 text-sm"
            >
                <span
                    class="w-16 shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                    >{{ formatClock(item.startsAt) }}</span
                >
                <span class="min-w-0 flex-1 text-foreground">{{
                    item.title
                }}</span>
            </li>
        </ol>
    </Panel>
</template>
