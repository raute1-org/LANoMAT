<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import NavItemBadge from '@/components/NavItemBadge.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

const props = defineProps<{
    label: string;
    icon: LucideIcon;
    items: NavItem[];
    storageKey: string;
}>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { state, setOpen } = useSidebar();

const key = computed(() => `lanomat.nav.${props.storageKey}`);

function isActive(item: NavItem): boolean {
    if (item.isActive !== undefined) {
        return item.isActive;
    }

    return item.prefix
        ? isCurrentOrParentUrl(item.href)
        : isCurrentUrl(item.href);
}

function readStored(): boolean {
    try {
        return localStorage.getItem(key.value) === '1';
    } catch {
        return false;
    }
}

function store(value: boolean): void {
    try {
        localStorage.setItem(key.value, value ? '1' : '0');
    } catch {
        // Private mode or blocked storage: the group just starts closed.
    }
}

const hasActive = computed(() => props.items.some(isActive));
const open = ref<boolean>(hasActive.value || readStored());

// Navigating to an item inside a closed group reveals it.
watch(hasActive, (active) => {
    if (active) {
        open.value = true;
    }
});

function onUpdate(value: boolean): void {
    if (state.value === 'collapsed') {
        // Icon-collapsed rail: any click expands the sidebar and shows this
        // group's items instead of toggling it closed.
        setOpen(true);
        value = true;
    }

    open.value = value;
    store(value);
}
</script>

<template>
    <SidebarGroup v-if="items.length > 0" class="px-2 py-0">
        <SidebarMenu>
            <Collapsible
                as-child
                :open="open"
                class="group/collapsible"
                @update:open="onUpdate"
            >
                <SidebarMenuItem>
                    <CollapsibleTrigger as-child>
                        <SidebarMenuButton :tooltip="label">
                            <component :is="icon" />
                            <span>{{ label }}</span>
                            <ChevronRight
                                class="ml-auto transition-transform group-data-[state=open]/collapsible:rotate-90 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                        </SidebarMenuButton>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <SidebarMenuSub>
                            <SidebarMenuSubItem
                                v-for="item in items"
                                :key="item.title"
                            >
                                <SidebarMenuSubButton
                                    as-child
                                    :is-active="isActive(item)"
                                >
                                    <Link :href="item.href">
                                        <span>{{ item.title }}</span>
                                        <span
                                            v-if="item.indicator"
                                            class="ml-auto size-2 shrink-0 rounded-full bg-ok"
                                            data-test="nav-indicator"
                                        >
                                            <span class="sr-only">{{
                                                item.indicator
                                            }}</span>
                                        </span>
                                        <NavItemBadge
                                            v-if="item.badge"
                                            :badge="item.badge"
                                        />
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        </SidebarMenuSub>
                    </CollapsibleContent>
                </SidebarMenuItem>
            </Collapsible>
        </SidebarMenu>
    </SidebarGroup>
</template>
