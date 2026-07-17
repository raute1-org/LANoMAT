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

// Tracks which server's install-hint link was just copied, so the "Kopiert!"
// confirmation only flashes next to the button that was actually clicked
// (mirrors the connect-string copy affordance above, keyed per-card instead
// of globally since a page can list several servers at once).
const copiedHintFor = ref<number | null>(null);

async function copyHint(serverId: number, text: string) {
    await copy(text);
    copiedHintFor.value = serverId;
    setTimeout(() => {
        if (copiedHintFor.value === serverId) {
            copiedHintFor.value = null;
        }
    }, 1500);
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

                        <!-- install hint ("So kommst du ran"): rendered only
                             when the game has one configured — nothing shown
                             otherwise, a calm, unobtrusive readout rather than
                             an empty placeholder. -->
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
                                <Button as-child size="sm" variant="outline">
                                    <a :href="server.installHint.steamUrl">{{
                                        labels.install_hint_steam
                                    }}</a>
                                </Button>
                                <code
                                    class="truncate font-mono text-xs text-muted-foreground"
                                    >{{ server.installHint.steamUrl }}</code
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="
                                        copyHint(
                                            server.id,
                                            server.installHint.steamUrl,
                                        )
                                    "
                                >
                                    {{
                                        copiedHintFor === server.id
                                            ? labels.install_hint_copied
                                            : labels.install_hint_copy
                                    }}
                                </Button>
                            </div>

                            <div v-if="server.installHint.shareUrl">
                                <a
                                    :href="server.installHint.shareUrl"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
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
            </div>
        </template>
    </main>
</template>
