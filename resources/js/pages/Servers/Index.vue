<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { GameServerDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    servers: GameServerDto[];
    labels: Record<string, string>;
}>();

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a subsequent client-driven navigation/refresh — mirrored via
// router's global lifecycle events rather than invented polling.
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

const liveVariant: Record<GameServerDto['status'], 'ok' | 'warn' | 'down'> = {
    ready: 'ok',
    provisioning: 'warn',
    pending: 'warn',
    failed: 'down',
    stopped: 'down',
};

function statusLabel(status: GameServerDto['status']): string {
    return props.labels[`status_${status}`] ?? status;
}

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-28 w-full rounded-lg" />
            <Skeleton class="h-28 w-full rounded-lg" />
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
                v-if="servers.length === 0"
                class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.empty }}
                </p>
            </div>

            <!-- normal -->
            <div v-else class="mt-8 space-y-4">
                <Card v-for="server in servers" :key="server.id">
                    <CardHeader>
                        <div class="flex items-center justify-between gap-4">
                            <CardTitle>{{
                                server.game ?? labels.title
                            }}</CardTitle>
                            <LiveIndicator
                                :variant="liveVariant[server.status]"
                                :pulse="server.status === 'provisioning'"
                                :label="statusLabel(server.status)"
                            />
                        </div>
                        <CardDescription v-if="server.matchLabel">
                            {{ server.matchLabel }}
                        </CardDescription>
                    </CardHeader>

                    <CardContent class="space-y-3">
                        <dl
                            class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"
                        >
                            <div v-if="server.address">
                                <dt class="text-muted-foreground">
                                    {{ labels.address_label }}
                                </dt>
                                <dd class="font-mono tabular-nums">
                                    {{ server.address }}
                                </dd>
                            </div>
                            <div v-if="server.port">
                                <dt class="text-muted-foreground">
                                    {{ labels.port_label }}
                                </dt>
                                <dd class="font-mono tabular-nums">
                                    {{ server.port }}
                                </dd>
                            </div>
                            <div
                                v-if="
                                    server.slotsMax !== undefined &&
                                    server.slotsUsed !== undefined
                                "
                            >
                                <dt class="text-muted-foreground">
                                    {{ labels.slots_label }}
                                </dt>
                                <dd class="font-mono tabular-nums">
                                    {{ server.slotsUsed }}/{{ server.slotsMax }}
                                </dd>
                            </div>
                            <div v-if="server.estimate">
                                <dt class="text-muted-foreground">
                                    {{ labels.estimate_label }}
                                </dt>
                                <dd
                                    class="font-mono tabular-nums"
                                    :class="
                                        server.estimate.overCap
                                            ? 'text-warn'
                                            : ''
                                    "
                                >
                                    ~{{ server.estimate.ramMb }} MB
                                    <span v-if="server.estimate.overCap">
                                        · {{ labels.estimate_over_cap }}</span
                                    >
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="server.connectString"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Button as-child size="sm">
                                <a :href="server.connectString">{{
                                    labels.connect
                                }}</a>
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="copy(server.connectString)"
                            >
                                {{ labels.copy }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </template>
    </main>
</template>
