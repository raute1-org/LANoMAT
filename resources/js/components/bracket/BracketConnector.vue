<script setup lang="ts">
defineProps<{
    width: number;
    height: number;
    paths: string[];
}>();
</script>

<template>
    <svg
        class="pointer-events-none absolute inset-0 h-full w-full"
        :viewBox="`0 0 ${width} ${height}`"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <path
            v-for="(d, index) in paths"
            :key="index"
            :d="d"
            fill="none"
            stroke="var(--border)"
            stroke-width="2"
        />
    </svg>
</template>
