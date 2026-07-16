<script setup lang="ts">
defineProps<{
    config: { headline?: string; body?: string };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="mx-auto max-w-5xl px-12 text-center">
        <h1 class="text-6xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.idle }}
        </h1>
        <p v-if="config.body" class="mt-8 text-3xl text-muted-foreground">
            {{ config.body }}
        </p>
    </div>
</template>
