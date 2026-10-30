<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LayoutDashboard, LogOut, Settings } from '@lucide/vue';
import Dashboard from '@/actions/Filament/Pages/Dashboard';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem v-if="user.can_access_admin" :as-child="true">
            <!-- Full-page anchor: /admin is a separate Filament app, not an Inertia page. -->
            <a
                class="block w-full cursor-pointer"
                :href="Dashboard.url()"
                data-test="admin-link"
            >
                <LayoutDashboard class="mr-2 h-4 w-4" />
                Admin-Bereich
            </a>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Einstellungen
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Abmelden
        </Link>
    </DropdownMenuItem>
</template>
