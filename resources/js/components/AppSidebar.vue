<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarDays,
    Image,
    Mic,
    PartyPopper,
    Swords,
    Trophy,
    UserCheck,
    UserPlus,
    Users,
    Armchair,
    Radio,
    Music,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { homeHref } from '@/lib/home';
import { index as eventsIndex, show as eventsShow, seating as eventsSeating } from '@/routes/events';
import { index as friendsIndex } from '@/routes/friends';
import { index as galleryIndex } from '@/routes/gallery';
import { index as jukeboxIndex } from '@/routes/jukebox';
import { checkin as orgaCheckin } from '@/routes/orga';
import { show as presenceShow } from '@/routes/presence';
import { index as scheduleIndex } from '@/routes/schedule';
import { leaderboard as statsLeaderboard } from '@/routes/stats';
import { index as teamsIndex } from '@/routes/teams';
import { index as tournamentsIndex } from '@/routes/tournaments';
import { setup as voiceSetup } from '@/routes/voice';
import type { NavItem } from '@/types';
import type { EventSummary } from '@/types/events';

const page = usePage();
const currentEvent = computed<EventSummary | null>(() => page.props.currentEvent);
const isStaff = computed<boolean>(() => page.props.auth?.user?.is_staff === true);

const generalItems = computed<NavItem[]>(() => [
    { title: 'Aktuelle LAN', href: homeHref(currentEvent.value), icon: PartyPopper },
    { title: 'Events', href: eventsIndex(), icon: CalendarDays },
    { title: 'Teams', href: teamsIndex(), icon: Users },
    { title: 'Bestenliste', href: statsLeaderboard(), icon: Trophy },
    { title: 'Freunde', href: friendsIndex(), icon: UserPlus },
    { title: 'Voice einrichten', href: voiceSetup(), icon: Mic },
]);

const eventItems = computed<NavItem[]>(() => {
    const ev = currentEvent.value;

    if (!ev) {
        return [];
    }

    const items: NavItem[] = [
        { title: 'Übersicht', href: eventsShow(ev.slug), icon: PartyPopper },
        { title: 'Zeitplan', href: scheduleIndex(ev.slug), icon: CalendarClock },
        { title: 'Turniere', href: tournamentsIndex(ev.slug), icon: Swords },
        { title: 'Sitzplan', href: eventsSeating(ev.slug), icon: Armchair },
        { title: 'Präsenz', href: presenceShow(ev.slug), icon: Radio },
        { title: 'Jukebox', href: jukeboxIndex(ev.slug), icon: Music },
        { title: 'Galerie', href: galleryIndex(ev.slug), icon: Image },
    ];

    if (isStaff.value) {
        items.push({ title: 'Check-in', href: orgaCheckin(ev.slug), icon: UserCheck });
    }

    return items;
});

const eventGroupLabel = computed(() =>
    currentEvent.value ? `Aktuelle LAN: ${currentEvent.value.name}` : '',
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="homeHref(currentEvent)">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="generalItems" label="Allgemein" />
            <NavMain
                v-if="eventItems.length > 0"
                :items="eventItems"
                :label="eventGroupLabel"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
