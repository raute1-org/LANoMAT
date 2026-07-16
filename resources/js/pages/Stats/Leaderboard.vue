<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { LeaderboardRowDto } from '@/types';

const props = defineProps<{
    rows: LeaderboardRowDto[];
    labels: Record<string, string>;
    badgeLabels: Record<string, string>;
}>();

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a subsequent client-driven navigation/refresh — same
// router-lifecycle mirror as the Servers/Index page.
const isNavigating = ref(false);
const hasError = ref(false);

function onStart() {
    hasError.value = false;
    isNavigating.value = true;
}

function onFinish() {
    isNavigating.value = false;
}

function onError() {
    hasError.value = true;
}

let removeStart: (() => void) | undefined;
let removeFinish: (() => void) | undefined;
let removeError: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', onStart);
    removeFinish = router.on('finish', onFinish);
    removeError = router.on('error', onError);
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
    removeError?.();
});

function typeLabel(row: LeaderboardRowDto): string {
    return row.type === 'team'
        ? props.labels.type_team
        : props.labels.type_user;
}

function badgeLabel(badge: string): string {
    return props.badgeLabels[badge] ?? badge;
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }}
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ labels.subtitle }}
        </p>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-2">
            <Skeleton class="h-14 w-full rounded-lg" />
            <Skeleton class="h-14 w-full rounded-lg" />
            <Skeleton class="h-14 w-full rounded-lg" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-lg border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">
                {{ labels.error }}
            </p>
        </div>

        <template v-else>
            <!-- empty -->
            <div
                v-if="rows.length === 0"
                class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.empty }}
                </p>
            </div>

            <!-- normal -->
            <Card v-else class="mt-8">
                <CardHeader>
                    <CardTitle>{{ labels.title }}</CardTitle>
                    <CardDescription>{{ labels.subtitle }}</CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">
                                {{
                                    labels.title
                                }}
                            </caption>
                            <thead>
                                <tr
                                    class="border-b border-border text-left text-xs text-muted-foreground"
                                >
                                    <th
                                        scope="col"
                                        class="w-12 px-4 py-2 font-medium"
                                    >
                                        {{ labels.rank_label }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2 font-medium"
                                    >
                                        {{ labels.name_label }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        {{ labels.wins_label }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        {{ labels.tournament_wins_label }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        {{ labels.participations_label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, index) in rows"
                                    :key="`${row.type}-${row.id}`"
                                    class="border-b border-border last:border-0"
                                >
                                    <td
                                        class="px-4 py-3 font-mono text-muted-foreground tabular-nums"
                                    >
                                        {{ index + 1 }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="font-medium">{{
                                                row.name
                                            }}</span>
                                            <Badge variant="outline">{{
                                                typeLabel(row)
                                            }}</Badge>
                                            <Badge
                                                v-for="badge in row.badges"
                                                :key="badge"
                                                variant="secondary"
                                            >
                                                {{ badgeLabel(badge) }}
                                            </Badge>
                                        </div>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.wins }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.tournamentWins }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono tabular-nums"
                                    >
                                        {{ row.participations }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </template>
    </main>
</template>
