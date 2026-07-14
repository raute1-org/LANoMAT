<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { discord as loginDiscord } from '@/routes/login';
import { edit as editTeam, join as joinTeam } from '@/routes/teams';
import type { TeamDetail } from '@/types';

const props = defineProps<{
    team: TeamDetail;
    isMember: boolean;
    labels: Record<string, string>;
}>();

const page = usePage();
const isAuthenticated = computed(() => page.props.auth.user !== null);
const isOwner = computed(
    () => page.props.auth.user?.id === props.team.owner.id,
);

const joinForm = useForm({ message: '' });

function join() {
    joinForm.post(joinTeam.url(props.team.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="team.name" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <div class="flex items-center gap-4">
            <img
                v-if="team.logoUrl"
                :src="team.logoUrl"
                :alt="team.name"
                class="h-16 w-16 rounded-md object-cover"
            />
            <div
                v-else
                class="flex h-16 w-16 items-center justify-center rounded-md bg-muted text-lg font-semibold"
            >
                {{ team.tag }}
            </div>
            <div>
                <h1 class="text-3xl font-bold tracking-tight">
                    {{ team.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ team.tag }} · {{ labels.owner }}: {{ team.owner.name }}
                </p>
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <Button v-if="isOwner" as-child variant="outline">
                <a :href="editTeam.url(team.id)">{{ labels.edit }}</a>
            </Button>
            <Button
                v-else-if="isAuthenticated && !isMember"
                :disabled="joinForm.processing"
                @click="join"
            >
                {{ labels.join }}
            </Button>
            <Button v-else-if="!isAuthenticated" as-child>
                <a :href="loginDiscord.url()">{{ labels.login_to_join }}</a>
            </Button>
        </div>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.members }}</h2>
            <ul class="mt-4 divide-y divide-border rounded-lg border border-border">
                <li
                    v-for="member in team.members"
                    :key="member.id"
                    class="flex items-center justify-between px-4 py-3"
                >
                    <span>{{ member.user.name }}</span>
                    <span
                        v-if="member.role === 'owner'"
                        class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                    >
                        {{ labels.owner }}
                    </span>
                </li>
            </ul>
        </section>
    </main>
</template>
