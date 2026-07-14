<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    profile: {
        name: string;
        avatarUrl: string | null;
        bio: string | null;
        steamUrl: string | null;
        profileColor: string | null;
    };
    labels: Record<string, string>;
}>();
</script>

<template>
    <Head :title="profile.name" />

    <main class="mx-auto max-w-2xl px-4 py-12">
        <div class="flex items-center gap-4">
            <div
                class="h-16 w-16 shrink-0 rounded-full bg-cover bg-center ring-2"
                :style="{
                    backgroundImage: profile.avatarUrl
                        ? `url(${profile.avatarUrl})`
                        : undefined,
                    '--tw-ring-color': profile.profileColor ?? undefined,
                }"
            />
            <h1 class="text-3xl font-bold tracking-tight">
                {{ profile.name }}
            </h1>
        </div>

        <section class="mt-8">
            <h2 class="text-sm text-muted-foreground">{{ labels.bio }}</h2>
            <p class="mt-1 whitespace-pre-line">
                {{ profile.bio || labels.no_bio }}
            </p>
        </section>

        <a
            v-if="profile.steamUrl"
            :href="profile.steamUrl"
            target="_blank"
            rel="noopener"
            class="mt-6 inline-block text-primary underline"
        >
            {{ labels.steam }}
        </a>
    </main>
</template>
