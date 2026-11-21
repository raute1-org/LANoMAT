<script setup lang="ts">
import { ChevronDown, ExternalLink, Play } from '@lucide/vue';
import { computed } from 'vue';
import CopyText from '@/components/CopyText.vue';
import LauncherLink from '@/components/LauncherLink.vue';
import JoinOptions from '@/components/servers/JoinOptions.vue';
import { Button } from '@/components/ui/button';
import type { JoinOptionDto } from '@/types';

/**
 * The join block of one server row on the game page. It renders exactly what
 * the server row proves, in this order: a launcher one-click, the game's own
 * join options, a link on the scheme allowlist, or the address as copyable
 * mono text — never a guessed `steam://` URL for a non-Steam game.
 *
 * With a launcher link the one-click stays the row's primary action; a PC
 * that already has the game installed (Steam, a manual install) keeps every
 * direct way behind a quiet "Anders verbinden" disclosure, so nobody is
 * forced through the launcher's own install.
 */
const props = defineProps<{
    server: {
        address: string;
        port: number | null;
        connectString: string;
        connectIsLink: boolean;
        joinOptions?: JoinOptionDto[];
        /** Optional until the server projection carries the install block. */
        install?: { launcherJoinUrl: string | null } | null;
    };
}>();

/**
 * `lanomat://join/<slug>` is the launcher: it installs the game when needed
 * and starts it on that exact server. Anything else is not followed.
 */
const launcherJoinUrl = computed<string | null>(() => {
    const url = props.server.install?.launcherJoinUrl ?? null;

    return url !== null && url.startsWith('lanomat://join/') ? url : null;
});

/**
 * The scheme allowlist of `useJoinOption.ts`: a connect string only becomes a
 * link on these schemes. A plain `host:port` stays copy-only instead of
 * turning into a dead or invented URL.
 */
const JOIN_LINK_SCHEMES = /^(steam|ut2004|https?):\/\//;

const connectLink = computed<string | null>(() =>
    props.server.connectIsLink &&
    JOIN_LINK_SCHEMES.test(props.server.connectString)
        ? props.server.connectString
        : null,
);

/** `host:port` — the form the game expects and the join block copies. */
const address = computed<string>(() => {
    if (props.server.address === '') {
        return '';
    }

    return props.server.port !== null && props.server.port > 0
        ? `${props.server.address}:${props.server.port}`
        : props.server.address;
});

const hasJoinOptions = computed(
    () =>
        address.value !== '' &&
        (props.server.joinOptions ?? []).some((option) =>
            JOIN_LINK_SCHEMES.test(option.url),
        ),
);

/** What "Adresse kopieren" copies: the exact string the game expects. */
const copyValue = computed<string>(() =>
    props.server.connectString !== ''
        ? props.server.connectString
        : address.value,
);

/** What the disclosure can offer beside the launcher: options, link, address. */
const hasAlternatives = computed(
    () =>
        hasJoinOptions.value ||
        connectLink.value !== null ||
        address.value !== '',
);

/**
 * `gameservers.join_ui`, inline: the game page has no label prop, and the join
 * block is the same component the server browser uses. No handler help here —
 * its copy names one game and this page serves every game.
 */
const labels: Record<string, string> = {
    join: 'Beitreten',
    choose: 'Beitritts-Weg',
    copy_address: 'Adresse kopieren',
    copied: 'Kopiert!',
    copy_failed: 'Kopieren nicht möglich',
    browser_prompt:
        'Dein Browser fragt eventuell, ob er die Anwendung öffnen darf. Das ist normal.',
    untested:
        'Noch nicht an jeder Installation getestet. Klappt es nicht, kopiere die Adresse und gib sie im Spiel ein.',
    setup_link: 'Hilfe',
};
</script>

<template>
    <div class="space-y-1">
        <!-- Launcher: one click installs if needed and starts on this server.
             The direct ways stay reachable for a PC that already has the game. -->
        <template v-if="launcherJoinUrl">
            <div class="flex flex-wrap items-center gap-2">
                <LauncherLink
                    :href="launcherJoinUrl"
                    size="sm"
                    :manual-hint="
                        hasAlternatives
                            ? 'Unter „Anders verbinden“ steht die Serveradresse zum Kopieren.'
                            : undefined
                    "
                >
                    <Play class="size-4" aria-hidden="true" />
                    Beitreten
                </LauncherLink>
            </div>

            <!-- Holds for every game the launcher can install, not just the first. -->
            <p class="text-xs text-muted-foreground">
                Der Launcher installiert das Spiel bei Bedarf und startet direkt
                auf diesem Server.
            </p>

            <details v-if="hasAlternatives" class="group text-xs">
                <summary
                    class="flex min-h-8 cursor-pointer list-none items-center gap-1 rounded-sm font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden"
                >
                    Anders verbinden
                    <ChevronDown
                        class="size-3.5 shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                </summary>

                <div class="mt-2 space-y-2">
                    <!-- Nothing amber in here: the launcher stays the primary. -->
                    <JoinOptions
                        v-if="hasJoinOptions"
                        :options="server.joinOptions ?? []"
                        :address="address"
                        :labels="labels"
                        :primary="false"
                    />

                    <Button
                        v-else-if="connectLink"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <a :href="connectLink" target="_blank" rel="noreferrer">
                            <ExternalLink class="size-4" aria-hidden="true" />
                            Beitreten
                        </a>
                    </Button>

                    <template v-if="address !== ''">
                        <p
                            class="font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ address }}
                        </p>
                        <CopyText
                            :text="address"
                            label="Adresse kopieren"
                            copied-label="Kopiert!"
                            failed-label="Kopieren nicht möglich"
                        />
                    </template>
                </div>
            </details>
        </template>

        <!-- No launcher link: exactly what the row itself proves. -->
        <div v-else class="flex flex-wrap items-center gap-2">
            <JoinOptions
                v-if="hasJoinOptions"
                :options="server.joinOptions ?? []"
                :address="address"
                :labels="labels"
            />

            <Button v-else-if="connectLink" as-child size="sm">
                <a :href="connectLink" target="_blank" rel="noreferrer">
                    <ExternalLink class="size-4" aria-hidden="true" />
                    Beitreten
                </a>
            </Button>

            <CopyText
                v-else-if="copyValue !== ''"
                :text="copyValue"
                label="Adresse kopieren"
                copied-label="Kopiert!"
                failed-label="Kopieren nicht möglich"
            />
        </div>
    </div>
</template>
