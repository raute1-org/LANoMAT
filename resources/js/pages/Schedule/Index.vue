<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CalendarPlus, Star } from '@lucide/vue';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dayKey, formatInstant } from '@/lib/datetime';
import { favorite, ics, unfavorite } from '@/routes/schedule';
import type { ScheduleItemDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    items: ScheduleItemDto[];
    now: ScheduleItemDto | null;
    next: ScheduleItemDto | null;
    labels: Record<string, string>;
    hasFavorites: boolean;
}>();

const page = usePage();
const isAuthenticated = computed(() => page.props.auth.user !== null);

// "My schedule": a client-side filter over the favorited items (the star
// already stores them); the ICS has a matching favorites-only link.
const onlyMine = ref(false);
const visibleItems = computed(() =>
    onlyMine.value ? props.items.filter((item) => item.mine) : props.items,
);
const icsAllUrl = computed(() => ics.url({ event: props.event.slug }));
const icsFavoritesUrl = computed(() =>
    ics.url({ event: props.event.slug }, { query: { favorites: 1 } }),
);

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
    return formatInstant(iso, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

// Date plus time when the instant is not today, so "Jetzt/Gleich" never hides
// that an item is on another day.
function formatWhen(iso: string): string {
    if (dayKey(iso) === dayKey(new Date())) {
        return formatTime(iso);
    }

    return formatInstant(iso, {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// End time; an end on a later day than the start is spelled out.
function formatEnd(item: ScheduleItemDto): string {
    if (item.endsAt === null) {
        return '';
    }

    return dayKey(item.endsAt) === dayKey(item.startsAt)
        ? formatTime(item.endsAt)
        : formatWhen(item.endsAt);
}

function formatDay(iso: string): string {
    return formatInstant(iso, {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
    });
}

const groupedItems = computed(() => {
    const groups = new Map<string, { day: string; items: ScheduleItemDto[] }>();

    for (const item of visibleItems.value) {
        const key = dayKey(item.startsAt);

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

    <PageContainer width="default" :title="`${labels.title} — ${event.name}`">
        <div v-if="now || next" class="mt-8 grid gap-4 sm:grid-cols-2">
            <Card v-if="now" class="border-primary">
                <CardHeader>
                    <LiveIndicator variant="live" :label="labels.now" />
                    <CardTitle class="mt-2">{{ now.title }}</CardTitle>
                    <CardDescription>
                        <span class="font-mono tabular-nums">
                            {{ formatWhen(now.startsAt) }}
                            <template v-if="now.endsAt">
                                – {{ formatEnd(now) }}
                            </template>
                        </span>
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
                        <span class="font-mono tabular-nums">{{
                            formatWhen(next.startsAt)
                        }}</span>
                        <template v-if="next.location">
                            · {{ next.location }}
                        </template>
                    </CardDescription>
                </CardHeader>
            </Card>
        </div>

        <div
            v-if="items.length > 0"
            class="mt-8 flex flex-wrap items-center gap-2"
            data-test="schedule-toolbar"
        >
            <Button
                v-if="isAuthenticated"
                type="button"
                size="sm"
                :variant="onlyMine ? 'default' : 'outline'"
                :aria-pressed="onlyMine"
                @click="onlyMine = !onlyMine"
            >
                <Star class="size-4" />
                {{ onlyMine ? labels.all_items : labels.only_mine }}
            </Button>
            <Button as-child size="sm" variant="outline">
                <a :href="icsAllUrl" download>
                    <CalendarPlus class="size-4" />
                    {{ labels.add_to_calendar }}
                </a>
            </Button>
            <Button
                v-if="isAuthenticated && hasFavorites"
                as-child
                size="sm"
                variant="outline"
            >
                <a :href="icsFavoritesUrl" download>
                    <CalendarPlus class="size-4" />
                    {{ labels.add_favorites_to_calendar }}
                </a>
            </Button>
        </div>

        <div
            v-if="items.length === 0"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.empty }}
            </p>
        </div>

        <div
            v-else-if="visibleItems.length === 0"
            class="mt-6 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.no_favorites }}
            </p>
        </div>

        <div v-else class="mt-6 space-y-8">
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
                                <span class="font-mono tabular-nums">
                                    {{ formatTime(item.startsAt) }}
                                    <template v-if="item.endsAt">
                                        – {{ formatEnd(item) }}
                                    </template>
                                </span>
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
    </PageContainer>
</template>
