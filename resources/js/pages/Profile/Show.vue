<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';
import {
    block as blockUser,
    cancel as cancelRequest,
    remove as removeFriend,
    request as sendRequest,
    respond as respondToRequest,
    unblock as unblockUser,
} from '@/routes/friends';
import type { RelationshipDto } from '@/types';

const isAuthenticated = computed(() => usePage().props.auth?.user != null);

const props = defineProps<{
    profile: {
        name: string;
        avatarUrl: string | null;
        bio: string | null;
        steamUrl: string | null;
        profileColor: string | null;
    };
    relationship: RelationshipDto | null;
    userId: number;
    labels: Record<string, string>;
    relationshipLabels: Record<string, string>;
}>();

function addFriend(): void {
    useForm({ user_id: props.userId }).post(sendRequest.url(), {
        preserveScroll: true,
    });
}

function cancel(friendshipId: number): void {
    useForm({}).delete(cancelRequest.url(friendshipId), {
        preserveScroll: true,
    });
}

function respond(friendshipId: number, accept: boolean): void {
    useForm({ accept }).patch(respondToRequest.url(friendshipId), {
        preserveScroll: true,
    });
}

function remove(): void {
    if (!confirm(props.relationshipLabels.remove_confirm)) {
        return;
    }

    useForm({}).delete(removeFriend.url(props.userId), {
        preserveScroll: true,
    });
}

function block(): void {
    if (!confirm(props.relationshipLabels.block_confirm)) {
        return;
    }

    useForm({}).post(blockUser.url(props.userId), { preserveScroll: true });
}

function unblock(): void {
    if (!confirm(props.relationshipLabels.unblock_confirm)) {
        return;
    }

    useForm({}).delete(unblockUser.url(props.userId), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="profile.name" />

    <PageContainer width="default" :as="isAuthenticated ? 'div' : 'main'">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <UserAvatar
                    :name="profile.name"
                    :src="profile.avatarUrl"
                    :size="64"
                    class="ring-2"
                    :style="{
                        '--tw-ring-color': profile.profileColor ?? undefined,
                    }"
                />
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ profile.name }}
                </h1>
            </div>

            <div
                v-if="relationship"
                class="flex shrink-0 flex-wrap items-center gap-2"
                data-test="relationship-controls"
            >
                <template v-if="relationship.state === 'none'">
                    <Button data-test="add-friend-button" @click="addFriend">
                        {{ relationshipLabels.add }}
                    </Button>
                </template>

                <template v-else-if="relationship.state === 'request_sent'">
                    <span class="text-sm text-muted-foreground">
                        {{ relationshipLabels.request_sent }}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="cancel-request-button"
                        @click="cancel(relationship.friendshipId!)"
                    >
                        {{ relationshipLabels.cancel }}
                    </Button>
                </template>

                <template v-else-if="relationship.state === 'request_received'">
                    <Button
                        size="sm"
                        data-test="accept-request-button"
                        @click="respond(relationship.friendshipId!, true)"
                    >
                        {{ relationshipLabels.accept }}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="decline-request-button"
                        @click="respond(relationship.friendshipId!, false)"
                    >
                        {{ relationshipLabels.decline }}
                    </Button>
                </template>

                <template v-else-if="relationship.state === 'friends'">
                    <span class="text-sm text-muted-foreground">
                        {{ relationshipLabels.friends }}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="remove-friend-button"
                        @click="remove"
                    >
                        {{ relationshipLabels.remove }}
                    </Button>
                </template>

                <template v-else-if="relationship.state === 'blocked'">
                    <span class="text-sm text-muted-foreground">
                        {{ relationshipLabels.blocked }}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="unblock-button"
                        @click="unblock"
                    >
                        {{ relationshipLabels.unblock }}
                    </Button>
                </template>

                <Button
                    v-if="
                        relationship.state !== 'self' &&
                        relationship.state !== 'blocked'
                    "
                    size="sm"
                    variant="destructive"
                    data-test="block-button"
                    @click="block"
                >
                    {{ relationshipLabels.block }}
                </Button>
            </div>
        </div>

        <section class="mt-8">
            <h2 class="text-sm text-muted-foreground">{{ labels.bio }}</h2>
            <p class="mt-1 whitespace-pre-line">
                {{ profile.bio || labels.no_bio }}
            </p>
        </section>

        <a
            v-if="profile.steamUrl"
            :href="profile.steamUrl"
            target="_blank"
            rel="noopener"
            class="mt-6 inline-block text-primary underline"
        >
            {{ labels.steam }}
        </a>
    </PageContainer>
</template>
