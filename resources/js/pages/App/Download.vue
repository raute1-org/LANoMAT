<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, Download, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { formatInstant } from '@/lib/datetime';

type Os = 'linux' | 'windows' | 'macos';

type Build = {
    name: string;
    os: Os;
    arch: string;
    kind: 'app' | 'cli';
    variant: 'installer' | 'portable' | 'archive';
    size: number;
    sha256: string;
    url: string;
    signed: boolean | null;
};

/** One thing to download. Today two (voice app, launcher); later possibly one. */
type DownloadEntry = {
    key: 'voice' | 'launcher';
    state: 'none' | 'mismatch' | 'current';
    exact: boolean;
    release: {
        version: string;
        commit: string;
        apiVersion?: number;
        builtAt: string;
    } | null;
    builds: Build[];
};

type Labels = Record<string, any>;

const props = defineProps<{
    downloads: DownloadEntry[];
    server: { name: string; url: string; version: string };
    detectedOs: Os | null;
    pairUrl: string | null;
    labels: Labels;
}>();

const { isNavigating, hasError } = usePageLoadState();

const platforms: Os[] = ['windows', 'linux', 'macos'];
const selected = ref<Os>(props.detectedOs ?? 'windows');

const nothingPublished = computed(() =>
    props.downloads.every((entry) => entry.state === 'none'),
);

// The Windows app comes first as installer, then as portable exe, then as an archive.
const order = (build: Build): number =>
    build.kind === 'cli'
        ? 3
        : ({ installer: 0, portable: 1, archive: 2 } as const)[build.variant];

function shown(entry: DownloadEntry): Build[] {
    return entry.builds
        .filter((build) => build.os === selected.value)
        .sort((a, b) => order(a) - order(b));
}

// One amber primary action on the screen: the first build of the first section that has one.
const primaryName = computed<string | null>(() => {
    for (const entry of props.downloads) {
        const first = shown(entry)[0];

        if (first !== undefined) {
            return first.name;
        }
    }

    return null;
});

const copied = ref<string | null>(null);

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = value;
        setTimeout(() => {
            if (copied.value === value) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        // No clipboard permission: the value stays selectable on the page.
    }
}

function megabytes(bytes: number): string {
    return `${(bytes / 1024 / 1024).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB`;
}

