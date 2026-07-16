<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Star } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { favorite, unfavorite } from '@/routes/schedule';
import type { ScheduleItemDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    items: ScheduleItemDto[];
    now: ScheduleItemDto | null;
    next: ScheduleItemDto | null;
    labels: Record<string, string>;
}>();

const page = usePage();
const isAuthenticated = computed(() => page.props.auth.user !== null);

function toggleFavorite(item: ScheduleItemDto) {
    if (item.mine) {
        router.delete(unfavorite.url({ item: item.id }), {
            preserveScroll: true,
            preserveState: true,
        });
    } else {
        router.post(
            favorite.url({ item: item.id }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDay(iso: string): string {
    return new Date(iso).toLocaleDateString([], {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
    });
}

const groupedItems = computed(() => {
    const groups = new Map<string, { day: string; items: ScheduleItemDto[] }>();

    for (const item of props.items) {
        const key = new Date(item.startsAt).toDateString();

        if (!groups.has(key)) {
            groups.set(key, { day: formatDay(item.startsAt), items: [] });
        }

        groups.get(key)?.items.push(item);
    }

    return [...groups.values()];
});
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <div v-if="now || next" class="mt-8 grid gap-4 sm:grid-cols-2">
            <Card v-if="now" class="border-primary">
                <CardHeader>
                    <Badge>{{ labels.now }}</Badge>
                    <CardTitle class="mt-2">{{ now.title }}</CardTitle>
                    <CardDescription>
                        {{ formatTime(now.startsAt) }}
                        <template v-if="now.endsAt">
                            – {{ formatTime(now.endsAt) }}
                        </template>
                        <template v-if="now.location">
                            · {{ now.location }}
                        </template>
                    </CardDescription>
                </CardHeader>
            </Card>

            <Card v-if="next">
                <CardHeader>
                    <Badge variant="outline">{{ labels.next }}</Badge>
                    <CardTitle class="mt-2">{{ next.title }}</CardTitle>
                    <CardDescription>
                        {{ formatTime(next.startsAt) }}
                        <template v-if="next.location">
                            · {{ next.location }}
                        </template>
                    </CardDescription>
                </CardHeader>
            </Card>
        </div>

        <p v-if="items.length === 0" class="mt-6 text-sm text-muted-foreground">
            {{ labels.empty }}
        </p>

        <div v-else class="mt-10 space-y-8">
            <section v-for="group in groupedItems" :key="group.day">
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ group.day }}
                </h2>

                <ul
                    class="mt-3 divide-y divide-border rounded-lg border border-border"
                >
                    <li
                        v-for="item in group.items"
                        :key="item.id"
                        class="flex items-center justify-between gap-4 px-4 py-4"
                    >
                        <div>
                            <p class="font-medium">{{ item.title }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ formatTime(item.startsAt) }}
                                <template v-if="item.endsAt">
                                    – {{ formatTime(item.endsAt) }}
                                </template>
                                <template v-if="item.location">
                                    · {{ item.location }}
                                </template>
                            </p>
                            <p
                                v-if="item.description"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                {{ item.description }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <Badge variant="outline">{{
                                item.typeLabel
                            }}</Badge>

                            <Button
                                v-if="isAuthenticated"
                                type="button"
                                size="icon"
                                variant="ghost"
                                :aria-label="
                                    item.mine
                                        ? labels.unfavorite
                                        : labels.favorite
                                "
                                :title="
                                    item.mine
                                        ? labels.unfavorite
                                        : labels.favorite
                                "
                                @click="toggleFavorite(item)"
                            >
                                <Star
                                    :class="[
                                        'size-4',
                                        item.mine
                                            ? 'fill-primary text-primary'
                                            : 'text-muted-foreground',
                                    ]"
                                />
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </main>
</template>
