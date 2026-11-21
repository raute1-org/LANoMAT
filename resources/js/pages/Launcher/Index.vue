<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, ChevronDown, Copy, Download, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { formatInstant } from '@/lib/datetime';

type Os = 'linux' | 'windows' | 'macos';
type Variant = 'installer' | 'portable' | 'archive';

type Build = {
    name: string;
    os: Os;
    arch: string;
    kind: 'app' | 'cli';
    variant: Variant;
    size: number;
    sha256: string;
    signed: boolean | null;
    url: string;
};

const props = defineProps<{
    state: 'none' | 'published';
    release: {
        version: string;
        commit: string;
        builtAt: string;
    } | null;
    /** One build per OS: the best the manifest offers for it. */
    builds: Build[];
    /** Every file the manifest offers (portable exe, CLI, other arch, …). */
    files: Build[];
    server: { name: string; url: string; version: string };
    detectedOs: Os | null;
}>();

const { isNavigating, hasError } = usePageLoadState();

const osNames: Record<Os, string> = {
    linux: 'Linux',
    windows: 'Windows',
    macos: 'macOS',
};

// The visitor's own system gets the one huge primary button of the page; everything
// else stays a quiet outline action. Without a detected system the first build leads.
const primaryOs = computed<Os | null>(
    () => props.detectedOs ?? props.builds[0]?.os ?? props.files[0]?.os ?? null,
);

const primaryBuild = computed<Build | null>(
    () =>
        props.builds.find((build) => build.os === primaryOs.value) ??
        props.builds[0] ??
        props.files[0] ??
        null,
);

// At most one other build per OS, so at most two quiet buttons under the hero.
const secondaryBuilds = computed<Build[]>(() =>
    props.builds.filter((build) => build.name !== primaryBuild.value?.name),
);

// The marquee says what runs here, not the release details — those stay folded away.
const supportedSystems = computed(() =>
    [...new Set(props.builds.map((build) => osNames[build.os]))].join(' · '),
);

// Files beyond the one per OS (e.g. the portable exe next to the installer).
const extras = computed<Build[]>(() => {
    const primary = new Set(props.builds.map((build) => build.name));

    return props.files.filter((build) => !primary.has(build.name));
});

// The visitor's own system has no build in this release: say it instead of leaving them
// with a file for another system and no explanation.
const missingForVisitor = computed<Os | null>(() => {
    const os = props.detectedOs;

    if (os === null || props.builds.some((build) => build.os === os)) {
        return null;
    }

    return os;
});

const builtAt = computed(() =>
    props.release === null
        ? ''
        : formatInstant(props.release.builtAt, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }),
);

const signedWarning = computed(
    () =>
        props.builds.some((build) => build.os === 'windows') &&
        props.builds.some(
            (build) => build.os === 'windows' && build.signed === false,
        ),
);

// The whole game in three tiles: start the launcher, confirm the code here, play.
const gameSteps = [
    {
        title: 'Launcher starten',
        text: 'Datei herunterladen und öffnen. Keine Einrichtung nötig.',
    },
    {
        title: 'Code im Browser bestätigen',
        text: 'Der Launcher zeigt einen Code — hier auf der Seite bestätigen.',
    },
    {
        title: 'UT2004 installieren + Starten',
        text: 'Ein Klick im Launcher, dann direkt auf den Server.',
    },
] as const;

function platform(build: Build): string {
    return `${osNames[build.os]}, ${build.arch}`;
}

function variantLabel(build: Build): string {
    if (build.kind === 'cli') {
        return 'Kommandozeile';
    }

    return (
        {
            installer: 'Installer',
            portable: 'Portable',
            archive: 'Archiv',
        } as const
    )[build.variant];
}

