<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FriendRow from '@/components/friends/FriendRow.vue';
import RequestRow from '@/components/friends/RequestRow.vue';
import SuggestionRow from '@/components/friends/SuggestionRow.vue';
import { Button } from '@/components/ui/button';
import {
    block as blockUser,
    cancel as cancelRequest,
    remove as removeFriend,
    request as sendRequest,
    respond as respondToRequest,
    unblock as unblockUser,
} from '@/routes/friends';
import type {
    FriendUserDto,
    IncomingRequestDto,
    OutgoingRequestDto,
    SuggestionDto,
} from '@/types';

defineProps<{
    friends: FriendUserDto[];
    incoming: IncomingRequestDto[];
    outgoing: OutgoingRequestDto[];
    suggestions: SuggestionDto[];
    blocked: FriendUserDto[];
    labels: Record<string, string>;
}>();

function respond(friendshipId: number, accept: boolean) {
    useForm({ accept }).patch(respondToRequest.url(friendshipId), {
        preserveScroll: true,
    });
}

function cancel(friendshipId: number) {
    useForm({}).delete(cancelRequest.url(friendshipId), {
        preserveScroll: true,
    });
}

function remove(userId: number, confirmMessage: string) {
    if (!confirm(confirmMessage)) {
        return;
    }

    useForm({}).delete(removeFriend.url(userId), { preserveScroll: true });
}

function block(userId: number) {
    useForm({}).post(blockUser.url(userId), { preserveScroll: true });
}

function unblock(userId: number, confirmMessage: string) {
    if (!confirm(confirmMessage)) {
        return;
    }

    useForm({}).delete(unblockUser.url(userId), { preserveScroll: true });
}

function add(userId: number) {
    useForm({ user_id: userId }).post(sendRequest.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <header class="space-y-0.5">
            <h1 class="text-3xl font-bold tracking-tight">
                {{ labels.title }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ labels.description }}
            </p>
        </header>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.incoming_title }}</h2>
            <p
                v-if="incoming.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ labels.incoming_empty }}
            </p>
            <ul
                v-else
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
                <RequestRow
                    v-for="request in incoming"
                    :key="request.friendshipId"
                    :user="request.from"
                    :labels="labels"
                    @accept="respond(request.friendshipId, true)"
                    @decline="respond(request.friendshipId, false)"
                />
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.friends_title }}</h2>
            <p
                v-if="friends.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ labels.friends_empty }}
            </p>
            <ul
                v-else
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
                <FriendRow
                    v-for="friend in friends"
                    :key="friend.id"
                    :user="friend"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="remove(friend.id, labels.remove_confirm)"
                    >
                        {{ labels.remove }}
                    </Button>
                    <Button
                        size="sm"
                        variant="destructive"
                        @click="block(friend.id)"
                    >
                        {{ labels.block }}
                    </Button>
                </FriendRow>
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.outgoing_title }}</h2>
            <p
                v-if="outgoing.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ labels.outgoing_empty }}
            </p>
            <ul
                v-else
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
                <FriendRow
                    v-for="request in outgoing"
                    :key="request.friendshipId"
                    :user="request.to"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="cancel(request.friendshipId)"
                    >
                        {{ labels.cancel }}
                    </Button>
                </FriendRow>
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">
                {{ labels.suggestions_title }}
            </h2>
            <p
                v-if="suggestions.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ labels.suggestions_empty }}
            </p>
            <ul
                v-else
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
                <SuggestionRow
                    v-for="suggestion in suggestions"
                    :key="suggestion.id"
                    :suggestion="suggestion"
                    :labels="labels"
                    @add="add(suggestion.id)"
                />
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.blocked_title }}</h2>
            <p
                v-if="blocked.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ labels.blocked_empty }}
            </p>
            <ul
                v-else
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
                <FriendRow v-for="user in blocked" :key="user.id" :user="user">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="unblock(user.id, labels.unblock_confirm)"
                    >
                        {{ labels.unblock }}
                    </Button>
                </FriendRow>
            </ul>
        </section>
    </main>
</template>
