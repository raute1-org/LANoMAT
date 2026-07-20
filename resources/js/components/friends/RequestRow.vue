<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import type { FriendUserDto } from '@/types';

defineProps<{
    user: FriendUserDto;
    labels: Record<string, string>;
    processing?: boolean;
}>();

const emit = defineEmits<{
    accept: [];
    decline: [];
}>();

function initials(name: string): string {
    return name.trim().charAt(0).toUpperCase();
}
</script>

<template>
    <li class="flex items-center justify-between gap-4 px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <Avatar>
                <AvatarImage
                    v-if="user.avatarUrl"
                    :src="user.avatarUrl"
                    :alt="user.name"
                />
                <AvatarFallback>{{ initials(user.name) }}</AvatarFallback>
            </Avatar>
            <span class="truncate font-medium">{{ user.name }}</span>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <Button
                size="sm"
                :disabled="processing"
                data-test="accept-request-button"
                @click="emit('accept')"
            >
                {{ labels.accept }}
            </Button>
            <Button
                size="sm"
                variant="outline"
                :disabled="processing"
                @click="emit('decline')"
            >
                {{ labels.decline }}
            </Button>
        </div>
    </li>
</template>
