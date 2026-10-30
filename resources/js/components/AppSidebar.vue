<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarDays,
    FolderOpen,
    HandHelping,
    MonitorPlay,
    Server,
    UtensilsCrossed,
    Vote,
    Image,
    Mic,
    PartyPopper,
    Swords,
    Trophy,
    UserCheck,
    UserPlus,
    Users,
    Armchair,
    LayoutDashboard,
    Ticket,
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
import { useModules } from '@/composables/useModules';
import { homeHref } from '@/lib/home';
import { dashboard } from '@/routes';
import { show as cateringShow } from '@/routes/catering';
import {
    index as eventsIndex,
    show as eventsShow,
    register as eventsRegister,
    seating as eventsSeating,
} from '@/routes/events';
import { index as filesIndex } from '@/routes/files';
import { index as friendsIndex } from '@/routes/friends';
import { index as galleryIndex } from '@/routes/gallery';
import { index as jukeboxIndex } from '@/routes/jukebox';
import { index as lfgIndex } from '@/routes/lfg';
import { checkin as orgaCheckin } from '@/routes/orga';
import { index as pollsIndex } from '@/routes/polls';
import { show as presenceShow } from '@/routes/presence';
import { index as scheduleIndex } from '@/routes/schedule';
import { control as screenControl } from '@/routes/screen';
import { index as serversIndex } from '@/routes/servers';
import { leaderboard as statsLeaderboard } from '@/routes/stats';
import { index as teamsIndex } from '@/routes/teams';
import { index as tournamentsIndex } from '@/routes/tournaments';
import { setup as voiceSetup } from '@/routes/voice';
import type { NavItem } from '@/types';
import type { EventSummary } from '@/types/events';

const page = usePage();
const currentEvent = computed<EventSummary | null>(
    () => page.props.currentEvent,
);
const isStaff = computed<boolean>(
    () => page.props.auth?.user?.is_staff === true,
);

const { has } = useModules();

const generalItems = computed<NavItem[]>(() => [
    { title: 'Dashboard', href: dashboard(), icon: LayoutDashboard },
    {
        title: 'Aktuelle LAN',
        href: homeHref(currentEvent.value),
        icon: PartyPopper,
        // With an active LAN the event group's "Übersicht" owns the highlight
        // for the same URL, so it is not marked twice.
        ...(currentEvent.value ? { isActive: false } : {}),
    },
    { title: 'Events', href: eventsIndex(), icon: CalendarDays },
    { title: 'Teams', href: teamsIndex(), icon: Users, prefix: true },
    ...(has('stats')
        ? [{ title: 'Bestenliste', href: statsLeaderboard(), icon: Trophy }]
        : []),
    ...(has('friends')
        ? [{ title: 'Freunde', href: friendsIndex(), icon: UserPlus }]
        : []),
    ...(has('voice')
        ? [{ title: 'Voice einrichten', href: voiceSetup(), icon: Mic }]
        : []),
]);

const eventItems = computed<NavItem[]>(() => {
    const ev = currentEvent.value;

    if (!ev) {
        return [];
    }

    const items: NavItem[] = [
        { title: 'Übersicht', href: eventsShow(ev.slug), icon: PartyPopper },
        ...(has('schedule')
            ? [
                  {
                      title: 'Zeitplan',
                      href: scheduleIndex(ev.slug),
                      icon: CalendarClock,
                  },
              ]
            : []),
        ...(ev.availability === 'open'
            ? [
                  {
                      title: 'Anmeldung',
                      href: eventsRegister(ev.slug),
                      icon: Ticket,
                  },
              ]
            : []),
        { title: 'Turniere', href: tournamentsIndex(ev.slug), icon: Swords },
        { title: 'Sitzplan', href: eventsSeating(ev.slug), icon: Armchair },
        ...(has('presence')
            ? [{ title: 'Präsenz', href: presenceShow(ev.slug), icon: Radio }]
            : []),
        ...(has('jukebox')
            ? [{ title: 'Jukebox', href: jukeboxIndex(ev.slug), icon: Music }]
            : []),
        ...(has('gallery')
            ? [{ title: 'Galerie', href: galleryIndex(ev.slug), icon: Image }]
            : []),
    ];

    if (isStaff.value) {
        items.push({
            title: 'Check-in',
            href: orgaCheckin(ev.slug),
            icon: UserCheck,
        });

        if (has('infoscreen')) {
            items.push({
                title: 'Infoscreen-Steuerung',
                href: screenControl(ev.slug),
                icon: MonitorPlay,
            });
        }
    }

    return items;
});

// Secondary event modules: every one of them is gated by its module toggle,
// so the "Mehr" group disappears entirely on a core profile.
const moreItems = computed<NavItem[]>(() => {
    const ev = currentEvent.value;

    if (!ev) {
        return [];
    }

    return [
        ...(has('catering')
            ? [
                  {
                      title: 'Essensbestellung',
                      href: cateringShow(ev.slug),
                      icon: UtensilsCrossed,
                  },
              ]
            : []),
        ...(has('voting')
            ? [
                  {
                      title: 'Abstimmungen',
                      href: pollsIndex(ev.slug),
                      icon: Vote,
                      prefix: true,
                  },
              ]
            : []),
        ...(has('lfg')
            ? [
                  {
                      title: 'Mitspieler finden',
                      href: lfgIndex(ev.slug),
                      icon: HandHelping,
                  },
              ]
            : []),
        ...(has('files')
            ? [
                  {
                      title: 'Dateien',
                      href: filesIndex(ev.slug),
                      icon: FolderOpen,
                  },
              ]
            : []),
        ...(has('game_servers')
            ? [
                  {
                      title: 'Spielserver',
                      href: serversIndex(ev.slug),
                      icon: Server,
                  },
              ]
            : []),
    ];
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
            <nav aria-label="Hauptnavigation" class="contents">
                <NavMain :items="generalItems" label="Allgemein" />
                <NavMain
                    v-if="eventItems.length > 0"
                    :items="eventItems"
                    :label="eventGroupLabel"
                />
                <NavMain
                    v-if="moreItems.length > 0"
                    :items="moreItems"
                    label="Mehr"
                />
            </nav>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
