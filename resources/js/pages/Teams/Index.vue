<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as storeTeam } from '@/routes/teams';
import { show as showTeam } from '@/routes/teams';
import type { TeamSummary } from '@/types';

defineProps<{
    teams: TeamSummary[];
    labels: Record<string, string>;
}>();

const open = ref(false);
const form = useForm({ name: '', tag: '' });

function submit() {
    form.post(storeTeam.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-5xl px-4 py-12">
        <div class="flex items-center justify-between">
            <h1 class="text-3xl font-bold tracking-tight">
                {{ labels.title }}
            </h1>

            <Dialog v-model:open="open">
                <DialogTrigger as-child>
                    <Button>{{ labels.create }}</Button>
                </DialogTrigger>
                <DialogContent>
                    <form class="space-y-4" @submit.prevent="submit">
                        <DialogHeader>
                            <DialogTitle>{{ labels.create }}</DialogTitle>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="name">{{ labels.create_name }}</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                maxlength="64"
                                required
                            />
                            <p
                                v-if="form.errors.name"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="tag">{{ labels.create_tag }}</Label>
                            <Input
                                id="tag"
                                v-model="form.tag"
                                maxlength="16"
                                required
                            />
                            <p
                                v-if="form.errors.tag"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.tag }}
                            </p>
                        </div>

                        <DialogFooter>
                            <Button type="submit" :disabled="form.processing">
                                {{ labels.create }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>

        <p
            v-if="teams.length === 0"
            class="mt-8 text-sm text-muted-foreground"
        >
            {{ labels.no_teams }}
        </p>

        <div v-else class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="team in teams"
                :key="team.id"
                :href="showTeam.url(team.id)"
                class="flex items-center gap-4 rounded-lg border border-border p-4 transition hover:bg-accent"
            >
                <img
                    v-if="team.logoUrl"
                    :src="team.logoUrl"
                    :alt="team.name"
                    class="h-12 w-12 rounded-md object-cover"
                />
                <div
                    v-else
                    class="flex h-12 w-12 items-center justify-center rounded-md bg-muted text-sm font-semibold"
                >
                    {{ team.tag }}
                </div>
                <div>
                    <p class="font-semibold">{{ team.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ team.tag }} · {{ team.memberCount }}
                        {{ labels.members }}
                    </p>
                </div>
            </Link>
        </div>
    </main>
</template>
