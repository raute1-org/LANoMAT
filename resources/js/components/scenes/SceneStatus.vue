<script setup lang="ts">
import { computed } from 'vue';

type Signal = {
    component: string;
    level: 'ok' | 'degraded' | 'down';
    message: string | null;
};

// Two shapes reach this component, mirroring SceneTombola: the
// rotation-configured scene's `{ signals }` (ScenePayload::statusData, all
// components' current levels), and the outage-moment SceneOverride's single
// `{ component, level, message }` (SetStatusSignal, dispatched only for a
// non-Ok level). Both are normalized below so the reassurance banner and the
// full tile share one template.
const props = defineProps<{
    data: {
        signals?: Signal[];
        component?: string;
        level?: 'ok' | 'degraded' | 'down';
        message?: string | null;
    };
    labels: Record<string, string>;
}>();

const outage = computed<Signal | null>(() => {
    if (props.data.component && props.data.level) {
        return {
            component: props.data.component,
            level: props.data.level,
            message: props.data.message ?? null,
        };
    }

    return null;
});

const signals = computed<Signal[]>(() => props.data.signals ?? []);

function componentLabel(component: string): string {
    return props.labels[`status_component_${component}`] ?? component;
}

function levelLabel(level: string): string {
    return props.labels[`status_level_${level}`] ?? level;
}

// Maps the domain's `degraded` level onto the design system's `warn` token —
// the only naming mismatch between Signal.level and the ok/warn/down tokens.
function levelVariant(level: string): 'ok' | 'warn' | 'down' {
    if (level === 'down') {
        return 'down';
    }

    if (level === 'degraded') {
        return 'warn';
    }

    return 'ok';
}

const dotColorClass: Record<'ok' | 'warn' | 'down', string> = {
    ok: 'bg-ok',
    warn: 'bg-warn',
    down: 'bg-down',
};

const textColorClass: Record<'ok' | 'warn' | 'down', string> = {
    ok: 'text-ok',
    warn: 'text-warn',
    down: 'text-down',
};
</script>

<template>
    <div
        v-if="outage"
        class="mx-auto flex h-full max-w-5xl flex-col items-center justify-center px-12 text-center"
    >
        <span
            class="inline-block h-6 w-6 rounded-full"
            :class="dotColorClass[levelVariant(outage.level)]"
        />
        <h1 class="mt-6 text-6xl font-bold tracking-tight text-foreground">
            {{ labels.status_reassurance_title }}
        </h1>
        <p
            class="mt-6 font-mono text-3xl uppercase"
            :class="textColorClass[levelVariant(outage.level)]"
        >
            {{ componentLabel(outage.component) }}:
            {{ levelLabel(outage.level) }}
        </p>
        <p class="mt-4 text-2xl text-muted-foreground">
            {{ outage.message || labels.status_reassurance_body }}
        </p>
    </div>

    <div v-else class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ labels.status_title }}
        </h1>

        <ul class="flex-1 space-y-4 overflow-auto">
            <li
                v-for="signal in signals"
                :key="signal.component"
                class="flex items-center justify-between rounded-xl bg-card px-8 py-6 text-3xl text-foreground"
            >
                <span class="font-semibold">{{
                    componentLabel(signal.component)
                }}</span>
                <span class="flex items-center gap-3">
                    <span
                        class="inline-block h-4 w-4 rounded-full"
                        :class="dotColorClass[levelVariant(signal.level)]"
                    />
                    <span
                        class="font-mono uppercase"
                        :class="textColorClass[levelVariant(signal.level)]"
                        >{{ levelLabel(signal.level) }}</span
                    >
                </span>
            </li>
        </ul>
    </div>
</template>
