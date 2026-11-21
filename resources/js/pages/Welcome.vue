<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import HomeChat from '@/components/home/HomeChat.vue';
import { Button } from '@/components/ui/button';
import { useModules } from '@/composables/useModules';
import type { HomeChatData } from '@/types';

defineProps<{
    labels: {
        tagline: string;
        hint: string;
        login: string;
    };
    /** Guest-safe chat data: published recipes, no event/member data, no servers. */
    chat: HomeChatData;
}>();

// The launcher page ships with the GameRecipes module; without it the route 404s.
const { has } = useModules();
</script>

<template>
    <Head title="Willkommen" />

    <main class="min-h-svh bg-background px-4 py-8 sm:py-14">
        <div class="mx-auto w-full max-w-3xl">
            <!-- The chat is the first thing on the page; the tagline, the hint
                 and the login sit below it (docs/design.md "Start-page chat"). -->
            <header class="flex flex-col items-center gap-3 text-center">
                <AppLogoIcon class="size-12 fill-current text-foreground" />

                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    LANoMAT
                </h1>
            </header>

            <!-- The arcade start hero: talk to it before logging in. -->
            <div class="mt-6 sm:mt-8">
                <HomeChat :chat="chat" guest />
            </div>

            <div class="mt-6 flex flex-col items-center gap-4 text-center">
                <p class="text-base text-foreground">{{ labels.tagline }}</p>
                <p class="mx-auto max-w-md text-sm text-muted-foreground">
                    {{ labels.hint }}
                </p>

                <Button
                    as="a"
                    href="/auth/discord/redirect"
                    size="lg"
                    data-test="welcome-login-discord"
                >
                    <svg
                        viewBox="0 0 127.14 96.36"
                        xmlns="http://www.w3.org/2000/svg"
                        class="size-4"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z"
                        />
                    </svg>
                    {{ labels.login }}
                </Button>

                <a
                    v-if="has('game_recipes')"
                    href="/launcher"
                    class="rounded-sm text-sm text-muted-foreground underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    data-test="welcome-launcher"
                >
                    LANoMAT Launcher herunterladen
                </a>
            </div>
        </div>
    </main>
</template>
