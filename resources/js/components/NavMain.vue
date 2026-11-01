<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
    label: string;
}>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

function isActive(item: NavItem): boolean {
    if (item.isActive !== undefined) {
        return item.isActive;
    }

    return item.prefix
        ? isCurrentOrParentUrl(item.href)
        : isCurrentUrl(item.href);
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="item.indicator"
                            class="ml-auto size-2 shrink-0 rounded-full bg-ok group-data-[collapsible=icon]:hidden"
                            data-test="nav-indicator"
                        >
                            <span class="sr-only">{{ item.indicator }}</span>
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
