<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
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
import type { GameServerDto } from '@/types';

// Permanent servers run all year, independent of any LAN: one list for every
// logged-in user, whatever the source (Pelican, Docker or external).
const props = defineProps<{
    servers: GameServerDto[];
    labels: Record<string, string>;
}>();

// Inertia renders the first load synchronously, so "loading"/"error" only apply
// to a later client-driven reload (see docs/design.md "States").
const { isNavigating, hasError } = usePageLoadState();
let refreshTimer: ReturnType<typeof setInterval> | undefined;

function refreshServers() {
    if (document.visibilityState === 'visible' && !isNavigating.value) {
        router.reload({ only: ['servers'] });
    }
}

onMounted(() => {
    // Status and players change without navigation: reload the list now and
    // then while the tab is visible.
    refreshTimer = setInterval(refreshServers, 30000);
});

onUnmounted(() => {
    clearInterval(refreshTimer);
});

const liveVariant: Record<GameServerDto['status'], 'ok' | 'warn' | 'down'> = {
    ready: 'ok',
    provisioning: 'warn',
    pending: 'warn',
    failed: 'down',
    stopped: 'down',
    not_responding: 'warn',
    install_failed: 'down',
    offline: 'down',
};

function statusLabel(status: GameServerDto['status']): string {
    return props.labels[`status_${status}`] ?? status;
}

function joinAddress(server: GameServerDto): string | null {
    if (!server.address) {
        return null;
    }

    return server.port ? `${server.address}:${server.port}` : server.address;
}

// Which card just copied which value, so "Kopiert!" shows next to the button
// that was actually pressed; a failed copy says so instead of staying silent.
const copied = ref<{ id: number; kind: string; ok: boolean } | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copy(id: number, kind: string, text: string) {
    let ok = true;

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        ok = false;
    }

    copied.value = { id, kind, ok };
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => {
        copied.value = null;
    }, 1800);
}

function copyLabel(id: number, kind: string, idle: string): string {
    if (copied.value?.id !== id || copied.value.kind !== kind) {
        return idle;
    }

    return copied.value.ok ? props.labels.copied : props.labels.copy_failed;
}

const hasServers = computed(() => props.servers.length > 0);
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="default"
        :title="labels.title"
        :description="labels.intro"
    >
        <!-- loading: only mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-32 w-full rounded-lg" />
            <Skeleton class="h-32 w-full rounded-lg" />
        </div>

        <!-- error -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center text-sm text-destructive"
        >
            {{ labels.error }}
        </div>

        <template v-else>
            <!-- empty -->
            <div
                v-if="!hasServers"
                class="mt-8 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
            >
                {{ labels.empty }}
            </div>

            <!-- normal -->
            <ul v-else class="mt-8 space-y-4">
                <li v-for="server in servers" :key="server.id">
                    <Card>
                        <CardHeader>
                            <div
                                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2"
                            >
                                <CardTitle class="min-w-0 break-words">{{
                                    server.title ?? server.game ?? labels.title
                                }}</CardTitle>
                                <LiveIndicator
                                    :variant="liveVariant[server.status]"
                                    :pulse="server.status === 'provisioning'"
                                    :label="statusLabel(server.status)"
                                />
                            </div>
                            <CardDescription v-if="server.game">
                                {{ server.game }}
                            </CardDescription>
                        </CardHeader>

                        <CardContent class="space-y-3">
                            <p
                                v-if="server.description"
                                class="text-sm whitespace-pre-line text-foreground"
                            >
                                {{ server.description }}
                            </p>

                            <dl
                                class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3"
                            >
                                <div v-if="joinAddress(server)" class="min-w-0">
                                    <dt class="text-muted-foreground">
                                        {{ labels.address_label }}
                                    </dt>
                                    <dd
                                        class="font-mono break-all tabular-nums"
                                    >
                                        {{ joinAddress(server) }}
                                    </dd>
                                </div>
                                <div v-if="server.query">
                                    <dt class="text-muted-foreground">
                                        {{ labels.players_label }}
                                    </dt>
                                    <dd class="font-mono tabular-nums">
                                        {{ server.query.players }}/{{
                                            server.query.maxPlayers
                                        }}
                                    </dd>
                                </div>
                                <div v-if="server.query?.map">
                                    <dt class="text-muted-foreground">
                                        {{ labels.map_label }}
                                    </dt>
                                    <dd class="font-mono break-all">
                                        {{ server.query.map }}
                                    </dd>
                                </div>
                            </dl>

                            <p
                                v-if="server.status === 'offline'"
                                class="text-sm text-muted-foreground"
                            >
                                {{ labels.offline_hint }}
                            </p>
                            <p
                                v-else-if="server.status === 'not_responding'"
                                class="rounded-md border border-warn/40 bg-warn/10 p-3 text-sm text-foreground"
                            >
                                {{ labels.not_responding_hint }}
                            </p>
                            <p
                                v-else-if="server.status === 'provisioning'"
                                class="text-sm text-muted-foreground"
                            >
                                {{ labels.starting_hint }}
                            </p>

                            <div
                                v-if="joinAddress(server)"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <Button
                                    v-if="server.connectIsLink"
                                    as-child
                                    size="sm"
                                >
                                    <a :href="server.connectString">{{
                                        labels.connect
                                    }}</a>
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="
                                        copy(
                                            server.id,
                                            'address',
                                            joinAddress(server) ?? '',
                                        )
                                    "
                                >
                                    {{
                                        copyLabel(
                                            server.id,
                                            'address',
                                            labels.copy_address,
                                        )
                                    }}
                                </Button>
                                <span class="sr-only" aria-live="polite">
                                    {{
                                        copied?.id === server.id
                                            ? copyLabel(
                                                  server.id,
                                                  copied.kind,
                                                  '',
                                              )
                                            : ''
                                    }}
                                </span>
                            </div>

                            <!-- install hint ("So kommst du ran"): only when the game has one -->
                            <div
                                v-if="server.installHint"
                                class="space-y-2 rounded-md border border-border bg-muted/30 p-3 text-sm"
                            >
                                <p class="font-medium text-foreground">
                                    {{ labels.install_hint_label }}
                                </p>

                                <div
                                    v-if="server.installHint.steamUrl"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <Button
                                        as-child
                                        size="sm"
                                        variant="outline"
                                    >
                                        <a
                                            :href="server.installHint.steamUrl"
                                            >{{ labels.install_hint_steam }}</a
                                        >
                                    </Button>
                                </div>

                                <div v-if="server.installHint.shareUrl">
                                    <a
                                        :href="server.installHint.shareUrl"
                                        class="text-xs text-primary underline-offset-4 hover:underline"
                                        >{{ labels.install_hint_download }}</a
                                    >
                                </div>

                                <p
                                    v-if="server.installHint.versionNote"
                                    class="text-muted-foreground"
                                >
                                    {{ server.installHint.versionNote }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </li>
            </ul>
        </template>
    </PageContainer>
</template>
