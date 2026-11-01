<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, Copy, Download, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';

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

const props = defineProps<{
    state: 'none' | 'mismatch' | 'current';
    server: { version: string; commit: string | null };
    release: {
        version: string;
        commit: string;
        apiVersion: number;
        builtAt: string;
    } | null;
    builds: Build[];
    detectedOs: Os | null;
    labels: Record<string, string>;
}>();

const { isNavigating, hasError } = usePageLoadState();

const platforms: Os[] = ['linux', 'windows', 'macos'];
const selected = ref<Os>(props.detectedOs ?? 'linux');

const buildsFor = (os: Os): Build[] =>
    props.builds.filter((build) => build.os === os);

// The Windows app comes first as installer, then as portable exe, then (older releases) as an archive.
const order = (build: Build): number =>
    build.kind === 'cli'
        ? 3
        : ({ installer: 0, portable: 1, archive: 2 } as const)[build.variant];

const shown = computed(() =>
    [...buildsFor(selected.value)].sort((a, b) => order(a) - order(b)),
);

const unsignedWindows = computed(
    () =>
        selected.value === 'windows' &&
        shown.value.some((build) => build.signed === false),
);

const copied = ref<string | null>(null);

async function copy(sha: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(sha);
        copied.value = sha;
        setTimeout(() => {
            if (copied.value === sha) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        // No clipboard permission: the hash stays selectable on the page.
    }
}

function megabytes(bytes: number): string {
    return `${(bytes / 1024 / 1024).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB`;
}

const builtAt = computed(() =>
    props.release === null
        ? ''
        : new Date(props.release.builtAt).toLocaleString('de-DE', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Europe/Berlin',
          }),
);

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

function title(build: Build): string {
    if (build.kind === 'cli') {
        return props.labels.kind_cli;
    }

    if (build.variant === 'installer') {
        return props.labels.variant_installer;
    }

    if (build.variant === 'portable') {
        return props.labels.variant_portable;
    }

    return build.os === 'windows'
        ? `${props.labels.kind_app} (${props.labels.variant_archive})`
        : props.labels.kind_app;
}

const hint = (build: Build): string | null =>
    build.kind === 'app' && build.variant === 'installer'
        ? props.labels.variant_installer_hint
        : build.kind === 'app' && build.variant === 'portable'
          ? props.labels.variant_portable_hint
          : null;

// The install steps of one Windows download, as label keys (Linux keeps its own section below).
function steps(build: Build): string[] {
    if (build.os !== 'windows' || build.kind !== 'app') {
        return [];
    }

    if (build.variant === 'installer') {
        return [
            'step_win_setup_run',
            'step_win_smartscreen',
            'step_win_setup_start',
        ];
    }

    if (build.variant === 'portable') {
        return [
            'step_win_portable_place',
            'step_win_portable_start',
            'step_win_smartscreen',
        ];
    }

    return ['step_win_extract', 'step_win_start', 'step_win_smartscreen'];
}

