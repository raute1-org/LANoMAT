<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Gamepad2,
    CalendarClock,
    CalendarDays,
    Download,
    FolderOpen,
    HandHelping,
    MonitorPlay,
    Server,
    Pizza,
    Vote,
    Image,
    Headphones,
    Mic,
    MonitorDown,
    PartyPopper,
    Radar,
    Swords,
    Trophy,
    UserCheck,
    UserPlus,
    Users,
    Armchair,
    Activity,
    Ellipsis,
    ShieldCheck,
    Ticket,
    Radio,
    Music,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavCollapsibleGroup from '@/components/NavCollapsibleGroup.vue';
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
import { useDesktopClient } from '@/composables/useDesktopClient';
import { useModules } from '@/composables/useModules';
import { formatClock } from '@/lib/datetime';
import { home } from '@/routes';
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
import { index as assistantIndex } from '@/routes/orga/assistant';
import { index as pollsIndex } from '@/routes/polls';
import { show as presenceShow } from '@/routes/presence';
import { index as scheduleIndex } from '@/routes/schedule';
import { control as screenControl } from '@/routes/screen';
import {
    browser as serversBrowser,
    index as serversIndex,
} from '@/routes/servers';
import { leaderboard as statsLeaderboard } from '@/routes/stats';
import { index as teamsIndex } from '@/routes/teams';
import { index as tournamentsIndex } from '@/routes/tournaments';
import { setup as voiceSetup } from '@/routes/voice';
import {
    client as webVoiceClient,
    index as webVoiceIndex,
} from '@/routes/webvoice';
import type { NavItem } from '@/types';
import type { EventSummary } from '@/types/events';
import type { NavBadge, NavSignals } from '@/types/navigation';

const page = usePage();
const currentEvent = computed<EventSummary | null>(
    () => page.props.currentEvent,
);
const isStaff = computed<boolean>(
    () => page.props.auth?.user?.is_staff === true,
);
// Absent or null (guests, backend not providing it): no badges at all.
const signals = computed<NavSignals | null>(
    () => page.props.navSignals ?? null,
);

const { has } = useModules();
const { client: desktopClient } = useDesktopClient();

function foodBadge(closesAt: string | null | undefined): NavBadge | undefined {
    if (!closesAt) {
        return undefined;
    }

    return { text: `bis ${formatClock(closesAt)}`, variant: 'warn' };
}

function tournamentBadge(
    state: NavSignals['tournament'] | undefined,
): NavBadge | undefined {
    if (state === 'ready') {
        return { text: 'Match bereit', variant: 'live' };
    }

    return state === 'checkin'
        ? { text: 'Check-in offen', variant: 'warn' }
        : undefined;
}

function mutedBadge(text: string | null): NavBadge | undefined {
    return text ? { text, variant: 'muted' } : undefined;
}

function withBadge(item: NavItem, badge: NavBadge | undefined): NavItem {
    return badge ? { ...item, badge } : item;
}

// First group: "Jetzt" and the live signals. Without a current event it has
// no label and holds only Jetzt and Server.
const nowItems = computed<NavItem[]>(() => {
    const ev = currentEvent.value;
    const sig = signals.value;
    const items: NavItem[] = [{ title: 'Jetzt', href: home(), icon: Activity }];

    if (has('game_servers')) {
        const online = sig?.serversOnline ?? 0;

        items.push(
            withBadge(
                { title: 'Server', href: serversIndex(), icon: Server },
                online > 0
                    ? { text: `${online} online`, variant: 'ok' }
                    : undefined,
            ),
        );
    }

    if (!ev) {
        return items;
    }

    items.push(
        withBadge(
            {
                title: 'Turniere',
                href: tournamentsIndex(ev.slug),
                icon: Swords,
            },
            tournamentBadge(sig?.tournament),
        ),
    );

    if (has('catering')) {
        items.push(
            withBadge(
                {
                    title: 'Essen',
                    href: cateringShow(ev.slug),
                    icon: Pizza,
                },
                foodBadge(sig?.foodClosesAt),
            ),
        );
    }

    if (has('schedule')) {
        items.push({
            title: 'Zeitplan',
            href: scheduleIndex(ev.slug),
            icon: CalendarClock,
        });
    }

    items.push(
        withBadge(
            { title: 'Sitzplan', href: eventsSeating(ev.slug), icon: Armchair },
            mutedBadge(sig?.seat ?? null),
        ),
    );

    if (has('presence')) {
        const present = sig?.present;

        items.push(
            withBadge(
                { title: 'Leute', href: presenceShow(ev.slug), icon: Radio },
                mutedBadge(
                    typeof present === 'number' && present > 0
                        ? `${present} da`
                        : null,
                ),
            ),
        );
    }

    // Hidden once the backend reports an own registration; without that
    // signal only the availability decides.
    if (ev.availability === 'open' && sig?.registered !== true) {
        items.push({
            title: 'Anmeldung',
            href: eventsRegister(ev.slug),
            icon: Ticket,
        });
    }

    return items;
});

