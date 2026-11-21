<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ArchiveFileList from '@/components/games/share/ArchiveFileList.vue';
import ArchiveUploader from '@/components/games/share/ArchiveUploader.vue';
import QuotaGauge from '@/components/games/share/QuotaGauge.vue';
import SharedGames from '@/components/games/share/SharedGames.vue';
import PageContainer from '@/components/PageContainer.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { usePageLoadState } from '@/composables/usePageLoadState';
import type {
    GameShareFile,
    GameShareForm,
    GameSharePageProps,
} from '@/types/gameShare';
import type { FlashToast } from '@/types/ui';

/**
 * "Spiel teilen": upload a ready-to-run archive, describe the game, and see
 * what happens to the shared games afterwards. Three numbered sections on one
 * page — no modal wizard — in the order of the work: archive, description,
 * own shares. The backend validates everything again; this page only keeps
 * the obvious mistakes out (no file, no title).
 */
const props = defineProps<GameSharePageProps>();

// Loading/error cover this page's own reads only. The files/quota partial
// reload after an upload and the form POST never swap the page for skeletons.
const { isNavigating, hasError } = usePageLoadState();

const page = usePage();

/** The server's success flash, shown inline and announced after the redirect. */
const successFlash = computed<string | null>(() => {
    const toast = page.flash?.toast as FlashToast | undefined;

    return toast?.type === 'success' ? toast.message : null;
});

const form = useForm<GameShareForm>({
    title: '',
    shared_file_id: null,
    platforms: ['windows'],
    executable: '',
    linux_runner: null,
    linux_executable: '',
    summary: '',
    version: '',
    homepage: '',
    players: '',
    multiplayer: true,
});

// --- file selection --------------------------------------------------------

const selectedFileId = ref<number | null>(null);
let selectionInitialised = false;

/**
 * Keeps the chosen file valid across prop refreshes. The first run honours
 * `?file=<id>` (or falls back to the newest usable archive); a later run
 * selects a freshly uploaded file and never leaves a vanished file selected.
 */
watch(
    () => props.files,
    (files, previous) => {
        const knownBefore = new Set((previous ?? []).map((file) => file.id));
        const newestUsable = files.find((file) => file.usable) ?? null;
        const stillThere = files.some(
            (file) => file.id === selectedFileId.value,
        );

        if (!selectionInitialised) {
            selectionInitialised = true;
            const fromUrl =
                files.find((file) => file.id === props.selectedFileId) ?? null;
            selectedFileId.value = fromUrl?.id ?? newestUsable?.id ?? null;

            return;
        }

        const fresh =
            files.find((file) => file.usable && !knownBefore.has(file.id)) ??
            null;

        if (fresh !== null) {
            selectedFileId.value = fresh.id;

            return;
        }

        if (!stillThere) {
            selectedFileId.value = newestUsable?.id ?? null;
        }
    },
    { immediate: true },
);

watch(
    selectedFileId,
    (id) => {
        form.shared_file_id = id;
    },
    { immediate: true },
);

const selectedFile = computed<GameShareFile | null>(
    () => props.files.find((file) => file.id === selectedFileId.value) ?? null,
);

const selectedUsable = computed<boolean>(
    () => selectedFile.value?.usable === true,
);

const titleReady = computed<boolean>(() => form.title.trim().length >= 2);

const canSubmit = computed<boolean>(
    () => selectedUsable.value && titleReady.value,
);

const linuxSelected = computed<boolean>(() => form.platforms.includes('linux'));

// Linux needs a runner; Wine is the common case and the honest default here.
watch(linuxSelected, (linux) => {
    if (linux && form.linux_runner === null) {
        form.linux_runner = 'wine';
    }
});

const archiveList = computed<string>(() =>
    props.limits.archiveExtensions.join(' oder '),
);

/** Refreshes the list and the quota after an upload; partial, so no skeleton. */
function refreshFiles(): void {
    router.reload({ only: ['files', 'quota'] });
}

// --- submit ----------------------------------------------------------------

