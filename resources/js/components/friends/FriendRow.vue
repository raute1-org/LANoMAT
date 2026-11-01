<script setup lang="ts">
import UserAvatar from '@/components/UserAvatar.vue';
import type { FriendUserDto } from '@/types';

defineProps<{
    user: FriendUserDto;
}>();
</script>

<template>
    <li class="flex items-center justify-between gap-4 px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <UserAvatar :name="user.name" :src="user.avatarUrl" />
            <span class="truncate font-medium">{{ user.name }}</span>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <slot />
        </div>
    </li>
</template>
