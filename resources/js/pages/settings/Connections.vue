<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ConnectionCard from '@/components/settings/ConnectionCard.vue';
import type { ConnectionProvider } from '@/components/settings/ConnectionCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { edit } from '@/routes/connections';

const props = defineProps<{
    providers: ConnectionProvider[];
    labels: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Connections settings',
                href: edit(),
            },
        ],
    },
});

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a subsequent client-driven navigation/refresh (e.g. after
// unlinking) — same router-lifecycle mirror as Voice/Setup.vue.
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
let removeErrorListener: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', onStart);
    removeFinish = router.on('finish', onFinish);
    removeErrorListener = router.on('error', onError);
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
    removeErrorListener?.();
});
</script>

<template>
    <Head :title="props.labels.title" />

    <h1 class="sr-only">{{ props.labels.title }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="props.labels.title"
            :description="props.labels.description"
        />

        <!-- loading: only shown mid-navigation, e.g. after unlinking -->
        <div
            v-if="isNavigating"
            class="space-y-4"
            data-test="connections-loading"
        >
            <Skeleton class="h-28 w-full rounded-lg" />
            <Skeleton class="h-28 w-full rounded-lg" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="rounded-lg border border-destructive/50 bg-destructive/10 p-8 text-center"
            data-test="connections-error"
        >
            <p class="text-sm text-destructive">
                {{ props.labels.load_error }}
            </p>
        </div>

        <template v-else>
            <!-- empty: no linkable provider is configured at all -->
            <div
                v-if="props.providers.length === 0"
                class="rounded-lg border border-dashed border-border p-8 text-center"
                data-test="connections-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{ props.labels.empty }}
                </p>
            </div>

            <!-- normal: one card per configured provider -->
            <div v-else class="space-y-4">
                <ConnectionCard
                    v-for="provider in props.providers"
                    :key="provider.provider"
                    :connection="provider"
                    :labels="props.labels"
                />
            </div>
        </template>
    </div>
</template>
