<script setup lang="ts">
import ConfettiOverlay from '@/components/scenes/ConfettiOverlay.vue';

const props = defineProps<{
    data: { winner?: string; tournament?: string; title?: string };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="relative flex h-full w-full items-center justify-center">
        <ConfettiOverlay />

        <div class="relative z-10 mx-auto max-w-5xl px-12 text-center">
            <h1
                class="text-8xl font-extrabold tracking-tight text-live uppercase"
            >
                {{ props.data.title ?? labels.winner_title }}
            </h1>
            <p class="mt-8 text-6xl font-bold text-foreground">
                {{ data.winner }}
            </p>
            <p
                v-if="data.tournament"
                class="mt-6 text-3xl text-muted-foreground"
            >
                {{
                    labels.winner_subtitle.replace(
                        ':tournament',
                        data.tournament,
                    )
                }}
            </p>
        </div>
    </div>
</template>