const moreItems = computed<NavItem[]>(() => {
    const ev = currentEvent.value;
    const items: NavItem[] = [];

    if (ev) {
        if (has('voting')) {
            items.push({
                title: 'Abstimmungen',
                href: pollsIndex(ev.slug),
                icon: Vote,
                prefix: true,
            });
        }

        if (has('lfg')) {
            items.push({
                title: 'Mitspieler finden',
                href: lfgIndex(ev.slug),
                icon: HandHelping,
            });
        }

        if (has('jukebox')) {
            items.push({
                title: 'Jukebox',
                href: jukeboxIndex(ev.slug),
                icon: Music,
            });
        }

        if (has('gallery')) {
            items.push({
                title: 'Galerie',
                href: galleryIndex(ev.slug),
                icon: Image,
            });
        }

        if (has('files')) {
            items.push({
                title: 'Dateien',
                href: filesIndex(ev.slug),
                icon: FolderOpen,
            });
        }

        items.push({
            title: 'LAN-Info & Anreise',
            href: eventsShow(ev.slug),
            icon: PartyPopper,
        });
    }

    if (has('game_recipes')) {
        items.push({ title: 'Spiele', href: '/games', icon: Gamepad2 });
        // The launcher page is behind login like every participant page; the
        // entry stays gated because the route belongs to GameRecipes.
        items.push({ title: 'Launcher', href: '/launcher', icon: Download });
    }

    if (has('game_servers')) {
        items.push({
            title: 'Alle Server',
            href: serversBrowser.url(),
            icon: Radar,
        });
    }

    items.push({
        title: 'Teams',
        href: teamsIndex(),
        icon: Users,
        prefix: true,
    });

    if (has('friends')) {
        items.push({ title: 'Freunde', href: friendsIndex(), icon: UserPlus });
    }

    if (has('stats')) {
        items.push({
            title: 'Bestenliste',
            href: statsLeaderboard(),
            icon: Trophy,
        });
    }

    items.push({
        title: 'Alle Events',
        href: eventsIndex(),
        icon: CalendarDays,
    });

    return items;
});

const voiceItems = computed<NavItem[]>(() => [
    ...(has('web_voice')
        ? [{ title: 'Sprachchat', href: webVoiceIndex(), icon: Headphones }]
        : []),
    ...(has('voice')
        ? [{ title: 'Voice einrichten', href: voiceSetup(), icon: Mic }]
        : []),
    // The shared prop is null for guests and when the WebVoice module is not routable.
    ...(has('web_voice') && desktopClient.value
        ? [
              {
                  title: 'Desktop-App',
                  href: webVoiceClient(),
                  icon: MonitorDown,
                  ...(desktopClient.value.available
                      ? { indicator: 'Für dein System verfügbar' }
                      : {}),
              },
          ]
        : []),
]);

/** Same label as the Filament navigation item (shell parity, App\\Support\\Brand\\ShellParity). */
const ASSISTANT_LABEL = 'Assistent';

const orgaItems = computed<NavItem[]>(() => {
    const ev = currentEvent.value;
    // The assistant is global (no event needed) and orga-only, not helper.
    const assistant: NavItem[] =
        has('assistant') && page.props.auth?.user?.can_access_admin === true
            ? [
                  {
                      title: ASSISTANT_LABEL,
                      href: assistantIndex(),
                      icon: Sparkles,
                      prefix: true,
                  },
              ]
            : [];

    if (!ev || !isStaff.value) {
        return assistant;
    }

    return [
        { title: 'Check-in', href: orgaCheckin(ev.slug), icon: UserCheck },
        ...(has('infoscreen')
            ? [
                  {
                      title: 'Infoscreen-Steuerung',
                      href: screenControl(ev.slug),
                      icon: MonitorPlay,
                  },
              ]
            : []),
        ...assistant,
    ];
});

const nowGroupLabel = computed(() => currentEvent.value?.name ?? '');
const eventIsLive = computed(() => currentEvent.value?.status === 'live');
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <nav aria-label="Hauptnavigation" class="contents">
                <NavMain
                    :items="nowItems"
                    :label="nowGroupLabel"
                    :live="eventIsLive"
                />
                <NavCollapsibleGroup
                    label="Mehr"
                    :icon="Ellipsis"
                    :items="moreItems"
                    storage-key="more"
                />
                <NavCollapsibleGroup
                    label="Voice"
                    :icon="Headphones"
                    :items="voiceItems"
                    storage-key="voice"
                />
                <NavCollapsibleGroup
                    label="Orga"
                    :icon="ShieldCheck"
                    :items="orgaItems"
                    storage-key="orga"
                />
            </nav>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
