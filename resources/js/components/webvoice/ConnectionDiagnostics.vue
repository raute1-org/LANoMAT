<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import type { ConnectionStatsState } from '@/composables/useConnectionStats';
import { judgePath } from '@/lib/webVoiceStats';
import type { ConnectionMetrics, PathVerdict } from '@/lib/webVoiceStats';

/**
 * Collapsed-by-default connection diagnostics (orga and admin). Numbers are mono, everything else
 * is plain German; the parsing lives in lib/webVoiceStats.ts, this component only formats.
 */
const props = defineProps<{
    open: boolean;
    state: ConnectionStatsState;
    send: ConnectionMetrics | null;
    receive: ConnectionMetrics | null;
    labels: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const number = (value: number | null, digits = 0): string =>
    value === null
        ? '–'
        : value.toLocaleString('de-DE', {
              minimumFractionDigits: digits,
              maximumFractionDigits: digits,
          });

type Row = { key: string; label: string; value: string };

function rows(metrics: ConnectionMetrics): Row[] {
    const path = metrics.path;
    const route =
        path.localType === null
            ? '–'
            : `${path.localType} / ${path.protocol ?? '?'}`;

    return [
        { key: 'path', label: props.labels.diag_path, value: route },
        {
            key: 'rtt',
            label: props.labels.diag_rtt,
            value: `${number(path.rttMs, 1)} ms`,
        },
        {
            key: 'jitter',
            label: props.labels.diag_jitter,
            value: `${number(metrics.jitterMs, 1)} ms`,
        },
        ...(metrics.direction === 'receive'
            ? [
                  {
                      key: 'buffer',
                      label: props.labels.diag_buffer,
                      value: `${number(metrics.jitterBufferMs, 1)} ms`,
                  },
                  {
                      key: 'playout',
                      label: props.labels.diag_playout,
                      value:
                          metrics.playoutMs === null
                              ? props.labels.diag_not_reported
                              : `${number(metrics.playoutMs, 1)} ms`,
                  },
                  {
                      key: 'loss',
                      label: props.labels.diag_loss,
                      value: `${number(metrics.lossPercent, 1)} %`,
                  },
                  {
                      key: 'concealed',
                      label: props.labels.diag_concealed,
                      value: `${number(metrics.concealedPercent, 1)} %`,
                  },
              ]
            : []),
        {
            key: 'rate',
            label: props.labels.diag_bitrate,
            value: `${number(metrics.kbps, 1)} kbit/s`,
        },
        {
            key: 'codec',
            label: props.labels.diag_codec,
            value: metrics.codec ?? '–',
        },
        {
            key: 'ptime',
            label: props.labels.diag_ptime,
            value:
                metrics.packetTimeMs === null
                    ? props.labels.diag_not_reported
                    : `${number(metrics.packetTimeMs)} ms`,
        },
    ];
}

const sections = computed(() =>
    (
        [
            ['send', props.labels.diag_send, props.send],
            ['receive', props.labels.diag_receive, props.receive],
        ] as const
    )
        .filter(([, , metrics]) => metrics !== null)
        .map(([direction, title, metrics]) => ({
            direction,
            title,
            verdict: judgePath((metrics as ConnectionMetrics).path),
            rows: rows(metrics as ConnectionMetrics),
        })),
);

const verdictClass: Record<PathVerdict, string> = {
    ok: 'text-muted-foreground',
    unknown: 'text-muted-foreground',
    slow: 'text-warn',
    reflexive: 'text-warn',
    tcp: 'text-destructive',
    relay: 'text-destructive',
};
</script>

<template>
    <section
        class="rounded-md border border-border bg-card"
        :aria-label="labels.diag_title"
    >
        <button
            type="button"
            class="flex w-full items-center justify-between gap-3 rounded-md p-4 text-left text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none pointer-coarse:min-h-12"
            :aria-expanded="open"
            aria-controls="web-voice-diagnostics"
            @click="emit('update:open', !open)"
        >
            <span>{{ labels.diag_title }}</span>
            <span class="flex items-center gap-3">
                <LiveIndicator
                    v-if="open && state === 'live'"
                    :label="labels.live_hint"
                />
                <ChevronDown
                    class="size-4 transition-transform motion-reduce:transition-none"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </span>
        </button>

        <div
            v-if="open"
            id="web-voice-diagnostics"
            class="space-y-4 border-t border-border p-4"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.diag_lead }}
            </p>

            <!-- empty / loading / error -->
            <p
                v-if="state === 'idle'"
                class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
            >
                {{ labels.diag_empty }}
            </p>
            <p
                v-else-if="state === 'waiting'"
                class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
            >
                {{ labels.diag_loading }}
            </p>
            <p
                v-else-if="state === 'unavailable'"
                role="alert"
                class="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
            >
                {{ labels.diag_unavailable }}
            </p>

            <!-- normal -->
            <div v-else class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="section in sections"
                    :key="section.direction"
                    class="min-w-0 rounded-md border border-border p-4"
                >
                    <h3 class="text-sm font-medium">{{ section.title }}</h3>

                    <p
                        v-if="section.verdict !== 'ok'"
                        class="mt-2 text-sm"
                        :class="verdictClass[section.verdict]"
                        :role="
                            ['tcp', 'relay'].includes(section.verdict)
                                ? 'alert'
                                : undefined
                        "
                    >
                        {{ labels[`diag_hint_${section.verdict}`] }}
                    </p>

                    <dl
                        class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm"
                    >
                        <template v-for="row in section.rows" :key="row.key">
                            <dt class="text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd class="text-right font-mono tabular-nums">
                                {{ row.value }}
                            </dd>
                        </template>
                    </dl>
                </div>
            </div>
        </div>
    </section>
</template>
