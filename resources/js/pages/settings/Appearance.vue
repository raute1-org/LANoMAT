<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Darstellung',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Darstellung" />

    <h1 class="sr-only">Darstellung</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Darstellung"
            description="Wähle, ob LANoMAT hell, dunkel oder passend zu deinem System angezeigt wird"
        />
        <AppearanceTabs />
    </div>
</template>
