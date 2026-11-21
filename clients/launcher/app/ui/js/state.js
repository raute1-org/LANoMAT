// The launcher's whole live state and the one screen switch. Screens are mutually
// exclusive; the Rust side owns auth, plans and installs, this store only mirrors them.
import { announce, focusHeading } from './util.js';

const SCREENS = ['boot', 'connect', 'chat', 'library', 'install', 'ready'];

export const state = {
    stage: 'boot',
    status: null, // StatusView from get_status
    url: '',
    games: [],
    servers: null, // ServerList from list_servers
    install: null, // {slug, title, version} of the current install
    ready: null, // {slug, title, version, targetDir}
    arcade: false, // EnvironmentView.arcade: fullscreen cabinet, QR-first
    kiosk: false, // EnvironmentView.kiosk: the fullscreen is locked
    gamepad: false, // Gamepad API: a controller is connected right now
};

export function showScreen(stage) {
    if (!SCREENS.includes(stage)) {
        throw new Error(`unknown screen: ${stage}`);
    }

    state.stage = stage;

    for (const name of SCREENS) {
        const screen = document.getElementById(`screen-${name}`);

        if (screen) {
            screen.hidden = name !== stage;
        }
    }

    const main = document.getElementById('main');

    if (main) {
        main.scrollTop = 0;
    }

    const heading = document.querySelector(`#screen-${stage} h1`);

    if (heading) {
        focusHeading(heading);
    }
}

export function announceScreen(title) {
    announce(title);
}
