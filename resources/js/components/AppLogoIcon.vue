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
            <circle cx="5.8" cy="2.7" r="2.5" />
            <circle cx="5.8" cy="8.9" r="2.5" />
            <circle cx="5.8" cy="15.1" r="2.5" />
            <circle cx="5.8" cy="21.3" r="2.5" />
            <circle cx="12" cy="21.3" r="2.5" />
        </g>
        <circle class="fill-primary" cx="18.2" cy="21.3" r="2.5" />
    </svg>
</template>
