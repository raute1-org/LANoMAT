<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editConnections } from '@/routes/connections';
import { edit } from '@/routes/profile';

const props = defineProps<{
    profile: {
        bio: string | null;
        steamUrl: string | null;
        streamUrl: string | null;
        profileColor: string | null;
        hasVerifiedSteamLink: boolean;
        verifiedSteamNickname: string | null;
    };
    labels: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const bio = ref(props.profile.bio ?? '');
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="bio">{{ props.labels.bio }}</Label>
                <textarea
                    id="bio"
                    v-model="bio"
                    name="bio"
                    rows="4"
                    class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs"
                    :placeholder="props.labels.bio_placeholder"
                />
                <InputError class="mt-2" :message="errors.bio" />
            </div>

            <div class="grid gap-2">
                <Label for="steam_url">{{ props.labels.steam_url }}</Label>
                <Input
                    id="steam_url"
                    type="url"
                    class="mt-1 block w-full"
                    name="steam_url"
                    :default-value="props.profile.steamUrl ?? ''"
                    placeholder="https://steamcommunity.com/id/…"
                />
                <InputError class="mt-2" :message="errors.steam_url" />
                <p
                    v-if="props.profile.hasVerifiedSteamLink"
                    class="text-sm text-muted-foreground"
                    data-test="steam-url-verified-hint"
                >
                    {{
                        props.labels.steam_url_verified_hint?.replace(
                            ':nickname',
                            props.profile.verifiedSteamNickname ?? '',
                        )
                    }}
                    <Link
                        :href="editConnections()"
                        class="text-primary underline underline-offset-4"
                        >{{ props.labels.steam_url_connections_link }}</Link
                    >
                </p>
                <p
                    v-else
                    class="text-sm text-muted-foreground"
                    data-test="steam-url-unverified-hint"
                >
                    {{ props.labels.steam_url_unverified_hint }}
                    <Link
                        :href="editConnections()"
                        class="text-primary underline underline-offset-4"
                        >{{ props.labels.steam_url_connections_link }}</Link
                    >
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="stream_url">{{ props.labels.stream_url }}</Label>
                <Input
                    id="stream_url"
                    type="url"
                    class="mt-1 block w-full"
                    name="stream_url"
                    :default-value="props.profile.streamUrl ?? ''"
                    :placeholder="props.labels.stream_url_placeholder"
                />
                <InputError class="mt-2" :message="errors.stream_url" />
            </div>

            <div class="grid gap-2">
                <Label for="profile_color">{{
                    props.labels.profile_color
                }}</Label>
                <Input
                    id="profile_color"
                    type="color"
                    class="h-10 w-16 p-1"
                    name="profile_color"
                    :default-value="props.profile.profileColor ?? '#000000'"
                />
                <InputError class="mt-2" :message="errors.profile_color" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
