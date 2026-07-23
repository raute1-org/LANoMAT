<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { homeHref } from '@/lib/home';
import { index as jukeboxIndex } from '@/routes/jukebox';
import { show as presenceShow } from '@/routes/presence';
import { show as profileShow } from '@/routes/profile';
import { show as recapShow } from '@/routes/recap';
import type { User } from '@/types/auth';
import type { EventSummary } from '@/types/events';

const page = usePage();
const currentEvent = computed<EventSummary | null>(
    () => page.props.currentEvent,
);
const user = computed<User | null>(() => page.props.auth?.user ?? null);
const isLive = computed(() => currentEvent.value?.status === 'live');
</script>

<template>
    <div class="min-h-screen">
        <header
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border bg-background/80 px-4 py-3 backdrop-blur"
        >
            <Link
                :href="homeHref(currentEvent)"
                class="flex items-center gap-2 rounded focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <AppLogo />
            </Link>

            <div v-if="currentEvent" class="flex items-center gap-2">
                <LiveIndicator v-if="isLive" label="LIVE" />
                <span class="text-sm font-medium text-foreground">{{
                    currentEvent.name
                }}</span>
            </div>

            <nav
                v-if="currentEvent"
                class="flex items-center gap-3 text-sm text-muted-foreground"
            >
                <Link
                    :href="presenceShow(currentEvent.slug)"
                    class="rounded hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >Präsenz</Link
                >
                <Link
                    :href="jukeboxIndex(currentEvent.slug)"
                    class="rounded hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >Jukebox</Link
                >
                <Link
                    :href="recapShow(currentEvent.slug)"
                    class="rounded hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >Recap</Link
                >
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <Link
                    v-if="user"
                    :href="profileShow(user.id)"
                    class="flex items-center gap-2 rounded text-sm font-medium text-foreground hover:text-foreground/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{ user.name }}
                </Link>
                <Button v-else as="a" href="/login" size="sm">Anmelden</Button>
            </div>
        </header>

        <slot />
    </div>
    <Toaster />
</template>
