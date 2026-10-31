<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FilePicker from '@/components/FilePicker.vue';
import PageContainer from '@/components/PageContainer.vue';
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
import { usePageLoadState } from '@/composables/usePageLoadState';
import { formatInstant } from '@/lib/datetime';
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

const { isNavigating, hasError } = usePageLoadState();

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
function decimal(value: number): string {
    return value.toLocaleString('de-DE', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
}

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    if (kb < 1024) {
        return `${decimal(kb)} KB`;
    }

    const mb = kb / 1024;

    return `${decimal(mb)} MB`;
}

function formatDateTime(iso: string): string {
    return formatInstant(iso, { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <PageContainer width="default" :title="`${labels.title} — ${event.name}`">
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
                        <FilePicker
                            id="file-upload"
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
    </PageContainer>
</template>
