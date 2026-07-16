<script setup lang="ts">
defineProps<{
    data: { qrSvg?: string; caption?: string | null };
    config: { headline?: string };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div
        class="mx-auto flex h-full max-w-4xl flex-col items-center justify-center gap-8 px-12 text-center"
    >
        <h1 class="text-5xl font-bold tracking-tight">
            {{ config.headline ?? labels.payment_qr_title }}
        </h1>

        <!-- eslint-disable-next-line vue/no-v-html -- server-rendered QR SVG, no user input -->
        <div
            v-if="data.qrSvg"
            class="w-96 rounded-xl bg-white p-8"
            v-html="data.qrSvg"
        />
        <p v-else class="text-3xl text-white/80">
            {{ labels.payment_qr_empty }}
        </p>

        <p v-if="data.caption" class="text-3xl text-white/80">
            {{ data.caption }}
        </p>
    </div>
</template>
