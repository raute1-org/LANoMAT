<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Component } from 'vue';
import SceneAnnouncement from '@/components/scenes/SceneAnnouncement.vue';
import SceneBracket from '@/components/scenes/SceneBracket.vue';
import SceneFrame from '@/components/scenes/SceneFrame.vue';
import ScenePaymentQr from '@/components/scenes/ScenePaymentQr.vue';
import SceneSchedule from '@/components/scenes/SceneSchedule.vue';
import SceneSeatmap from '@/components/scenes/SceneSeatmap.vue';
import SceneSponsors from '@/components/scenes/SceneSponsors.vue';
import SceneTombola from '@/components/scenes/SceneTombola.vue';
import SceneUpcomingMatches from '@/components/scenes/SceneUpcomingMatches.vue';
import SceneWinner from '@/components/scenes/SceneWinner.vue';
import { useEventChannel } from '@/composables/useEventChannel';
import { useSceneRotation } from '@/composables/useSceneRotation';
import type { ScenePayloadDto, SceneType } from '@/types';

const props = defineProps<{
    event: { id: number; name: string; slug: string };
    scenes: ScenePayloadDto[];
    labels: Record<string, string>;
}>();

// Unknown/not-yet-shipped scene types (Status, ...) intentionally have no
// entry here yet and render nothing via SceneFrame's <component :is>
// fallback — later tasks add their component here as each scene ships.
const sceneComponents: Partial<Record<SceneType, Component>> = {
    announcement: SceneAnnouncement,
    bracket: SceneBracket,
    upcoming_matches: SceneUpcomingMatches,
    schedule: SceneSchedule,
    seatmap: SceneSeatmap,
    payment_qr: ScenePaymentQr,
    sponsors: SceneSponsors,
    tombola: SceneTombola,
    winner: SceneWinner,
};

const idleScene = computed<ScenePayloadDto>(() => ({
    id: 0,
    type: 'announcement',
    durationSec: 0,
    config: {},
    data: {},
}));

const { current, override } = useSceneRotation(
    computed(() => props.scenes),
    { idle: idleScene.value },
);

useEventChannel<{ scene: ScenePayloadDto }>(
    props.event.id,
    ['.scene.override'],
    (payload) => {
        override(payload.scene, payload.scene.durationSec * 1000);
    },
);

useEventChannel(props.event.id, ['.scenes.updated'], () => {
    router.reload({ only: ['scenes'] });
});

const activeComponent = computed<Component | undefined>(
    () => sceneComponents[current.value.type],
);
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <SceneFrame>
        <component
            :is="activeComponent"
            v-if="activeComponent"
            :key="current.id"
            :config="current.config"
            :data="current.data"
            :labels="labels"
        />
        <!-- Unreachable today because `idleScene` always uses the registered
             'announcement' type; kept as defense-in-depth for a future
             registry gap (an unregistered scene type reaching `current`). -->
        <div v-else class="mx-auto max-w-5xl px-12 text-center">
            <h1 class="text-6xl font-bold tracking-tight">
                {{ labels.idle }}
            </h1>
            <p class="mt-8 text-3xl text-white/80">
                {{ labels.idle_body }}
            </p>
        </div>
    </SceneFrame>
</template>
