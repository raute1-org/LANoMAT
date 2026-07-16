<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    leave as leaveTeam,
    respond as respondToRequest,
    update as updateTeam,
} from '@/routes/teams';
import type { TeamEditDetail } from '@/types';

const props = defineProps<{
    team: TeamEditDetail;
    labels: Record<string, string>;
}>();

const form = useForm({
    name: props.team.name,
    tag: props.team.tag,
    logo: null as File | null,
});

function submit() {
    form.patch(updateTeam.url(props.team.id), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function onLogoChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.logo = target.files?.[0] ?? null;
}

function respond(requestId: number, accept: boolean) {
    useForm({ accept }).post(
        respondToRequest.url({ team: props.team.id, teamRequest: requestId }),
        { preserveScroll: true },
    );
}

function leave() {
    if (!confirm(props.labels.leave_confirm)) {
        return;
    }

    useForm({}).delete(leaveTeam.url(props.team.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="team.name" />

    <main class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.edit }} — {{ team.name }}
        </h1>

        <form class="mt-8 space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">{{ labels.create_name }}</Label>
                <Input id="name" v-model="form.name" maxlength="64" required />
                <p v-if="form.errors.name" class="text-sm text-destructive">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="tag">{{ labels.create_tag }}</Label>
                <Input id="tag" v-model="form.tag" maxlength="16" required />
                <p v-if="form.errors.tag" class="text-sm text-destructive">
                    {{ form.errors.tag }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="logo">{{ labels.logo }}</Label>
                <img
                    v-if="team.logoUrl"
                    :src="team.logoUrl"
                    :alt="team.name"
                    width="64"
                    height="64"
                    class="h-16 w-16 rounded-md object-cover"
                />
                <input
                    id="logo"
                    type="file"
                    accept="image/*"
                    class="text-sm"
                    @change="onLogoChange"
                />
                <p v-if="form.errors.logo" class="text-sm text-destructive">
                    {{ form.errors.logo }}
                </p>
            </div>

            <Button type="submit" :disabled="form.processing">
                {{ labels.save }}
            </Button>
        </form>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.requests }}</h2>
            <p
                v-if="team.joinRequests.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ labels.no_requests }}
            </p>
            <ul
                v-else
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
                <li
                    v-for="request in team.joinRequests"
                    :key="request.id"
                    class="flex items-center justify-between gap-4 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ request.user.name }}</p>
                        <p
                            v-if="request.message"
                            class="text-sm text-muted-foreground"
                        >
                            {{ request.message }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button size="sm" @click="respond(request.id, true)">
                            {{ labels.accept }}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="respond(request.id, false)"
                        >
                            {{ labels.decline }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="text-xl font-semibold">{{ labels.members }}</h2>
            <ul
                class="mt-4 divide-y divide-border rounded-lg border border-border"
            >
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
            <Button class="mt-4" variant="destructive" @click="leave">
                {{ labels.leave }}
            </Button>
        </section>
    </main>
</template>
