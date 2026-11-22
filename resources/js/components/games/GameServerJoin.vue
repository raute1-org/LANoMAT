<script setup lang="ts">
import { ChevronDown, ExternalLink, Play } from '@lucide/vue';
import { computed } from 'vue';
import CopyText from '@/components/CopyText.vue';
import LauncherLink from '@/components/LauncherLink.vue';
import JoinOptions from '@/components/servers/JoinOptions.vue';
import { Button } from '@/components/ui/button';
import { isAllowedJoinUrl } from '@/composables/useJoinOption';
import { joinLinkNamesServer } from '@/lib/launcherJoin';
import type { JoinOptionDto, ServerInstallDto } from '@/types';

/**
 * The join block of one server row on the game page. It renders exactly what
 * the server row proves, in this order: the launcher handoff
 * ("Im Launcher beitreten" — it installs the game when needed and starts it on
 * that server), then the game's own join as the alternative: join options, a
 * link on the scheme allowlist, or the address as copyable mono text — never a
 * guessed `steam://` URL for a non-Steam game.
 *
 * A PC that already has the game installed (Steam, a manual install) keeps
 * every direct way behind a quiet "Anders verbinden" disclosure, so nobody is
 * forced through the launcher's own install. The launcher link is outline: on
 * the game page only "Installieren" carries the one amber accent.
 */
const props = defineProps<{
    server: {
        address: string;
        port: number | null;
        connectString: string;
        connectIsLink: boolean;
        joinOptions?: JoinOptionDto[];
        /** The matching recipe and its launcher handoff; absent when there is none. */
        install?: ServerInstallDto | null;
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

/** False for a link that installs and starts the game but cannot name this server. */
const joinsServer = computed(() => joinLinkNamesServer(launcherJoinUrl.value));

/**
 * `install.lanReady` false: the game's files are not on the LAN right now, so
 * the launcher starts an existing install but cannot install fresh. The same
 * words as `install_not_on_lan` in `lang/de/gameservers.php`.
 */
const missingOnLan = computed(() => props.server.install?.lanReady === false);

/**
 * A connect string only becomes a link on the same scheme allowlist
 * `JoinOptions` uses; a plain `host:port` stays copy-only instead of turning
 * into a dead or invented URL.
 */
const connectLink = computed<string | null>(() =>
    props.server.connectIsLink && isAllowedJoinUrl(props.server.connectString)
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
            isAllowedJoinUrl(option.url),
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
 * `gameservers.join_ui` from `lang/de/gameservers.php`, inline: the game page
 * has no label prop, and these words belong to the same controls the server
 * browser renders — keep them in step with that file. No handler help here —
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
                <!-- The same words as `install_launcher` in
                     lang/de/gameservers.php. -->
                <LauncherLink
                    :href="launcherJoinUrl"
                    size="sm"
                    variant="outline"
                    :manual-hint="
                        hasAlternatives
                            ? 'Unter „Anders verbinden“ steht die Serveradresse zum Kopieren.'
                            : undefined
                    "
                >
                    <Play class="size-4" aria-hidden="true" />
                    {{
                        joinsServer
                            ? 'Im Launcher beitreten'
                            : 'Im Launcher starten'
                    }}
                </LauncherLink>
            </div>

            <!-- Holds for every game the launcher can install, not just the first. -->
            <p v-if="joinsServer" class="text-xs text-muted-foreground">
                Der Launcher installiert das Spiel bei Bedarf und startet direkt
                auf diesem Server.
            </p>
            <p v-else class="text-xs text-muted-foreground">
                Der Launcher installiert das Spiel bei Bedarf und startet es.
                Den Server wählst du dann im Spiel; seine Adresse steht oben.
            </p>

            <!-- The archive is not on the LAN: install-fresh is blocked, the
                 join above still starts an existing install. -->
            <p
                v-if="missingOnLan"
                class="text-xs text-muted-foreground"
                data-test="install-not-on-lan"
            >
                Für dieses Spiel liegen gerade nicht alle Dateien auf dem
                LAN-Server; eine Neuinstallation kann deshalb scheitern. Wer das
                Spiel schon hat, kann trotzdem beitreten — die Orga hilft, wenn
                etwas fehlt.
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
