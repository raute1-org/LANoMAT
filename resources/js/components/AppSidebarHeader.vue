<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useModules } from '@/composables/useModules';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const { has } = useModules();
const isAuthenticated = computed(() => page.props.auth.user !== null);
const notificationLabels = computed(() => page.props.notificationLabels);
// The launcher is the way onto the LAN: the download is the one shell action
// that must never hide in the sidebar. The route only exists with GameRecipes.
const launcherAvailable = computed(
    () => isAuthenticated.value && has('game_recipes'),
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div v-if="isAuthenticated" class="ml-auto flex items-center gap-2">
            <Button
                v-if="launcherAvailable"
                as-child
                size="lg"
                class="gap-2 px-3 font-semibold shadow-sm sm:px-4"
                data-test="header-launcher"
            >
                <a
                    href="/launcher/download-latest"
                    aria-label="Launcher herunterladen"
                >
                    <Download class="size-4" aria-hidden="true" />
                    <span class="hidden sm:inline">Launcher herunterladen</span>
                    <span class="sm:hidden">Launcher</span>
                </a>
            </Button>
            <NotificationBell :labels="notificationLabels" />
        </div>
    </header>
</template>
