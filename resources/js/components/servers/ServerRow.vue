<script setup lang="ts">
import { Check, ChevronDown, Copy } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import JoinOptions from '@/components/servers/JoinOptions.vue';
import PlayerListDisclosure from '@/components/servers/PlayerListDisclosure.vue';
import ServerAdminControls from '@/components/servers/ServerAdminControls.vue';
import { Button } from '@/components/ui/button';
import { useJoinOption } from '@/composables/useJoinOption';
import type { GameServerDto } from '@/types';

/**
 * One compact server row of the overview (`Servers/Index`): status, title,
 * map, players, address and the connect/copy actions; the detail panel
 * (player list, join options, hints, install hint, admin controls) opens
 * inline. The parent owns which row is open.
 */
const props = defineProps<{
    server: GameServerDto;
    labels: Record<string, string>;
    permanentLabels: Record<string, string>;
    overviewLabels: Record<string, string>;
    adminMaps: string[] | null;
    /** Amber "Verbinden" — only for the viewer's own match. */
    primary: boolean;
    open: boolean;
    /** Event servers show their RAM estimate; permanent ones do not. */
    showEstimate: boolean;
}>();

const emit = defineEmits<{ toggle: [] }>();

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

const statusText = computed(
    () => props.labels[`status_${props.server.status}`] ?? props.server.status,
);

const title = computed(
    () => props.server.title ?? props.server.matchLabel ?? props.server.game,
);

// Muted subline: never repeat the title.
const subline = computed(() => {
    const parts = [
        props.server.matchLabel,
        props.server.game,
        props.server.description,
    ].filter((part): part is string => !!part && part !== title.value);

    return props.server.description ? parts.slice(-1)[0] : parts[0];
});

const address = computed(() => {
    if (!props.server.address) {
        return null;
    }

    return props.server.port
        ? `${props.server.address}:${props.server.port}`
        : props.server.address;
});

const joinable = computed(
    () =>
        (props.server.status === 'ready' ||
            props.server.status === 'not_responding') &&
        !!props.server.connectString,
);

const { selected: joinOption } = useJoinOption(
    () => props.server.joinOptions ?? [],
);

// The row's "Verbinden": the chosen way in when the game has several, else the
// plain connect link. A plain address:port has no link — copy only.
const joinHref = computed(() => {
    if (joinOption.value) {
        return joinOption.value.url;
    }

    return props.server.connectIsLink ? props.server.connectString : null;
});

// A plain connect string that says more than address:port (password, console
// command): shown in the panel and copied instead of the bare address.
const plainConnect = computed(() =>
    !props.server.connectIsLink &&
    props.server.connectString &&
    props.server.connectString !== address.value
        ? props.server.connectString
        : null,
);

const copyText = computed(
    () => plainConnect.value ?? address.value ?? props.server.connectString,
);

const panelId = computed(() => `server-panel-${props.server.id}`);

const copyState = ref<'idle' | 'ok' | 'failed'>('idle');
let timer: ReturnType<typeof setTimeout> | undefined;

async function copyAddress() {
    try {
        await navigator.clipboard.writeText(copyText.value);
        copyState.value = 'ok';
    } catch {
        copyState.value = 'failed';
    }

    clearTimeout(timer);
    timer = setTimeout(() => {
        copyState.value = 'idle';
    }, 1500);
}

onUnmounted(() => clearTimeout(timer));

const copiedHint = ref(false);
let hintTimer: ReturnType<typeof setTimeout> | undefined;

async function copyHint(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copiedHint.value = true;
        clearTimeout(hintTimer);
        hintTimer = setTimeout(() => {
            copiedHint.value = false;
        }, 1500);
    } catch {
        copiedHint.value = false;
    }
}

