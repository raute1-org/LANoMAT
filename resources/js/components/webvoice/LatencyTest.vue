<script setup lang="ts">
import { Gauge } from '@lucide/vue';
import { computed } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import type {
    LatencyTestFailure,
    LatencyTestPhase,
    LatencyTestResult,
} from '@/composables/useLatencyTest';

/** The latency self-test card (orga and admin). All logic is in useLatencyTest; this formats. */
const props = defineProps<{
    phase: LatencyTestPhase;
    failure: LatencyTestFailure | null;
    progress: { done: number; total: number };
    result: LatencyTestResult | null;
    /** `''` = browser default, otherwise milliseconds as a string (the value of the select). */
    jitterTarget: string;
    targets: string[];
    voiceReady: boolean;
    labels: Record<string, string>;
}>();

const emit = defineEmits<{
    run: [];
    cancel: [];
    'update:jitterTarget': [value: string];
}>();

const busy = computed(
    () => props.phase === 'preparing' || props.phase === 'running',
);

const ms = (value: number): string =>
    value.toLocaleString('de-DE', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });

const failureText: Record<LatencyTestFailure, string> = {
    unavailable: 'voice_unavailable',
    failed: 'test_failed',
    no_audio: 'test_no_audio',
};

const stats = computed(() => {
    const summary = props.result?.summary;

    if (!summary) {
        return [];
    }

    return [
        { key: 'min', label: props.labels.test_min, value: summary.minMs },
        {
            key: 'median',
            label: props.labels.test_median,
            value: summary.medianMs,
        },
        { key: 'p95', label: props.labels.test_p95, value: summary.p95Ms },
        { key: 'max', label: props.labels.test_max, value: summary.maxMs },
        {
            key: 'jitter',
            label: props.labels.test_jitter,
            value: summary.jitterMs,
        },
    ];
});
</script>

<template>
    <section
        class="rounded-md border border-border bg-card p-4"
        :aria-label="labels.test_title"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-sm font-medium">{{ labels.test_title }}</h2>
                <p class="mt-1 max-w-prose text-sm text-muted-foreground">
                    {{ labels.test_lead }}
                </p>
            </div>
            <LiveIndicator
                v-if="busy"
                :label="
                    phase === 'running'
                        ? `${progress.done} / ${progress.total}`
                        : labels.joining
                "
            />
        </div>

        <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-sm">
                <span class="text-muted-foreground">{{
                    labels.test_jitter_target
                }}</span>
                <select
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none pointer-coarse:h-11"
                    :value="jitterTarget"
                    :disabled="busy"
                    @change="
                        emit(
                            'update:jitterTarget',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">{{ labels.test_jitter_default }}</option>
                    <option v-for="t in targets" :key="t" :value="t">
                        {{ t }} ms
                    </option>
                </select>
            </label>

            <Button v-if="!busy" :disabled="!voiceReady" @click="emit('run')">
                <Gauge class="size-4" aria-hidden="true" />
                {{ result ? labels.test_again : labels.test_start }}
            </Button>
            <Button v-else variant="outline" @click="emit('cancel')">
                {{ labels.test_cancel }}
            </Button>
        </div>

        <p
            v-if="!voiceReady"
            class="mt-4 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
        >
            {{ labels.voice_not_ready }}
        </p>

        <div
            v-if="failure && phase === 'error'"
            role="alert"
            class="mt-4 rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
        >
            {{ labels[failureText[failure]] }}
        </div>

        <div v-if="result" class="mt-4 space-y-3">
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div v-for="item in stats" :key="item.key">
                    <dt class="text-xs text-muted-foreground">
                        {{ item.label }}
                    </dt>
                    <dd class="font-mono text-lg tabular-nums">
                        {{ ms(item.value) }}
                        <span class="text-xs text-muted-foreground">ms</span>
                    </dd>
                </div>
            </dl>

            <p class="text-sm text-muted-foreground">
                {{
                    labels.test_summary
                        .replace(':count', String(result.summary.count))
                        .replace(':lost', String(result.summary.lost))
                }}
                <span v-if="result.receive?.jitterBufferMs != null">
                    {{
                        labels.test_buffer.replace(
                            ':ms',
                            ms(result.receive.jitterBufferMs),
                        )
                    }}
                </span>
                <span v-if="result.receive?.concealedPercent != null">
                    {{
                        labels.test_concealed.replace(
                            ':percent',
                            ms(result.receive.concealedPercent),
                        )
                    }}
                </span>
                <span
                    v-if="
                        result.settings.jitterBufferTargetMs !== null &&
                        !result.settings.jitterBufferTargetApplied
                    "
                    class="text-warn"
                >
                    {{ labels.test_target_unsupported }}
                </span>
            </p>
        </div>

        <p class="mt-4 text-sm text-muted-foreground">
            {{ labels.test_excludes }}
        </p>
    </section>
</template>
