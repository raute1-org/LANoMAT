<script setup lang="ts">
defineProps<{
    data: { logos?: string[] };
    config: { headline?: string };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.sponsors_title }}
        </h1>

        <p
            v-if="!data.logos || data.logos.length === 0"
            class="text-3xl text-muted-foreground"
        >
            {{ labels.sponsors_empty }}
        </p>

        <div
            v-else
            class="grid flex-1 grid-cols-4 items-center gap-8 overflow-auto"
        >
            <div
                v-for="(logo, index) in data.logos"
                :key="index"
                class="flex h-40 items-center justify-center rounded-xl bg-card p-6"
            >
                <img
                    :src="logo"
                    :alt="
                        labels.sponsors_logo_alt.replace(
                            ':index',
                            String(index + 1),
                        )
                    "
                    width="240"
                    height="112"
                    loading="lazy"
                    class="max-h-full max-w-full object-contain"
                />
            </div>
        </div>
    </div>
</template>
