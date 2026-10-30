<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <g fill="currentColor">
            <circle cx="4.5" cy="4.5" r="2.6" />
            <circle cx="4.5" cy="12" r="2.6" />
            <circle cx="4.5" cy="19.5" r="2.6" />
            <circle cx="12" cy="19.5" r="2.6" />
        </g>
        <circle class="fill-primary" cx="19.5" cy="19.5" r="2.6" />
    </svg>
</template>