const hasWindowsApp = computed(
    () =>
        selected.value === 'windows' &&
        shown.value.some((build) => build.kind === 'app'),
);
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="narrow"
        :title="labels.title"
        :description="labels.description"
        :back="{ href: '/talk', label: labels.back }"
    >
        <!-- loading -->
        <div
            v-if="isNavigating"
            class="mt-8 space-y-3"
            role="status"
            :aria-label="labels.loading"
        >
            <Skeleton class="h-20 w-full" />
            <Skeleton class="h-32 w-full" />
        </div>

        <!-- error -->
        <p
            v-else-if="hasError"
            class="mt-8 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center text-sm text-destructive"
            role="alert"
        >
            {{ labels.error }}
        </p>

        <template v-else>
            <!-- empty: nothing published -->
            <div
                v-if="state === 'none'"
                class="mt-8 flex items-start gap-3 rounded-md border border-warn/60 bg-warn/10 p-5 text-sm"
                role="alert"
                data-test="client-none"
            >
                <TriangleAlert
                    class="mt-0.5 size-5 shrink-0 text-warn"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">{{ labels.none_title }}</p>
                    <p class="mt-1 text-muted-foreground">
                        {{ labels.none_body }}
                    </p>
                </div>
            </div>

            <template v-else>
                <!-- warning: built for another server version -->
                <div
                    v-if="state === 'mismatch'"
                    class="mt-8 flex items-start gap-3 rounded-md border border-warn/60 bg-warn/10 p-5 text-sm"
                    role="alert"
                    data-test="client-mismatch"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-warn"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-medium">{{ labels.mismatch_title }}</p>
                        <p class="mt-1 text-muted-foreground">
                            {{ labels.mismatch_body }}
                        </p>
                        <p class="mt-2 font-mono text-xs">
                            {{ labels.server_version }}: {{ server.version }} /
                            {{ labels.client_version }}:
                            {{ release?.version }}
                        </p>
                    </div>
                </div>

                <!-- normal -->
                <dl
                    class="mt-8 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm"
                    data-test="client-release"
                >
                    <dt class="text-muted-foreground">
                        {{ labels.version }}
                    </dt>
                    <dd class="font-mono">{{ release?.version }}</dd>
                    <dt class="text-muted-foreground">{{ labels.built }}</dt>
                    <dd>{{ builtAt }}</dd>
                </dl>

                <div
                    class="mt-6 flex flex-wrap gap-2"
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
                </div>

                <section
                    class="mt-4"
                    role="tabpanel"
                    :aria-labelledby="`tab-${selected}`"
                >
                    <p
                        v-if="shown.length === 0"
                        class="rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                        data-test="client-no-platform"
                    >
                        {{ labels[`no_build_${selected}`] }}
                    </p>

                    <ul v-else class="space-y-3" data-test="client-builds">
                        <li
                            v-for="build in shown"
                            :key="build.name"
                            class="rounded-md border border-border bg-card p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div class="min-w-0 text-sm">
                                    <p
                                        class="font-medium"
                                        :data-test="`client-title-${build.variant}-${build.kind}`"
                                    >
                                        {{ title(build) }}
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
                                <Button as-child>
                                    <a :href="build.url" :download="build.name">
                                        <Download
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ labels.download }}
                                    </a>
                                </Button>
                            </div>
                            <ol
                                v-if="steps(build).length > 0"
                                class="mt-3 list-decimal space-y-1 pl-5 text-sm"
                                :data-test="`client-steps-${build.variant}`"
                            >
                                <li v-for="key in steps(build)" :key="key">
                                    {{ labels[key] }}
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
                </section>

                <div
                    v-if="unsignedWindows"
                    class="mt-4 flex items-start gap-3 rounded-md border border-warn/60 bg-warn/10 p-5 text-sm"
                    role="note"
                    data-test="client-unsigned"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-warn"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-medium">{{ labels.unsigned_title }}</p>
                        <p class="mt-1 text-muted-foreground">
                            {{ labels.unsigned_body }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="hasWindowsApp"
                    class="mt-4 text-sm text-muted-foreground"
                    data-test="client-webview2"
                >
                    {{ labels.webview2_note }}
                </p>

                <section
                    v-if="selected === 'linux' && shown.length > 0"
                    class="mt-8"
                    aria-labelledby="install-heading"
                >
                    <h2
                        id="install-heading"
                        class="text-lg font-semibold tracking-tight"
                    >
                        {{ labels.install_heading }}
                    </h2>
                    <ol
                        class="mt-3 list-decimal space-y-2 pl-5 text-sm"
                        data-test="client-steps"
                    >
                        <li>{{ labels.step_extract }}</li>
                        <li>{{ labels.step_chmod }}</li>
                        <li>{{ labels.step_start }}</li>
                    </ol>
                </section>
            </template>
        </template>

        <p class="mt-8 text-sm text-muted-foreground">
            {{ labels.pair_hint }}
            <Link
                href="/talk/connect"
                class="rounded-sm underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
            >
                {{ labels.pair_link }}
            </Link>
        </p>
    </PageContainer>
</template>
