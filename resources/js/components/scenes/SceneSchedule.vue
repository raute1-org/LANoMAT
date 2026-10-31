<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { formatInstant } from '@/lib/datetime';
import type { ScheduleItemDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: {
        items?: ScheduleItemDto[];
    };
    labels: Record<string, string>;
}>();

function formatTime(iso: string): string {
    return formatInstant(iso, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

// The payload is a snapshot from page load / the last reload, but a beamer
// runs for hours. Derive "now", "next" and the list from the items against a
// ticking clock so the scene starts at the current programme point and past
// items drop out on their own.
const MAX_LIST_ROWS = 6;
const clock = ref(Date.now());
let clockTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    clockTimer = setInterval(() => {
        clock.value = Date.now();
    }, 30000);
});

onUnmounted(() => {
    if (clockTimer !== null) {
        clearInterval(clockTimer);
    }
});

function endMs(item: ScheduleItemDto): number {
    return item.endsAt
        ? new Date(item.endsAt).getTime()
        : new Date(item.startsAt).getTime() + 3600000;
}

const remaining = computed(() =>
    (props.data.items ?? []).filter((item) => endMs(item) > clock.value),
);
const current = computed(
    () =>
        remaining.value.find(
            (item) => new Date(item.startsAt).getTime() <= clock.value,
        ) ?? null,
);
const next = computed(
    () =>
        remaining.value.find(
            (item) => new Date(item.startsAt).getTime() > clock.value,
        ) ?? null,
);
const later = computed(() =>
    remaining.value.filter(
        (item) => item.id !== current.value?.id && item.id !== next.value?.id,
    ),
);
const listed = computed(() => later.value.slice(0, MAX_LIST_ROWS));
const hiddenCount = computed(() => later.value.length - listed.value.length);
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.schedule_title }}
        </h1>

        <div class="grid grid-cols-2 gap-8">
            <div
                v-if="current"
                class="rounded-xl border-2 border-live bg-card px-8 py-6"
            >
                <p
                    class="font-mono text-2xl tracking-widest text-live uppercase"
                >
                    {{ labels.schedule_now }}
                </p>
                <p class="mt-2 text-4xl font-semibold text-foreground">
                    {{ current.title }}
                </p>
                <p
                    class="mt-2 font-mono text-2xl text-muted-foreground tabular-nums"
                >
                    {{ formatTime(current.startsAt) }}
                    <template v-if="current.endsAt">
                        – {{ formatTime(current.endsAt) }}
                    </template>
                </p>
            </div>

            <div
                v-if="next"
                class="rounded-xl border border-border bg-card/50 px-8 py-6"
            >
                <p
                    class="font-mono text-2xl tracking-widest text-muted-foreground uppercase"
                >
                    {{ labels.schedule_next }}
                </p>
                <p class="mt-2 text-4xl font-semibold text-foreground">
                    {{ next.title }}
                </p>
                <p
                    class="mt-2 font-mono text-2xl text-muted-foreground tabular-nums"
                >
                    {{ formatTime(next.startsAt) }}
                </p>
            </div>
        </div>

        <p v-if="!current && !next" class="text-3xl text-muted-foreground">
            {{ labels.schedule_empty }}
        </p>

        <ul class="mt-4 space-y-3">
            <li
                v-for="item in listed"
                :key="item.id"
                class="flex items-baseline gap-4 text-2xl text-muted-foreground"
            >
                <span class="w-24 shrink-0 font-mono tabular-nums">{{
                    formatTime(item.startsAt)
                }}</span>
                <span>{{ item.title }}</span>
            </li>
        </ul>

        <p v-if="hiddenCount > 0" class="text-2xl text-muted-foreground">
            {{ labels.more_count.replace(':count', String(hiddenCount)) }}
        </p>
    </div>
</template>
