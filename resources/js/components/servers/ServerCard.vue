<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import JoinOptions from '@/components/servers/JoinOptions.vue';
import PlayerListDisclosure from '@/components/servers/PlayerListDisclosure.vue';
import ServerInstallActions from '@/components/servers/ServerInstallActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { GlobalServerRowDto } from '@/types';

/**
 * One row of the global server browser (`Servers/Global`). Compact on purpose:
 * the page groups many servers by game, so the card carries only what a player
 * needs to decide — status, map, players, address and the way in — plus a link
 * to the event the server belongs to. Join links come from `JoinOptions`
 * (server-built `JoinLinkTemplates`), never built here.
 */
const props = defineProps<{
    server: GlobalServerRowDto;
    /** `gameservers.browser` + `gameservers.page` + `gameservers.join_ui` labels. */
    labels: Record<string, string>;
}>();

const liveVariant: Record<
    GlobalServerRowDto['status'],
    'ok' | 'warn' | 'down'
> = {
    ready: 'ok',
    provisioning: 'warn',
    pending: 'warn',
    failed: 'down',
    stopped: 'down',
    not_responding: 'warn',
    install_failed: 'down',
    offline: 'down',
};

const statusLabel = computed(
    () => props.labels[`status_${props.server.status}`] ?? props.server.status,
);

const name = computed(
    () =>
        props.server.title ??
        props.server.query?.serverName ??
        props.server.game ??
        props.labels.title,
);

const address = computed(() => {
    if (!props.server.address) {
        return null;
    }

    return props.server.port
        ? `${props.server.address}:${props.server.port}`
        : props.server.address;
});

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copyConnectString() {
    try {
        await navigator.clipboard.writeText(props.server.connectString);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => {
            copied.value = false;
        }, 1800);
    } catch {
        copied.value = false;
    }
}

onUnmounted(() => clearTimeout(copiedTimer));
</script>

<template>
    <article class="rounded-md border border-border bg-card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold text-foreground">
                    {{ name }}
                </h3>
                <p
                    class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                >
                    <span v-if="server.game">{{ server.game }}</span>
                    <span
                        v-if="server.game && server.matchLabel"
                        aria-hidden="true"
                        >·</span
                    >
                    <span v-if="server.matchLabel">{{
                        server.matchLabel
                    }}</span>
                    <Badge
                        v-if="server.permanent"
                        variant="outline"
                        class="text-xs"
                    >
                        {{ labels.permanent_badge }}
                    </Badge>
                </p>
            </div>

            <LiveIndicator
                :variant="liveVariant[server.status]"
                :pulse="server.status === 'provisioning'"
                :label="statusLabel"
            />
        </div>

        <p
            v-if="server.description"
            class="mt-2 text-sm whitespace-pre-line text-muted-foreground"
        >
            {{ server.description }}
        </p>

        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div v-if="server.query?.map">
                <dt class="text-xs text-muted-foreground">
                    {{ labels.map_label }}
                </dt>
                <dd class="truncate font-mono text-foreground">
                    {{ server.query.map }}
                </dd>
            </div>
            <div v-if="server.query">
                <dt class="text-xs text-muted-foreground">
                    {{ labels.players_label }}
                </dt>
                <dd class="font-mono text-foreground tabular-nums">
                    {{ server.query.players }}/{{ server.query.maxPlayers }}
                </dd>
            </div>
            <div v-if="address" class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    {{ labels.address_label }}
                </dt>
                <dd class="truncate font-mono text-foreground">
                    {{ address }}
                </dd>
            </div>
            <div v-if="server.event" class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    {{ labels.event_label }}
                </dt>
                <dd class="min-w-0">
                    <Link
                        :href="`/servers?event=${encodeURIComponent(server.event.slug)}`"
                        class="block truncate underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ server.event.name }}
                    </Link>
                </dd>
            </div>
        </dl>

        <PlayerListDisclosure
            class="mt-3"
            :query="server.query ?? null"
            :labels="labels"
        />

        <p
            v-if="server.status === 'not_responding'"
            class="mt-3 rounded-md border border-warn/40 bg-warn/10 p-3 text-sm text-foreground"
        >
            {{ labels.not_responding_hint }}
        </p>
        <p
            v-else-if="server.status === 'provisioning'"
            class="mt-3 text-sm text-muted-foreground"
        >
            {{ labels.starting_hint }}
        </p>
        <p
            v-else-if="server.status === 'offline'"
            class="mt-3 text-sm text-muted-foreground"
        >
            {{ labels.offline_hint }}
        </p>

        <JoinOptions
            v-if="server.joinOptions?.length && address"
            class="mt-3"
            :options="server.joinOptions"
            :address="address"
            :labels="labels"
            handler-help
        />
        <div
            v-else-if="server.connectString"
            class="mt-3 flex flex-wrap items-center gap-2"
        >
            <Button v-if="server.connectIsLink" as-child size="sm">
                <a :href="server.connectString">{{ labels.connect }}</a>
            </Button>
            <code
                v-else
                class="rounded-md bg-muted px-2 py-1 font-mono text-sm"
                >{{ server.connectString }}</code
            >
            <Button
                type="button"
                size="sm"
                variant="outline"
                @click="copyConnectString"
            >
                <Check v-if="copied" class="size-3.5 text-ok" />
                <Copy v-else class="size-3.5" />
                {{ copied ? labels.copied : labels.copy }}
            </Button>
        </div>

        <!-- The recipe way in comes before the hand-written install hint. -->
        <ServerInstallActions
            v-if="server.install"
            class="mt-3"
            :install="server.install"
            :labels="labels"
        />

        <details
            v-if="server.installHint"
            class="group mt-3 rounded-md border border-border bg-muted/30 text-sm"
        >
            <summary
                class="flex min-h-10 cursor-pointer list-none items-center justify-between gap-2 rounded-md px-3 py-2 font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ labels.install_hint_label }}
                <span
                    aria-hidden="true"
                    class="text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
                    >▾</span
                >
            </summary>
            <div class="space-y-2 border-t border-border px-3 py-2">
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
                </div>
                <a
                    v-if="server.installHint.shareUrl"
                    :href="server.installHint.shareUrl"
                    class="block text-xs text-primary underline-offset-4 hover:underline"
                    >{{ labels.install_hint_download }}</a
                >
                <p
                    v-if="server.installHint.versionNote"
                    class="text-xs text-muted-foreground"
                >
                    {{ server.installHint.versionNote }}
                </p>
            </div>
        </details>
    </article>
</template>
