<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useNowLabels } from './useNowLabels';

const props = defineProps<{ startsAt: string }>();

const t = useNowLabels();
const now = ref(Date.now());
let handle: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    handle = setInterval(() => {
        now.value = Date.now();
    }, 30_000);
});
onUnmounted(() => {
    if (handle) {
        clearInterval(handle);
    }
});

const parts = computed(() => {
    const minutesTotal = Math.max(
        0,
        Math.floor((new Date(props.startsAt).getTime() - now.value) / 60_000),
    );

    return {
        days: Math.floor(minutesTotal / 1440),
        hours: Math.floor((minutesTotal % 1440) / 60),
        minutes: minutesTotal % 60,
    };
});

const pad = (n: number) => n.toString().padStart(2, '0');
</script>

<template>
    <div
        role="timer"
        class="flex items-baseline gap-4 font-mono text-sm tabular-nums"
        :aria-label="
            t('countdown_aria', {
                days: parts.days,
                hours: parts.hours,
                minutes: parts.minutes,
            })
        "
    >
        <span
            ><span class="text-lg font-semibold text-foreground">{{
                parts.days
            }}</span
            ><span class="text-muted-foreground">
                {{ t('days_short') }}</span
            ></span
        >
        <span
            ><span class="text-lg font-semibold text-foreground">{{
                pad(parts.hours)
            }}</span
            ><span class="text-muted-foreground">
                {{ t('hours_short') }}</span
            ></span
        >
        <span
            ><span class="text-lg font-semibold text-foreground">{{
                pad(parts.minutes)
            }}</span
            ><span class="text-muted-foreground">
                {{ t('minutes_short') }}</span
            ></span
        >
    </div>
</template>
