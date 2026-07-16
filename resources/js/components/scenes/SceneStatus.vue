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

function levelDotClass(level: string): string {
    if (level === 'down') {
        return 'bg-red-500';
    }

    if (level === 'degraded') {
        return 'bg-yellow-400';
    }

    return 'bg-emerald-500';
}
</script>

<template>
    <div
        v-if="outage"
        class="mx-auto flex h-full max-w-5xl flex-col items-center justify-center px-12 text-center"
    >
        <span
            class="inline-block h-6 w-6 rounded-full"
            :class="levelDotClass(outage.level)"
        />
        <h1 class="mt-6 text-6xl font-bold tracking-tight">
            {{ labels.status_reassurance_title }}
        </h1>
        <p class="mt-6 text-3xl text-white/80">
            {{ componentLabel(outage.component) }}:
            {{ levelLabel(outage.level) }}
        </p>
        <p class="mt-4 text-2xl text-white/70">
            {{ outage.message || labels.status_reassurance_body }}
        </p>
    </div>

    <div v-else class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight">
            {{ labels.status_title }}
        </h1>

        <ul class="flex-1 space-y-4 overflow-auto">
            <li
                v-for="signal in signals"
                :key="signal.component"
                class="flex items-center justify-between rounded-xl bg-white/10 px-8 py-6 text-3xl"
            >
                <span class="font-semibold">{{
                    componentLabel(signal.component)
                }}</span>
                <span class="flex items-center gap-3">
                    <span
                        class="inline-block h-4 w-4 rounded-full"
                        :class="levelDotClass(signal.level)"
                    />
                    {{ levelLabel(signal.level) }}
                </span>
            </li>
        </ul>
    </div>
</template>