// One download, its own installation path — no shared steps across platforms.
function steps(build: Build): string[] {
    if (build.kind === 'cli') {
        return [
            'Archiv entpacken und die Datei in einen Ordner deiner Wahl legen.',
            'Im Terminal starten und der Anleitung folgen.',
        ];
    }

    if (build.os === 'linux') {
        return [
            `Archiv entpacken: tar -xzf ${build.name} — darin liegen die Binärdatei, das Icon und eine .desktop-Datei.`,
            'Im entpackten Ordner starten: ./lanomat-launcher',
            'Optional fürs Startmenü: lanomat-launcher nach ~/.local/bin (muss im PATH liegen), lanomat-launcher.desktop nach ~/.local/share/applications und lanomat-launcher.png nach ~/.local/share/icons/hicolor/512x512/apps kopieren.',
        ];
    }

    if (build.os === 'macos') {
        return [
            'Archiv entpacken und die App in den Programme-Ordner ziehen.',
            'Beim ersten Start mit Rechtsklick „Öffnen“ bestätigen.',
        ];
    }

    if (build.variant === 'installer') {
        return [
            'Setup starten und den Anweisungen folgen.',
            'Meldet sich Windows SmartScreen: „Weitere Informationen“ → „Trotzdem ausführen“.',
            'Den Launcher über das Startmenü öffnen.',
        ];
    }

    return [
        'Die exe in einen Ordner deiner Wahl legen (keine Installation nötig).',
        'Doppelklick zum Starten.',
        'Meldet sich Windows SmartScreen: „Weitere Informationen“ → „Trotzdem ausführen“.',
    ];
}

function extraHint(build: Build): string {
    if (build.kind === 'cli') {
        return 'Kommandozeilen-Variante: Archiv entpacken und im Terminal starten.';
    }

    if (build.os === 'windows' && build.variant === 'portable') {
        return 'Dieselbe App ohne Installation: Datei ablegen, Doppelklick zum Starten.';
    }

    if (build.os === 'linux') {
        return 'Dieselbe App als Archiv: entpacken, dann ./lanomat-launcher starten.';
    }

    if (build.os === 'macos') {
        return 'Archiv entpacken und die App in den Programme-Ordner ziehen.';
    }

    return 'Dieselbe App, nur anders verpackt.';
}

// Which copy button was last used — per target, so one click never relabels the others.
const copied = ref<{ key: string; name: string } | null>(null);

function isCopied(key: string): boolean {
    return copied.value?.key === key;
}

function shaKey(build: Build): string {
    return `sha256:${build.name}`;
}