const fieldOrder: Array<{ key: keyof GameShareForm; id: string }> = [
    { key: 'shared_file_id', id: 'share-file-group' },
    { key: 'title', id: 'share-title' },
    { key: 'platforms', id: 'share-platform-windows' },
    { key: 'executable', id: 'share-executable' },
    { key: 'linux_runner', id: 'share-linux-wine' },
    { key: 'linux_executable', id: 'share-linux-executable' },
    { key: 'summary', id: 'share-summary' },
    { key: 'version', id: 'share-version' },
    { key: 'players', id: 'share-players' },
    { key: 'homepage', id: 'share-homepage' },
];

/** Focuses the first field the server rejected, in the page's reading order. */
function focusFirstError(): void {
    const first = fieldOrder.find(({ key }) => Boolean(form.errors[key]));

    if (first === undefined) {
        return;
    }

    const element = document.getElementById(first.id);

    if (element === null) {
        return;
    }

    element.focus({ preventScroll: true });
    element.scrollIntoView({
        block: 'center',
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
    });
}

function submit(): void {
    if (!canSubmit.value || form.processing) {
        return;
    }

    form.shared_file_id = selectedFileId.value;
    form.linux_runner = linuxSelected.value
        ? (form.linux_runner ?? 'wine')
        : null;
    form.linux_executable =
        linuxSelected.value && form.linux_runner === 'native'
            ? form.linux_executable
            : '';

    form.post('/games/share', {
        preserveScroll: true,
        onError: () => {
            void nextTick(focusFirstError);
        },
        onSuccess: () => {
            // The share is in the list now; clear the description, keep the
            // file so a second game can be described right away. The fields
            // are reset one by one: `platforms` is an array that v-model
            // mutates in place, which a plain `reset()` would keep.
            const keep = selectedFileId.value;
            form.reset(
                'title',
                'executable',
                'linux_runner',
                'linux_executable',
                'summary',
                'version',
                'homepage',
                'players',
            );
            form.platforms = ['windows'];
            form.multiplayer = true;
            form.shared_file_id = keep;
        },
    });
}
</script>

