<script setup lang="ts">
import type { ScheduleItemDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: {
        items?: ScheduleItemDto[];
        now?: ScheduleItemDto | null;
        next?: ScheduleItemDto | null;
    };
    labels: Record<string, string>;
}>();

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

const upcoming = () =>
    (props.data.items ?? []).filter(
        (item) =>
            item.id !== props.data.now?.id && item.id !== props.data.next?.id,
    );
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.schedule_title }}
        </h1>

        <div class="grid grid-cols-2 gap-8">
            <div
                v-if="data.now"
                class="rounded-xl border-2 border-live bg-card px-8 py-6"
            >
                <p
                    class="font-mono text-2xl tracking-widest text-live uppercase"
                >
                    {{ labels.schedule_now }}
                </p>
                <p class="mt-2 text-4xl font-semibold text-foreground">
                    {{ data.now.title }}
                </p>
                <p
                    class="mt-2 font-mono text-2xl text-muted-foreground tabular-nums"
                >
                    {{ formatTime(data.now.startsAt) }}
                    <template v-if="data.now.endsAt">
                        – {{ formatTime(data.now.endsAt) }}
                    </template>
                </p>
            </div>

            <div
                v-if="data.next"
                class="rounded-xl border border-border bg-card/50 px-8 py-6"
            >
                <p
                    class="font-mono text-2xl tracking-widest text-muted-foreground uppercase"
                >
                    {{ labels.schedule_next }}
                </p>
                <p class="mt-2 text-4xl font-semibold text-foreground">
                    {{ data.next.title }}
                </p>
                <p
                    class="mt-2 font-mono text-2xl text-muted-foreground tabular-nums"
                >
                    {{ formatTime(data.next.startsAt) }}
                </p>
            </div>
        </div>

        <p
            v-if="!data.now && !data.next"
            class="text-3xl text-muted-foreground"
        >
            {{ labels.schedule_empty }}
        </p>

        <ul class="mt-4 flex-1 space-y-3 overflow-auto">
            <li
                v-for="item in upcoming()"
                :key="item.id"
                class="flex items-baseline gap-4 text-2xl text-muted-foreground"
            >
                <span class="w-24 shrink-0 font-mono tabular-nums">{{
                    formatTime(item.startsAt)
                }}</span>
                <span>{{ item.title }}</span>
            </li>
        </ul>
    </div>
</template>
