<script setup lang="ts">
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';
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
</script>

<template>
    <li class="flex items-center justify-between gap-4 px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <UserAvatar :name="user.name" :src="user.avatarUrl" />
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