function builtAt(entry: DownloadEntry): string {
    return entry.release === null
        ? ''
        : formatInstant(entry.release.builtAt, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}

function meta(build: Build): string {
    const parts = [build.arch, megabytes(build.size)];

    if (build.os === 'windows') {
        parts.push(
            build.signed === true
                ? props.labels.signed_label
                : props.labels.unsigned_label,
        );
    }

    return parts.join(', ');
}

function title(entry: DownloadEntry, build: Build): string {
    const own = props.labels.downloads[entry.key];

    if (build.kind === 'cli') {
        return own.kind_cli;
    }

    if (build.variant === 'installer') {
        return props.labels.variant_installer;
    }

    if (build.variant === 'portable') {
        return props.labels.variant_portable;
    }

    return build.os === 'windows'
        ? `${own.kind_app} (${props.labels.variant_archive})`
        : own.kind_app;
}

const hint = (build: Build): string | null =>
    build.kind === 'app' && build.variant === 'installer'
        ? props.labels.variant_installer_hint
        : build.kind === 'app' && build.variant === 'portable'
          ? props.labels.variant_portable_hint
          : null;

// Install steps of one download, as keys into `labels.steps`.
function steps(build: Build): string[] {
    if (build.kind === 'cli') {
        return ['cli_extract', 'cli_start'];
    }

    if (build.os === 'macos') {
        return ['macos_extract', 'macos_open'];
    }

    if (build.os === 'linux') {
        return ['linux_extract', 'linux_chmod', 'linux_start'];
    }

    if (build.variant === 'installer') {
        return ['win_installer_run', 'win_smartscreen', 'win_installer_start'];
    }

    if (build.variant === 'portable') {
        return ['win_portable_place', 'win_portable_start', 'win_smartscreen'];
    }

    return ['win_extract', 'win_start', 'win_smartscreen'];
}

function step(key: string, build: Build): string {
    return String(props.labels.steps[key]).replace(':name', build.name);
}

const unsigned = (entry: DownloadEntry): boolean =>
    selected.value === 'windows' &&
    shown(entry).some((build) => build.signed === false);

const hasWindowsApp = (entry: DownloadEntry): boolean =>
    selected.value === 'windows' &&
    shown(entry).some((build) => build.kind === 'app');

function retry(): void {
    router.reload();
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="default"
        :title="labels.title"
        :description="labels.description"
    >
        <!-- loading -->
        <div
            v-if="isNavigating"
            class="mt-6 space-y-3"
            role="status"
            :aria-label="labels.loading"
        >
            <Skeleton class="h-10 w-full" />
            <Skeleton class="h-32 w-full" />
            <Skeleton class="h-32 w-full" />
        </div>

        <!-- error -->
        <div
            v-else-if="hasError"
            class="mt-6 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center"
            role="alert"
        >
            <p class="text-sm text-destructive">{{ labels.error }}</p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="mt-3"
                @click="retry"
            >
                {{ labels.retry }}
            </Button>
        </div>

        <!-- empty: nothing published at all -->
        <div
            v-else-if="nothingPublished"
            class="mt-6 flex items-start gap-3 rounded-md border border-warn/60 bg-warn/10 p-4 text-sm"
            role="alert"
            data-test="app-none"
        >
            <TriangleAlert
                class="mt-0.5 size-5 shrink-0 text-warn"
                aria-hidden="true"
            />
            <div>
                <p class="font-medium">{{ labels.none_all_title }}</p>
                <p class="mt-1 text-muted-foreground">
                    {{ labels.none_all_body }}
                </p>
            </div>
        </div>

        <!-- normal -->
        <template v-else>
            <div
                class="mt-6 flex flex-wrap items-center gap-2"
                role="tablist"
                :aria-label="labels.platforms"
            >
                <button
                    v-for="os in platforms"
                    :id="`tab-${os}`"
                    :key="os"
                    type="button"
                    role="tab"
                    :aria-selected="selected === os"
                    :data-test="`app-tab-${os}`"
                    :class="[
                        'rounded-md border px-3 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-10',
                        selected === os
                            ? 'border-primary bg-primary/10 font-medium'
                            : 'border-border text-muted-foreground hover:text-foreground',
                    ]"
                    @click="selected = os"
                >
                    {{ labels[`os_${os}`] }}
                </button>
                <span
                    v-if="detectedOs"
                    class="ml-1 text-xs text-muted-foreground"
                    data-test="app-detected"
                >
                    {{ labels.detected }}: {{ labels[`os_${detectedOs}`] }}
                </span>
            </div>

            <section
                v-for="entry in downloads"
                :key="entry.key"
                class="mt-6 rounded-md border border-border bg-card"
                role="tabpanel"
                :aria-labelledby="`download-${entry.key}`"
                :data-test="`app-download-${entry.key}`"
            >
                <div class="p-4">
                    <h2
                        :id="`download-${entry.key}`"
                        class="text-lg font-semibold tracking-tight"
                    >
                        {{ labels.downloads[entry.key].title }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ labels.downloads[entry.key].text }}
                    </p>
                </div>

                <!-- empty: this download is not published -->
                <p
                    v-if="entry.state === 'none'"
                    class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
                    :data-test="`app-none-${entry.key}`"
                >
                    {{ labels.downloads[entry.key].none }}
                    <Link
                        v-if="entry.key === 'launcher'"
                        href="/games"
                        class="underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {{ labels.downloads.launcher.games_link }}
                    </Link>
                </p>

                <template v-else>
                    <!-- warning: built for another server version -->
                    <div
                        v-if="entry.state === 'mismatch'"
                        class="flex items-start gap-3 border-t border-warn/60 bg-warn/10 p-4 text-sm"
                        role="alert"
                        :data-test="`app-mismatch-${entry.key}`"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-5 shrink-0 text-warn"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="font-medium">
                                {{ labels.mismatch_title }}
                            </p>
                            <p class="mt-1 text-muted-foreground">
                                {{ labels.mismatch_body }}
                            </p>
                            <p class="mt-2 font-mono text-xs">
                                {{ labels.server_version }}:
                                {{ server.version }} /
                                {{ labels.client_version }}:
                                {{ entry.release?.version }}
                            </p>
                        </div>
                    </div>

                    <!-- compatible: built for another server version, same API -->
                    <p
                        v-if="entry.state === 'current' && !entry.exact"
                        class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
                        :data-test="`app-compatible-${entry.key}`"
                    >
                        {{
                            labels.compatible_note.replace(
                                ':version',
                                entry.release?.version ?? '',
                            )
                        }}
                    </p>

                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border-t border-border px-4 py-3 text-sm"
                        :data-test="`app-release-${entry.key}`"
                    >
                        <dt class="text-muted-foreground">
                            {{ labels.version }}
                        </dt>
                        <dd class="font-mono">{{ entry.release?.version }}</dd>
                        <dt class="text-muted-foreground">
                            {{ labels.built }}
                        </dt>
                        <dd>{{ builtAt(entry) }}</dd>
                    </dl>

                    <p
                        v-if="shown(entry).length === 0"
                        class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
                        :data-test="`app-no-platform-${entry.key}`"
                    >
                        {{
                            labels.downloads[entry.key][`no_build_${selected}`]
                        }}
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-border border-t border-border"
                        :data-test="`app-builds-${entry.key}`"
                    >
                        <li
                            v-for="build in shown(entry)"
                            :key="build.name"
                            class="p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div class="min-w-0 text-sm">
                                    <p
                                        class="font-medium"
                                        :data-test="`app-title-${entry.key}-${build.variant}-${build.kind}`"
                                    >
                                        {{ title(entry, build) }}
                                    </p>
                                    <p
                                        v-if="hint(build)"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ hint(build) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ meta(build) }}
                                    </p>
                                </div>
                                <Button
                                    as-child
                                    :variant="
                                        build.name === primaryName
                                            ? 'default'
                                            : 'outline'
                                    "
                                >
                                    <a
                                        :href="build.url"
                                        :download="build.name"
                                        :aria-label="`${labels.download}: ${build.name}`"
                                    >
                                        <Download
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ labels.download }}
                                    </a>
                                </Button>
                            </div>
                            <ol
                                class="mt-3 list-decimal space-y-1 pl-5 text-sm"
                                :data-test="`app-steps-${entry.key}-${build.variant}`"
                            >
                                <li v-for="key in steps(build)" :key="key">
                                    {{ step(key, build) }}
                                </li>
                            </ol>
                            <div class="mt-3 flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs text-muted-foreground">
                                        {{ labels.sha256 }}
                                    </p>
                                    <p
                                        class="font-mono text-xs break-all select-all"
                                    >
                                        {{ build.sha256 }}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :aria-label="labels.copy_sha"
                                    @click="copy(build.sha256)"
                                >
                                    <Check
                                        v-if="copied === build.sha256"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <Copy
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{
                                        copied === build.sha256
                                            ? labels.copied
                                            : labels.copy
                                    }}
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <div
                        v-if="unsigned(entry)"
                        class="flex items-start gap-3 border-t border-warn/60 bg-warn/10 p-4 text-sm"
                        role="note"
                        :data-test="`app-unsigned-${entry.key}`"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-5 shrink-0 text-warn"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="font-medium">
                                {{ labels.unsigned_title }}
                            </p>
                            <p class="mt-1 text-muted-foreground">
                                {{ labels.unsigned_body }}
                            </p>
                        </div>
                    </div>

                    <p
                        v-if="entry.key === 'voice' && hasWindowsApp(entry)"
                        class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
                        data-test="app-webview2"
                    >
                        {{ labels.webview2_note }}
                    </p>
                </template>
            </section>

            <section class="mt-6 space-y-2 text-sm" data-test="app-pair">
                <h2 class="text-sm font-medium text-muted-foreground">
                    {{ labels.pair_heading }}
                </h2>
                <p>{{ labels.pair_hint }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-muted-foreground">
                        {{ labels.pair_server }}
                    </span>
                    <code
                        class="rounded-sm bg-muted px-2 py-1 font-mono text-xs select-all"
                        data-test="app-server-url"
                    >
                        {{ server.url }}
                    </code>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-label="labels.pair_copy"
                        @click="copy(server.url)"
                    >
                        <Check
                            v-if="copied === server.url"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <Copy v-else class="size-4" aria-hidden="true" />
                        {{
                            copied === server.url ? labels.copied : labels.copy
                        }}
                    </Button>
                </div>
                <p v-if="pairUrl" class="text-muted-foreground">
                    {{ labels.pair_link_hint }}
                    <Link
                        :href="pairUrl"
                        class="rounded-sm underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {{ labels.pair_link }}
                    </Link>
                </p>
            </section>
        </template>
    </PageContainer>
</template>
