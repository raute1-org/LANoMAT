<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

/**
 * The one place an avatar is rendered. `src` is always LANoMAT's own avatar URL (served to logged-in
 * users only), never a Discord CDN address. No src, or a failed load, shows the monogram.
 */
type AvatarSize = 24 | 32 | 36 | 40 | 64;

const props = withDefaults(
    defineProps<{
        name: string;
        src?: string | null;
        size?: AvatarSize;
        class?: string;
    }>(),
    { src: null, size: 32, class: undefined },
);

const sizeClasses: Record<AvatarSize, string> = {
    24: 'size-6 text-[10px]',
    32: 'size-8 text-xs',
    36: 'size-9 text-sm',
    40: 'size-10 text-sm',
    64: 'size-16 text-xl',
};

const failed = ref(false);

watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);

const showImage = computed(() => !!props.src && !failed.value);
</script>

<template>
    <span
        data-slot="user-avatar"
        :class="
            cn(
                'relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted font-medium text-muted-foreground select-none',
                sizeClasses[size],
                props.class,
            )
        "
    >
        <img
            v-if="showImage"
            :src="src!"
            :alt="name"
            :width="size"
            :height="size"
            loading="lazy"
            decoding="async"
            class="size-full object-cover"
            @error="failed = true"
        />
        <span v-else aria-hidden="true">{{ getInitials(name) }}</span>
    </span>
</template>
