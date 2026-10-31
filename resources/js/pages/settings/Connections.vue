<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ConnectionCard from '@/components/settings/ConnectionCard.vue';
import type { ConnectionProvider } from '@/components/settings/ConnectionCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { edit } from '@/routes/connections';

const props = defineProps<{
    providers: ConnectionProvider[];
    labels: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Verknüpfungen',
                href: edit(),
            },
        ],
    },
});

const { isNavigating, hasError } = usePageLoadState();
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