async function copy(value: string, key: string, name: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = { key, name };
        setTimeout(() => {
            if (copied.value?.key === key) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        // No clipboard permission: the value stays selectable on the page.
    }
}

function megabytes(bytes: number): string {
    return `${(bytes / 1024 / 1024).toLocaleString('de-DE', {
        maximumFractionDigits: 1,
    })} MB`;
}

// Announced through a polite live region: screen readers hear the confirmation once,
// instead of the button's own label changing silently.
const copyAnnouncement = computed(() =>
    copied.value === null ? '' : `${copied.value.name} kopiert`,
);
</script>

<template>
    <Head title="LANoMAT Launcher" />

    <PageContainer width="wide">
        <!-- loading -->
        <div
            v-if="isNavigating"
            class="space-y-3"
            role="status"
            aria-label="Launcher-Builds werden geladen"
        >
            <Skeleton class="h-72 w-full rounded-3xl" />
            <div class="grid gap-3 sm:grid-cols-3">
                <Skeleton class="h-40 w-full rounded-3xl" />
                <Skeleton class="h-40 w-full rounded-3xl" />
                <Skeleton class="h-40 w-full rounded-3xl" />
            </div>
        </div>

        <!-- error -->
        <p
            v-else-if="hasError"
            class="rounded-3xl border border-destructive/50 bg-destructive/10 p-10 text-center text-sm text-destructive"
            role="alert"
        >
            Die Launcher-Builds konnten nicht geladen werden. Lade die Seite
            neu; bleibt es dabei, frag im Orga-Team nach.
        </p>

        <!-- empty -->
        <div
            v-else-if="state === 'none'"
            class="overflow-hidden rounded-3xl border border-border bg-card"
            data-test="launcher-none"
        >
            <div
                class="flex items-center justify-between gap-4 border-b border-border px-5 py-3 sm:px-8"
            >
                <p
                    class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
                >
                    1-Klick-Setup
                </p>
            </div>
            <div class="p-5 sm:p-8 lg:p-12">
                <h1
                    class="text-5xl leading-[0.95] font-bold tracking-tight text-foreground sm:text-6xl"
                >
                    LANoMAT Launcher
                </h1>
                <p class="mt-4 max-w-xl text-lg text-muted-foreground">
                    Noch kein Build veröffentlicht.
                </p>
                <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                    Sobald ein Build bereitsteht, findest du hier den Download
                    für Windows und Linux. Bis dahin installierst du Unreal
                    Tournament 2004 wie gewohnt von Hand über die Spiele-Seite.
                </p>
                <Button as-child size="lg" class="mt-8">
                    <Link href="/games/unreal-tournament-2004">
                        Zum Spiel Unreal Tournament 2004
                    </Link>
                </Button>
            </div>
        </div>

        <template v-else>
            <!-- The cabinet: one hero, one giant action, two quiet alternatives. -->
            <section
                class="overflow-hidden rounded-3xl border border-border bg-foreground text-background"
                aria-labelledby="launcher-title"
                data-test="launcher-hero"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-background/15 px-5 py-3 sm:px-8"
                >
                    <p
                        class="font-mono text-xs tracking-widest text-background/60 uppercase"
                    >
                        1-Klick-Setup
                    </p>
                    <p
                        v-if="supportedSystems"
                        class="font-mono text-xs text-background/60"
                    >
                        {{ supportedSystems }}
                    </p>
                </div>

                <div class="p-5 sm:p-8 lg:p-12">
                    <p
                        v-if="detectedOs"
                        class="inline-flex items-center gap-2 rounded-full bg-background/10 px-3 py-1"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                        <span
                            class="font-mono text-xs tracking-widest text-background/80 uppercase"
                        >
                            Erkannt: {{ osNames[detectedOs] }}
                        </span>
                    </p>

                    <h1
                        id="launcher-title"
                        class="mt-5 text-5xl leading-[0.95] font-bold tracking-tight text-background sm:text-6xl lg:text-7xl"
                    >
                        LANoMAT Launcher
                    </h1>

                    <p
                        class="mt-4 max-w-xl text-lg text-background/80 sm:text-xl"
                    >
                        Ein Klick: Spiel installieren, starten, mitspielen.
                    </p>

                    <div
                        class="mt-8 flex flex-col items-stretch gap-3 sm:items-start"
                    >
                        <Button
                            v-if="primaryBuild"
                            as-child
                            size="lg"
                            class="h-auto min-h-16 w-full justify-start rounded-2xl px-6 py-4 text-left text-base sm:min-h-20 sm:w-auto sm:px-8 sm:text-lg"
                            data-test="launcher-primary"
                        >
                            <a
                                :href="primaryBuild.url"
                                :download="primaryBuild.name"
                            >
                                <Download
                                    class="size-5 shrink-0 sm:size-6"
                                    aria-hidden="true"
                                />
                                <span class="leading-tight">
                                    <span class="block font-semibold">
                                        Für
                                        {{ osNames[primaryBuild.os] }}
                                        herunterladen
                                    </span>
                                    <span
                                        class="mt-1 block font-mono text-xs font-normal"
                                    >
                                        {{ variantLabel(primaryBuild) }} ·
                                        {{ megabytes(primaryBuild.size) }}
                                    </span>
                                </span>
                            </a>
                        </Button>

                        <div
                            v-if="secondaryBuilds.length > 0"
                            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                        >
                            <Button
                                v-for="build in secondaryBuilds"
                                :key="build.name"
                                as-child
                                variant="ghost"
                                size="lg"
                                class="h-auto min-h-12 justify-start rounded-xl border border-background/30 px-4 py-2.5 text-left text-sm text-background hover:bg-background/10 hover:text-background"
                                :aria-label="`${osNames[build.os]} herunterladen (${megabytes(build.size)})`"
                                data-test="launcher-secondary"
                            >
                                <a :href="build.url" :download="build.name">
                                    <Download
                                        class="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="leading-tight">
                                        <span class="block font-medium">
                                            {{ osNames[build.os] }}
                                        </span>
                                        <span
                                            class="mt-0.5 block font-mono text-xs font-normal text-background/70"
                                        >
                                            {{ megabytes(build.size) }}
                                        </span>
                                    </span>
                                </a>
                            </Button>
                        </div>
                    </div>

                    <p
                        v-if="missingForVisitor"
                        class="mt-5 max-w-xl rounded-2xl border border-background/20 bg-background/5 p-4 text-sm text-background/80"
                        data-test="launcher-no-os"
                    >
                        <span class="font-medium text-background">
                            Für {{ osNames[missingForVisitor] }} gibt es noch
                            keinen Build.
                        </span>
                        Nimm die Datei für ein anderes System oder frag im
                        Orga-Team nach.
                    </p>

                    <p
                        v-if="signedWarning"
                        class="mt-4 flex max-w-xl items-start gap-2 text-xs text-background/70"
                        role="note"
                        data-test="launcher-unsigned"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Windows zeigt beim ersten Start eine
                            SmartScreen-Warnung — der Build ist nicht signiert.
                        </span>
                    </p>
                </div>
            </section>

            <!-- Three big tiles: the whole flow, nothing to read twice. -->
            <section class="mt-4 sm:mt-6" aria-labelledby="launcher-steps">
                <h2 id="launcher-steps" class="sr-only">
                    In drei Schritten spielbereit
                </h2>
                <ol class="grid gap-3 sm:grid-cols-3">
                    <li
                        v-for="(step, index) in gameSteps"
                        :key="step.title"
                        class="rounded-3xl border border-border bg-card p-6 sm:p-7"
                    >
                        <span
                            class="font-mono text-4xl font-semibold text-muted-foreground/70 tabular-nums"
                            aria-hidden="true"
                        >
                            0{{ index + 1 }}
                        </span>
                        <h3
                            class="mt-5 text-lg font-semibold tracking-tight text-foreground"
                        >
                            {{ step.title }}
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ step.text }}
                        </p>
                    </li>
                </ol>
            </section>

            <!-- Everything technical, folded away. -->
            <details
                class="group mt-4 rounded-3xl border border-border bg-card sm:mt-6"
                data-test="launcher-details"
            >
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-3xl p-5 outline-none focus-visible:ring-2 focus-visible:ring-ring sm:p-6 [&::-webkit-details-marker]:hidden"
                >
                    <span class="min-w-0">
                        <span
                            class="block text-lg font-semibold tracking-tight text-foreground"
                        >
                            Mehr Infos
                        </span>
                        <span
                            class="mt-0.5 block text-sm text-muted-foreground"
                        >
                            Version, Serveradresse, Prüfsummen und
                            Installationswege.
                        </span>
                    </span>
                    <ChevronDown
                        class="size-5 shrink-0 text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                </summary>

                <div class="space-y-8 border-t border-border p-5 sm:p-6">
                    <dl
                        v-if="release"
                        class="grid gap-4 sm:grid-cols-3"
                        data-test="launcher-release"
                    >
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Version
                            </dt>
                            <dd class="mt-1 font-mono text-sm text-foreground">
                                {{ release.version }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">Stand</dt>
                            <dd class="mt-1 font-mono text-sm text-foreground">
                                {{ builtAt }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Commit
                            </dt>
                            <dd
                                class="mt-1 font-mono text-sm break-all text-foreground"
                            >
                                {{ release.commit }}
                            </dd>
                        </div>
                    </dl>

                    <div>
                        <h3 class="text-sm font-semibold text-foreground">
                            Serveradresse
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Diese Adresse trägst du beim Koppeln im Launcher
                            ein.
                        </p>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <p
                                class="rounded-xl border border-border bg-muted px-3 py-2 font-mono text-sm break-all text-foreground select-all"
                                data-test="launcher-server-url"
                            >
                                {{ server.url }}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :aria-label="
                                    isCopied('server-url')
                                        ? 'Serveradresse kopiert'
                                        : 'Serveradresse kopieren'
                                "
                                @click="
                                    copy(
                                        server.url,
                                        'server-url',
                                        'Serveradresse',
                                    )
                                "
                            >
                                <Check
                                    v-if="isCopied('server-url')"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <Copy
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{
                                    isCopied('server-url')
                                        ? 'Kopiert'
                                        : 'Kopieren'
                                }}
                            </Button>
                        </div>
                        <p class="mt-1 font-mono text-xs text-muted-foreground">
                            {{ server.name }} {{ server.version }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-foreground">
                            Dateien und Prüfsummen
                        </h3>
                        <ul class="mt-3 space-y-3">
                            <li
                                v-for="build in files"
                                :key="build.name"
                                class="rounded-2xl border border-border bg-muted/50 p-4"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <p
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{ osNames[build.os] }} ·
                                        {{ variantLabel(build) }}
                                    </p>
                                    <p
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ megabytes(build.size) }}
                                    </p>
                                </div>
                                <p
                                    class="mt-1 font-mono text-xs break-all text-muted-foreground"
                                >
                                    {{ build.name }} · {{ platform(build) }}
                                </p>
                                <div class="mt-2 flex items-start gap-2">
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            SHA-256
                                        </p>
                                        <p
                                            class="font-mono text-xs break-all text-foreground select-all"
                                        >
                                            {{ build.sha256 }}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :aria-label="
                                            isCopied(shaKey(build))
                                                ? `SHA-256 von ${build.name} kopiert`
                                                : `SHA-256 von ${build.name} kopieren`
                                        "
                                        @click="
                                            copy(
                                                build.sha256,
                                                shaKey(build),
                                                'SHA-256',
                                            )
                                        "
                                    >
                                        <Check
                                            v-if="isCopied(shaKey(build))"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        <Copy
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{
                                            isCopied(shaKey(build))
                                                ? 'Kopiert'
                                                : 'Kopieren'
                                        }}
                                    </Button>
                                </div>
                                <ol
                                    class="mt-3 list-decimal space-y-1 pl-5 text-sm text-muted-foreground"
                                >
                                    <li
                                        v-for="step in steps(build)"
                                        :key="step"
                                    >
                                        {{ step }}
                                    </li>
                                </ol>
                            </li>
                        </ul>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        Im Pilotbetrieb installiert der Launcher Unreal
                        Tournament 2004.
                        <Link
                            href="/games/unreal-tournament-2004"
                            class="rounded-sm underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            Zum Spiel Unreal Tournament 2004
                        </Link>
                    </p>
                </div>
            </details>

            <!-- Everything else: below the fold, one compact line per file. -->
            <section
                v-if="extras.length > 0"
                class="mt-6 sm:mt-8"
                aria-labelledby="launcher-files"
                data-test="launcher-files"
            >
                <h2
                    id="launcher-files"
                    class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Weitere Dateien
                </h2>
                <ul
                    class="mt-3 divide-y divide-border rounded-3xl border border-border bg-card"
                >
                    <li
                        v-for="build in extras"
                        :key="build.name"
                        class="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-5"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground">
                                {{ osNames[build.os] }} ·
                                {{ variantLabel(build) }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-xs break-all text-muted-foreground"
                            >
                                {{ build.name }} ·
                                {{ megabytes(build.size) }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ extraHint(build) }}
                            </p>
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <a :href="build.url" :download="build.name">
                                <Download class="size-4" aria-hidden="true" />
                                Herunterladen
                            </a>
                        </Button>
                    </li>
                </ul>
            </section>
        </template>

        <!-- One polite confirmation for whichever copy button was pressed. -->
        <span class="sr-only" aria-live="polite">{{ copyAnnouncement }}</span>
    </PageContainer>
</template>
