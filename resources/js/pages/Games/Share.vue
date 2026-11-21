<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageContainer from '@/components/PageContainer.vue';
</script>

<template>
    <Head title="Spiel teilen" />

    <PageContainer
        width="default"
        title="Spiel teilen"
        description="Lade ein Spiel hoch und beschreibe es. Die Orga prüft es und gibt es für alle frei."
        :back="{ href: '/games', label: 'Spiele' }"
    />
</template>
