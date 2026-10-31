<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import type { VoiceProviderSetupDto } from '@/types';

defineProps<{
    providers: VoiceProviderSetupDto[];
    labels: Record<string, string>;
}>();

const { isNavigating, hasError } = usePageLoadState();
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer width="default" :title="labels.title">
        <p class="mt-2 text-sm text-muted-foreground">
            {{ labels.intro }}
        </p>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-40 w-full rounded-lg" />
            <Skeleton class="h-40 w-full rounded-lg" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-lg border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">
                {{ labels.load_error }}
            </p>
        </div>

        <template v-else>
            <!-- empty: no active voice provider at all -->
            <div
                v-if="providers.length === 0"
                class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.empty }}
                </p>
            </div>

            <!-- normal -->
            <div v-else class="mt-8 space-y-6">
                <Card v-for="provider in providers" :key="provider.provider">
                    <CardHeader>
                        <CardTitle>{{ provider.label }}</CardTitle>
                        <CardDescription>
                            {{ labels.server_address }}:
                            <span class="font-mono tabular-nums">
                                {{ provider.host }}:{{ provider.port }}
                            </span>
                        </CardDescription>
                        <CardDescription v-if="provider.password">
                            {{ labels.server_password }}:
                            <span class="font-mono tabular-nums select-all">
                                {{ provider.password }}
                            </span>
                        </CardDescription>
                    </CardHeader>

                    <CardContent class="space-y-6">
                        <Button as-child size="lg" class="w-full sm:w-auto">
                            <a :href="provider.joinLink">{{
                                labels.connect
                            }}</a>
                        </Button>

                        <div class="space-y-3 border-t border-border pt-4">
                            <h2 class="text-sm font-medium text-foreground">
                                {{ labels.installers_heading }}
                            </h2>

                            <!-- per-provider empty state: no current installer at all -->
                            <p
                                v-if="provider.installers.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                {{ labels.installers_empty }}
                            </p>

                            <div v-else class="space-y-2">
                                <div
                                    v-for="installer in provider.installers"
                                    :key="installer.id"
                                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-muted/40 px-3 py-2"
                                >
                                    <div class="text-sm">
                                        <span class="font-medium">{{
                                            installer.platformLabel
                                        }}</span>
                                        <span
                                            class="ml-2 text-muted-foreground"
                                        >
                                            {{ labels.version_label }}
                                            <span
                                                class="font-mono tabular-nums"
                                                >{{ installer.version }}</span
                                            >
                                        </span>
                                    </div>
                                    <Button
                                        as-child
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <a
                                            :href="`/voice/installers/${installer.id}/download`"
                                            >{{ labels.download }}</a
                                        >
                                    </Button>
                                </div>
                            </div>

                            <p
                                v-if="provider.provider === 'teamspeak'"
                                class="text-xs text-muted-foreground"
                            >
                                {{ labels.teamspeak_eula_note }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </template>
    </PageContainer>
</template>
