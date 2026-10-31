<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { buttonVariants } from '@/components/ui/button';
import { useResumableUploads } from '@/composables/useResumableUploads';
import type { UploadItem } from '@/composables/useResumableUploads';
import { formatBytes, formatDuration, formatSpeed } from '@/lib/bytes';

const props = defineProps<{
    ticketUrl: string;
    maxFileBytes: number;
    labels: Record<string, string>;
    onFinished: () => void;
}>();

const { items, add, pause, resume, cancel, retry, dismiss } =
    useResumableUploads({
        ticketUrl: props.ticketUrl,
        maxFileBytes: props.maxFileBytes,
        onFinished: props.onFinished,
    });

const dragging = ref(false);

const hint = computed(() =>
    props.labels.up_hint.replace(':max', formatBytes(props.maxFileBytes)),
);

function onPick(event: Event): void {
    const target = event.target as HTMLInputElement;
    add(Array.from(target.files ?? []));
    // Selecting the same file again (to resume after a reload) must fire `change` again.
    target.value = '';
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    add(Array.from(event.dataTransfer?.files ?? []));
}

function percent(item: UploadItem): number {
    return item.size > 0 ? Math.min(100, (item.sent / item.size) * 100) : 0;
}

function statusLabel(item: UploadItem): string {
    switch (item.status) {
        case 'queued':
            return props.labels.up_status_queued;
        case 'preparing':
            return props.labels.up_status_preparing;
        case 'reconnecting':
            return props.labels.up_status_reconnecting;
        case 'paused':
            return props.labels.up_status_paused;
        case 'done':
            return props.labels.up_status_done;
        case 'error':
            return (
                item.errorMessage ??
                props.labels[`up_err_${item.errorCode}`] ??
                props.labels.up_err_generic
            );
        default:
            return item.resumed
                ? props.labels.up_status_resumed
                : props.labels.up_status_uploading;
    }
}

const isRunning = (item: UploadItem): boolean =>
    item.status === 'uploading' || item.status === 'reconnecting';
</script>

<template>
    <div class="space-y-4">
        <div
            class="rounded-md border border-dashed p-4 transition-colors motion-reduce:transition-none"
            :class="dragging ? 'border-primary bg-primary/5' : 'border-border'"
            @dragover.prevent="dragging = true"
            @dragleave="dragging = false"
            @drop.prevent="onDrop"
        >
            <div class="flex flex-wrap items-center gap-3">
                <input
                    id="file-upload"
                    type="file"
                    multiple
                    class="peer sr-only"
                    @change="onPick"
                />
                <label
                    for="file-upload"
                    :class="[
                        buttonVariants({ variant: 'default' }),
                        'cursor-pointer peer-focus-visible:border-ring peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50',
                    ]"
                >
                    {{ labels.up_pick }}
                </label>
                <span class="text-sm text-muted-foreground">{{
                    labels.up_drop
                }}</span>
            </div>
            <p class="mt-3 max-w-prose text-sm text-muted-foreground">
                {{ hint }}
            </p>
            <p class="mt-1 max-w-prose text-sm text-muted-foreground">
                {{ labels.up_hint_reload }}
            </p>
        </div>

        <ul v-if="items.length > 0" class="space-y-3" aria-live="polite">
            <li
                v-for="item in items"
                :key="item.id"
                class="rounded-md border border-border p-3"
                :class="item.status === 'error' && 'border-destructive/50'"
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="min-w-0 text-sm font-medium break-all">
                        {{ item.name }}
                    </p>
                    <span
                        class="shrink-0 font-mono text-sm text-muted-foreground tabular-nums"
                        >{{ formatBytes(item.size) }}</span
                    >
                </div>

                <div
                    v-if="item.status !== 'error'"
                    class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-label="item.name"
                    :aria-valuenow="Math.round(percent(item))"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuetext="statusLabel(item)"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                        :style="{ width: `${percent(item)}%` }"
                    />
                </div>

                <p
                    class="mt-2 text-sm"
                    :class="
                        item.status === 'error'
                            ? 'text-destructive'
                            : item.status === 'reconnecting'
                              ? 'text-warn'
                              : 'text-muted-foreground'
                    "
                    :role="item.status === 'error' ? 'alert' : undefined"
                >
                    {{ statusLabel(item) }}
                </p>

                <div
                    class="mt-2 flex flex-wrap items-center justify-between gap-2"
                >
                    <span
                        v-if="isRunning(item) || item.status === 'paused'"
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ formatBytes(item.sent) }} /
                        {{ formatBytes(item.size) }} ·
                        {{ Math.floor(percent(item)) }} %
                        <template v-if="item.speed > 0">
                            · {{ formatSpeed(item.speed) }}
                        </template>
                        <template v-if="item.etaSeconds !== null">
                            ·
                            {{
                                labels.up_eta.replace(
                                    ':time',
                                    formatDuration(item.etaSeconds),
                                )
                            }}
                        </template>
                    </span>
                    <span v-else />

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="isRunning(item)"
                            size="sm"
                            variant="secondary"
                            @click="pause(item.id)"
                        >
                            {{ labels.up_pause }}
                        </Button>
                        <Button
                            v-if="item.status === 'paused'"
                            size="sm"
                            variant="secondary"
                            @click="resume(item.id)"
                        >
                            {{ labels.up_resume }}
                        </Button>
                        <Button
                            v-if="item.status === 'error'"
                            size="sm"
                            variant="secondary"
                            @click="retry(item.id)"
                        >
                            {{ labels.up_retry }}
                        </Button>
                        <Button
                            v-if="
                                item.status === 'done' ||
                                item.status === 'error'
                            "
                            size="sm"
                            variant="ghost"
                            @click="dismiss(item.id)"
                        >
                            {{ labels.up_dismiss }}
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="ghost"
                            @click="cancel(item.id)"
                        >
                            {{ labels.up_cancel }}
                        </Button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
