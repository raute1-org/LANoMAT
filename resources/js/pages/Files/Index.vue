<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { destroy as destroyFile, store as storeFile } from '@/routes/files';
import type { FileQuotaDto, SharedFileDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    files: SharedFileDto[];
    labels: Record<string, string>;
    quota: FileQuotaDto | null;
}>();

/**
 * The quota bar reads as a quiet fuel gauge rather than a marketing metric —
 * amber only once the viewer is close to (or at) the cap, per the design
 * system's "amber is rationed" rule (docs/design.md).
 */
const quotaRatio = computed(() => {
    if (props.quota === null || props.quota.capBytes <= 0) {
        return 0;
    }

    return Math.min(1, props.quota.usedBytes / props.quota.capBytes);
});

const quotaIsNearCap = computed(() => quotaRatio.value >= 0.9);

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a subsequent client-driven navigation/refresh — same
// router-lifecycle mirror as the Stats/Leaderboard and Servers/Index pages.
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
let removeErrorListener: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', onStart);
    removeFinish = router.on('finish', onFinish);
    removeErrorListener = router.on('error', onError);
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
    removeErrorListener?.();
});

const form = useForm<{ file: File | null }>({
    file: null,
});

function onFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
}

function submit() {
    form.post(storeFile.url({ event: props.event.slug }), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(file: SharedFileDto) {
    useForm({}).delete(destroyFile.url({ sharedFile: file.id }), {
        preserveScroll: true,
    });
}

/**
 * File size is machine data — rendered in `font-mono` per the design system
 * (mono is reserved for machine-readable values, not the filename itself).
 */
function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    if (kb < 1024) {
        return `${kb.toFixed(1)} KB`;
    }

    const mb = kb / 1024;

    return `${mb.toFixed(1)} MB`;
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('de-DE');
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <Card class="mt-8">
            <CardHeader>
                <CardTitle>{{ labels.upload_title }}</CardTitle>
                <div v-if="quota" class="space-y-1.5 pt-1">
                    <div
                        class="flex items-baseline justify-between gap-2 text-sm text-muted-foreground"
                    >
                        <span>{{ labels.quota_label }}</span>
                        <span
                            class="font-mono tabular-nums"
                            :class="quotaIsNearCap && 'text-warn'"
                        >
                            {{ formatSize(quota.usedBytes) }} /
                            {{ formatSize(quota.capBytes) }}
                        </span>
                    </div>
                    <div
                        class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="Math.round(quotaRatio * 100)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full transition-[width] motion-reduce:transition-none"
                            :class="quotaIsNearCap ? 'bg-warn' : 'bg-primary'"
                            :style="{ width: `${quotaRatio * 100}%` }"
                        />
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="file-upload">{{
                            labels.upload_label
                        }}</Label>
                        <input
                            id="file-upload"
                            type="file"
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm file:mr-3 file:rounded-sm file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            required
                            @change="onFileChange"
                        />
                        <p
                            v-if="form.errors.file"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.file }}
                        </p>
                    </div>

                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing
                                ? labels.uploading
                                : labels.upload_submit
                        }}
                    </Button>
                </form>
            </CardContent>
        </Card>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-8 space-y-2">
            <Skeleton class="h-16 w-full rounded-lg" />
            <Skeleton class="h-16 w-full rounded-lg" />
            <Skeleton class="h-16 w-full rounded-lg" />
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
            <!-- empty -->
            <div
                v-if="files.length === 0"
                class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.empty }}
                </p>
            </div>

            <!-- normal -->
            <div v-else class="mt-8 space-y-4">
                <Card v-for="file in files" :key="file.id">
                    <CardHeader>
                        <div class="flex items-center justify-between gap-4">
                            <CardTitle class="break-all">{{
                                file.originalName
                            }}</CardTitle>
                            <div class="flex shrink-0 gap-2">
                                <Badge
                                    v-if="file.visibility === 'pending'"
                                    variant="outline"
                                    class="text-warn"
                                >
                                    {{ labels.pending_badge }}
                                </Badge>
                                <Badge v-if="file.mine" variant="outline">
                                    {{ labels.mine_badge }}
                                </Badge>
                            </div>
                        </div>
                        <CardDescription>
                            {{ labels.uploaded_by }}
                            {{ file.uploaderName }} ·
                            <span class="font-mono tabular-nums">{{
                                formatDateTime(file.createdAt)
                            }}</span>
                        </CardDescription>
                    </CardHeader>

                    <CardContent
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <span
                            class="font-mono text-sm text-muted-foreground tabular-nums"
                        >
                            {{ formatSize(file.sizeBytes) }}
                        </span>

                        <div class="flex gap-2">
                            <Button as-child size="sm" variant="secondary">
                                <a :href="`/files/${file.id}/download`">{{
                                    labels.download
                                }}</a>
                            </Button>
                            <Button
                                v-if="file.mine"
                                size="sm"
                                variant="destructive"
                                @click="remove(file)"
                            >
                                {{ labels.delete }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </template>
    </main>
</template>