<template>
    <Head title="Spiel teilen" />

    <PageContainer
        width="default"
        title="Spiel teilen"
        description="Lade ein Spiel hoch und beschreibe es. Die Orga prüft es und gibt es für alle frei."
        :back="{ href: '/games', label: 'Spiele' }"
    >
        <!-- loading: only a full visit; partial reloads keep the content -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-64 w-full rounded-md" />
            <Skeleton class="h-96 w-full rounded-md" />
            <Skeleton class="h-32 w-full rounded-md" />
        </div>

        <!-- error: a failed client-driven load -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">
                Das Formular konnte nicht geladen werden. Lade die Seite neu und
                versuche es noch einmal.
            </p>
        </div>

        <template v-else>
            <!-- The server's flash after a successful share, announced politely. -->
            <p
                v-if="successFlash"
                role="status"
                class="mt-6 rounded-md border border-ok/40 bg-ok/10 px-4 py-3 text-sm text-foreground"
            >
                {{ successFlash }}
            </p>

            <!-- 01 — the archive -->
            <section class="mt-8" aria-labelledby="share-archive-heading">
                <h2
                    id="share-archive-heading"
                    class="flex items-baseline gap-2 text-base font-semibold tracking-tight text-foreground"
                >
                    <span
                        class="font-mono text-xs font-normal tracking-widest text-muted-foreground"
                        >01</span
                    >
                    Archiv
                </h2>
                <p class="mt-1 max-w-prose text-sm text-muted-foreground">
                    Ein Ordner, der nach dem Entpacken sofort startet: packe ihn
                    als {{ archiveList }}. Keine Installer und keine ISO-Dateien
                    — die Orga prüft die Datei vor der Freigabe.
                </p>

                <Panel :level="3" title="Datei auswählen" class="mt-3">
                    <div
                        v-if="event && upload"
                        class="space-y-4 border-t border-border p-4"
                    >
                        <ArchiveUploader
                            :upload="upload"
                            :upload-labels="uploadLabels"
                            :archive-extensions="limits.archiveExtensions"
                            @finished="refreshFiles"
                        />
                        <QuotaGauge v-if="quota" :quota="quota" />
                    </div>
                    <div
                        v-else
                        class="space-y-3 border-t border-border p-4 text-sm text-muted-foreground"
                    >
                        <p v-if="event">
                            Der Upload ist gerade nicht verfügbar. Ein Archiv,
                            das schon auf dem Dateiserver liegt, kannst du
                            trotzdem auswählen.
                        </p>
                        <p v-else-if="files.length > 0">
                            Der Upload öffnet mit der nächsten LAN. Ein Archiv,
                            das schon auf dem Dateiserver liegt, kannst du
                            trotzdem auswählen — die Liste zeigt es unten.
                        </p>
                        <p v-else>
                            Der Upload öffnet mit der nächsten LAN. Ein Archiv,
                            das schon auf dem Dateiserver liegt, kannst du
                            trotzdem auswählen; in deiner Liste liegt noch
                            keins.
                        </p>
                        <QuotaGauge v-if="quota" :quota="quota" />
                    </div>

                    <ArchiveFileList
                        :files="files"
                        :selected="selectedFileId"
                        :archive-extensions="limits.archiveExtensions"
                        :can-upload="event !== null && upload !== null"
                        :error="form.errors.shared_file_id"
                        @update:selected="selectedFileId = $event"
                    />
                </Panel>
            </section>

            <!-- 02 — the description -->
            <section class="mt-8" aria-labelledby="share-game-heading">
                <h2
                    id="share-game-heading"
                    class="flex items-baseline gap-2 text-base font-semibold tracking-tight text-foreground"
                >
                    <span
                        class="font-mono text-xs font-normal tracking-widest text-muted-foreground"
                        >02</span
                    >
                    Spiel
                </h2>
                <p class="mt-1 max-w-prose text-sm text-muted-foreground">
                    Beschreibe, was die anderen herunterladen und starten. Pfade
                    zählen ab dem Ordner, den du hochgeladen hast.
                </p>

                <Panel :level="3" title="Angaben zum Spiel" class="mt-3">
                    <form
                        class="space-y-5 border-t border-border p-4"
                        novalidate
                        @submit.prevent="submit"
                    >
                        <div class="grid gap-1.5">
                            <Label for="share-title">
                                Titel
                                <span
                                    class="text-destructive"
                                    aria-hidden="true"
                                    >*</span
                                >
                            </Label>
                            <Input
                                id="share-title"
                                v-model="form.title"
                                :maxlength="limits.titleMax"
                                required
                                autocomplete="off"
                                class="h-11 sm:h-9"
                                :aria-invalid="
                                    form.errors.title ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.title
                                        ? 'share-title-hint share-title-error'
                                        : 'share-title-hint'
                                "
                            />
                            <p
                                id="share-title-hint"
                                class="text-xs text-muted-foreground"
                            >
                                Der Name, unter dem das Spiel in der Bibliothek
                                steht.
                            </p>
                            <p
                                v-if="form.errors.title"
                                id="share-title-error"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.title }}
                            </p>
                        </div>

                        <fieldset
                            class="grid gap-2"
                            :aria-describedby="
                                form.errors.platforms
                                    ? 'share-platforms-error'
                                    : undefined
                            "
                        >
                            <legend class="text-sm font-medium">
                                Plattformen
                                <span
                                    class="text-destructive"
                                    aria-hidden="true"
                                    >*</span
                                >
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    for="share-platform-windows"
                                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-border px-3 text-sm transition-colors has-[:checked]:border-primary motion-reduce:transition-none"
                                >
                                    <input
                                        id="share-platform-windows"
                                        v-model="form.platforms"
                                        type="checkbox"
                                        value="windows"
                                        class="size-4 accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                    Windows
                                </label>
                                <label
                                    for="share-platform-linux"
                                    class="flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-border px-3 text-sm transition-colors has-[:checked]:border-primary motion-reduce:transition-none"
                                >
                                    <input
                                        id="share-platform-linux"
                                        v-model="form.platforms"
                                        type="checkbox"
                                        value="linux"
                                        class="size-4 accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                    Linux
                                </label>
                            </div>
                            <p
                                v-if="form.errors.platforms"
                                id="share-platforms-error"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.platforms }}
                            </p>
                        </fieldset>

                        <div class="grid gap-1.5">
                            <Label for="share-executable">
                                Startdatei
                                <span
                                    class="text-destructive"
                                    aria-hidden="true"
                                    >*</span
                                >
                            </Label>
                            <Input
                                id="share-executable"
                                v-model="form.executable"
                                :maxlength="limits.executableMax"
                                required
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="bin/Game.exe"
                                class="h-11 font-mono sm:h-9"
                                :aria-invalid="
                                    form.errors.executable ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.executable
                                        ? 'share-executable-hint share-executable-error'
                                        : 'share-executable-hint'
                                "
                            />
                            <p
                                id="share-executable-hint"
                                class="text-xs text-muted-foreground"
                            >
                                Pfad in dem entpackten Ordner, zum Beispiel
                                <span class="font-mono">bin/Game.exe</span>.
                                Liegt im Archiv ein Unterordner wie
                                <span class="font-mono">MeinSpiel/</span>, zählt
                                der Pfad ab diesem Ordner.
                            </p>
                            <p
                                v-if="form.errors.executable"
                                id="share-executable-error"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.executable }}
                            </p>
                        </div>

                        <fieldset
                            v-if="linuxSelected"
                            class="grid gap-2"
                            :aria-describedby="
                                form.errors.linux_runner
                                    ? 'share-linux-runner-error'
                                    : undefined
                            "
                        >
                            <legend class="text-sm font-medium">
                                Linux-Start
                            </legend>
                            <label
                                for="share-linux-wine"
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-border px-3 text-sm transition-colors has-[:checked]:border-primary motion-reduce:transition-none"
                            >
                                <input
                                    id="share-linux-wine"
                                    v-model="form.linux_runner"
                                    type="radio"
                                    name="linux-runner"
                                    value="wine"
                                    class="size-4 accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                                Läuft über Wine
                            </label>
                            <label
                                for="share-linux-native"
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-border px-3 text-sm transition-colors has-[:checked]:border-primary motion-reduce:transition-none"
                            >
                                <input
                                    id="share-linux-native"
                                    v-model="form.linux_runner"
                                    type="radio"
                                    name="linux-runner"
                                    value="native"
                                    class="size-4 accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                                Eigenes Linux-Programm
                            </label>
                            <p
                                v-if="form.errors.linux_runner"
                                id="share-linux-runner-error"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.linux_runner }}
                            </p>

                            <div
                                v-if="form.linux_runner === 'native'"
                                class="grid gap-1.5 pt-1"
                            >
                                <Label for="share-linux-executable">
                                    Linux-Startdatei
                                </Label>
                                <Input
                                    id="share-linux-executable"
                                    v-model="form.linux_executable"
                                    :maxlength="limits.executableMax"
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="Game.sh"
                                    class="h-11 font-mono sm:h-9"
                                    :aria-invalid="
                                        form.errors.linux_executable
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.linux_executable
                                            ? 'share-linux-executable-hint share-linux-executable-error'
                                            : 'share-linux-executable-hint'
                                    "
                                />
                                <p
                                    id="share-linux-executable-hint"
                                    class="text-xs text-muted-foreground"
                                >
                                    Pfad in dem entpackten Ordner, zum Beispiel
                                    <span class="font-mono">Game.sh</span>.
                                </p>
                                <p
                                    v-if="form.errors.linux_executable"
                                    id="share-linux-executable-error"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.linux_executable }}
                                </p>
                            </div>
                            <p v-else class="text-xs text-muted-foreground">
                                Bei Wine startet die Windows-Startdatei von
                                oben.
                            </p>
                        </fieldset>

                        <div class="grid gap-1.5">
                            <Label for="share-summary">Kurzbeschreibung</Label>
                            <Textarea
                                id="share-summary"
                                v-model="form.summary"
                                :maxlength="limits.summaryMax"
                                rows="3"
                                class="min-h-24"
                                :aria-invalid="
                                    form.errors.summary ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.summary
                                        ? 'share-summary-hint share-summary-error'
                                        : 'share-summary-hint'
                                "
                            />
                            <p
                                id="share-summary-hint"
                                class="text-xs text-muted-foreground"
                            >
                                Ein bis zwei Sätze für die Spieleliste. Leer
                                lassen ist in Ordnung.
                            </p>
                            <p
                                v-if="form.errors.summary"
                                id="share-summary-error"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.summary }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="share-version">Version</Label>
                                <Input
                                    id="share-version"
                                    v-model="form.version"
                                    maxlength="40"
                                    autocomplete="off"
                                    placeholder="1.0"
                                    class="h-11 font-mono sm:h-9"
                                    :aria-invalid="
                                        form.errors.version ? 'true' : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.version
                                            ? 'share-version-error'
                                            : undefined
                                    "
                                />
                                <p
                                    v-if="form.errors.version"
                                    id="share-version-error"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.version }}
                                </p>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="share-players">Spieleranzahl</Label>
                                <Input
                                    id="share-players"
                                    v-model="form.players"
                                    maxlength="20"
                                    autocomplete="off"
                                    placeholder="2-8"
                                    class="h-11 sm:h-9"
                                    :aria-invalid="
                                        form.errors.players ? 'true' : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.players
                                            ? 'share-players-error'
                                            : undefined
                                    "
                                />
                                <p
                                    v-if="form.errors.players"
                                    id="share-players-error"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.players }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <label
                                for="share-multiplayer"
                                class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium"
                            >
                                <input
                                    id="share-multiplayer"
                                    v-model="form.multiplayer"
                                    type="checkbox"
                                    class="size-4 accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                                Mehrspieler
                            </label>
                            <p
                                id="share-multiplayer-hint"
                                class="text-xs text-muted-foreground"
                            >
                                Mehrere Spieler können zusammen spielen.
                            </p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="share-homepage">Homepage</Label>
                            <Input
                                id="share-homepage"
                                v-model="form.homepage"
                                type="url"
                                maxlength="500"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="https://lan.example/spiel"
                                class="h-11 sm:h-9"
                                :aria-invalid="
                                    form.errors.homepage ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.homepage
                                        ? 'share-homepage-hint share-homepage-error'
                                        : 'share-homepage-hint'
                                "
                            />
                            <p
                                id="share-homepage-hint"
                                class="text-xs text-muted-foreground"
                            >
                                Nur wenn es eine offizielle Seite gibt.
                            </p>
                            <p
                                v-if="form.errors.homepage"
                                id="share-homepage-error"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.homepage }}
                            </p>
                        </div>

                        <div
                            class="flex flex-col gap-2 border-t border-border pt-5 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <Button
                                type="submit"
                                class="h-11 w-full sm:h-10 sm:w-auto"
                                :disabled="!canSubmit || form.processing"
                            >
                                {{
                                    form.processing
                                        ? 'Wird geteilt …'
                                        : 'Spiel teilen'
                                }}
                            </Button>
                            <p
                                v-if="!selectedUsable"
                                class="text-xs text-muted-foreground sm:text-right"
                            >
                                Wähle oben ein Archiv als .zip oder .tar.bz2.
                            </p>
                            <p
                                v-else-if="!titleReady"
                                class="text-xs text-muted-foreground sm:text-right"
                            >
                                Gib dem Spiel einen Titel.
                            </p>
                        </div>
                    </form>
                </Panel>
            </section>

            <!-- 03 — what happens afterwards -->
            <section class="mt-8" aria-labelledby="share-shares-heading">
                <h2
                    id="share-shares-heading"
                    class="flex items-baseline gap-2 text-base font-semibold tracking-tight text-foreground"
                >
                    <span
                        class="font-mono text-xs font-normal tracking-widest text-muted-foreground"
                        >03</span
                    >
                    Deine geteilten Spiele
                </h2>
                <p class="mt-1 max-w-prose text-sm text-muted-foreground">
                    Nach dem Absenden prüft die Orga Datei und Angaben. Sobald
                    beides passt, steht dein Spiel unter Spiele und im Launcher;
                    der Katalog-Eintrag entsteht danach automatisch.
                </p>

                <Panel :level="3" title="Deine Spiele" class="mt-3">
                    <SharedGames :shares="shares" />
                </Panel>
            </section>
        </template>
    </PageContainer>
</template>
