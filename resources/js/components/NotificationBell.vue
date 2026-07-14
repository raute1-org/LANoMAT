<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { read } from '@/routes/notifications';

type Props = {
    labels: Record<string, string>;
};

defineProps<Props>();

const page = usePage();
const notifications = computed(() => page.props.unreadNotifications);

function markAsRead(id: string) {
    router.post(
        read.url({ notification: id }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <Button
                variant="ghost"
                size="icon"
                class="group relative h-9 w-9 cursor-pointer"
            >
                <Bell class="size-5 opacity-80 group-hover:opacity-100" />
                <Badge
                    v-if="notifications.length > 0"
                    variant="destructive"
                    class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px]"
                >
                    {{ notifications.length }}
                </Badge>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <DropdownMenuLabel>{{ labels.title }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <p
                v-if="notifications.length === 0"
                class="px-2 py-4 text-center text-sm text-muted-foreground"
            >
                {{ labels.empty }}
            </p>
            <DropdownMenuItem
                v-for="notification in notifications"
                :key="notification.id"
                class="flex flex-col items-start gap-1 whitespace-normal"
                @select.prevent="markAsRead(notification.id)"
            >
                <span class="text-sm font-medium">{{
                    notification.title
                }}</span>
                <span class="text-xs text-muted-foreground">{{
                    notification.body
                }}</span>
                <span class="text-xs text-primary">{{
                    labels.mark_read
                }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