onUnmounted(() => clearTimeout(hintTimer));
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2.5">
            <button
                type="button"
                class="flex min-h-10 min-w-0 grow basis-60 items-center gap-3 rounded-sm text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="open"
                :aria-controls="panelId"
                @click="emit('toggle')"
            >
                <LiveIndicator
                    :variant="liveVariant[server.status]"
                    :pulse="server.status === 'provisioning'"
                    aria-hidden="true"
                />
                <span class="sr-only">{{ statusText }}</span>
                <span class="min-w-0">
                    <span
                        class="block truncate text-sm font-medium text-foreground"
                        >{{ title ?? labels.title }}</span
                    >
                    <span
                        v-if="subline"
                        class="block truncate text-xs text-muted-foreground"
                        >{{ subline }}</span
                    >
                </span>
                <ChevronDown
                    class="ml-auto size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </button>

            <template v-if="joinable">
                <span
                    class="hidden w-28 truncate font-mono text-xs text-muted-foreground lg:block"
                    >{{ server.query?.map ?? '' }}</span
                >
                <span
                    class="font-mono text-sm whitespace-nowrap tabular-nums sm:w-14 sm:text-right"
                    ><span class="sr-only">{{ labels.players_label }}: </span
                    >{{
                        server.query
                            ? `${server.query.players}/${server.query.maxPlayers}`
                            : '–'
                    }}</span
                >
                <span class="hidden w-48 truncate font-mono text-xs xl:block">{{
                    address
                }}</span>
                <span class="ml-auto flex items-center gap-1.5 sm:ml-0">
                    <Button
                        v-if="joinHref"
                        as-child
                        size="sm"
                        :variant="primary ? 'default' : 'outline'"
                    >
                        <a :href="joinHref">{{ labels.connect }}</a>
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        class="size-8"
                        :aria-label="
                            copyState === 'failed'
                                ? permanentLabels.copy_failed
                                : permanentLabels.copy_address
                        "
                        :title="
                            copyState === 'failed'
                                ? permanentLabels.copy_failed
                                : permanentLabels.copy_address
                        "
                        @click="copyAddress"
                    >
                        <Check
                            v-if="copyState === 'ok'"
                            class="size-4 text-ok"
                        />
                        <Copy v-else class="size-4" />
                    </Button>
                    <span class="sr-only" aria-live="polite">{{
                        copyState === 'ok'
                            ? permanentLabels.copied
                            : copyState === 'failed'
                              ? permanentLabels.copy_failed
                              : ''
                    }}</span>
                </span>
            </template>
            <span
                v-else
                class="ml-auto text-sm text-muted-foreground sm:ml-0 sm:w-auto"
                >{{ statusText }}</span
            >
        </div>

        <div
            v-if="open"
            :id="panelId"
            class="space-y-3 px-3 pt-1 pb-3 pl-8 text-sm"
        >
            <p
                v-if="server.description"
                class="whitespace-pre-line text-foreground"
            >
                {{ server.description }}
            </p>

            <div v-if="joinable && address" class="space-y-1">
                <p class="text-xs text-muted-foreground">
                    {{ overviewLabels.connect_other }}
                </p>
                <p class="font-mono text-xs break-all">{{ address }}</p>
                <p
                    v-if="plainConnect"
                    class="font-mono text-xs break-all text-muted-foreground"
                >
                    {{ plainConnect }}
                </p>
            </div>

            <PlayerListDisclosure
                :query="server.query ?? null"
                :labels="labels"
                default-open
            />

            <p v-if="server.status === 'offline'" class="text-muted-foreground">
                {{ permanentLabels.offline_hint }}
            </p>
            <p
                v-else-if="server.status === 'not_responding'"
                class="rounded-md border border-warn/40 bg-warn/10 p-3 text-foreground"
            >
                {{ labels.not_responding_hint }}
            </p>
            <p
                v-else-if="server.status === 'provisioning'"
                class="text-muted-foreground"
            >
                {{ labels.starting_hint }}
            </p>
            <p
                v-else-if="joinable && !server.connectIsLink"
                class="text-muted-foreground"
            >
                {{ labels.connect_plain_hint }}
            </p>

            <JoinOptions
                v-if="joinable && server.joinOptions?.length && address"
                :options="server.joinOptions"
                :address="address"
                :labels="labels"
                handler-help
                :primary="false"
            />

            <div
                v-if="server.installHint"
                class="space-y-2 rounded-md border border-border bg-muted/30 p-3"
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
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="copyHint(server.installHint.steamUrl)"
                    >
                        {{
                            copiedHint
                                ? labels.install_hint_copied
                                : labels.install_hint_copy
                        }}
                    </Button>
                </div>

                <div v-if="server.installHint.shareUrl">
                    <a
                        :href="server.installHint.shareUrl"
                        class="text-xs text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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

            <p
                v-if="showEstimate && server.estimate"
                class="font-mono text-xs tabular-nums"
                :class="
                    server.estimate.overCap
                        ? 'text-warn'
                        : 'text-muted-foreground'
                "
            >
                {{ labels.estimate_label }}: ~{{ server.estimate.ramMb }} MB
                <span v-if="server.estimate.overCap">
                    · {{ labels.estimate_over_cap }}</span
                >
            </p>

            <ServerAdminControls
                v-if="server.canManage && server.admin && adminMaps"
                :server="server"
                :admin="server.admin"
                :maps="adminMaps"
                :labels="permanentLabels"
            />
        </div>
    </div>
</template>
