// UI drive: walks through the participant screens like a person (real DOM clicks, typing and
// key events inside the app's webview), takes screenshots through the hook script, and reports
// PASS/FAIL lines. Evaluated by the app built with `--features selftest` (see smoke-ui.sh).
// Parameters arrive as window.__SELFTEST: { mode, serverUrl, uploadPath, downloadDir }.
(async () => {
    const P = window.__SELFTEST || {};
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    while (!window.__TAURI__) {
        await sleep(100);
    }

    const inv = (name, args) => window.__TAURI__.core.invoke(name, args);
    const { S } = await import('./js/strings.js');
    // The stand-in's latest client build (CLIENT_SERVER in standin_demo.py).
    const UPDATE_LATEST = 'm13-13-g1b2c3d4';
    let failures = 0;

    const report = (ok, name, detail) => {
        if (!ok) {
            failures++;
        }

        return inv('selftest_report', {
            line: `${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' - ' + detail : ''}`,
        });
    };
    const hook = (args) => inv('selftest_hook', { args });
    const shot = async (name, { toast = false } = {}) => {
        if (!toast && document.getElementById('toast')) {
            document.getElementById('toast').hidden = true;
        }

        await sleep(350); // let the last paint finish
        await hook(`shot ${name}`);
    };
    const q = (sel, root = document) => root.querySelector(sel);
    const qa = (sel, root = document) => [...root.querySelectorAll(sel)];
    const visible = (e) =>
        Boolean(e) && !e.closest('[hidden]') && e.getClientRects().length > 0;
    const byText = (sel, text, root = document) =>
        qa(sel, root).find(
            (e) => visible(e) && e.textContent.trim().includes(text),
        );
    const waitFor = async (pred, ms = 8000, what = 'condition') => {
        const end = Date.now() + ms;

        while (Date.now() < end) {
            let v = null;

            try {
                v = pred();
            } catch {
                v = null;
            }

            if (v) {
                return v;
            }

            await sleep(60);
        }

        throw new Error('timed out waiting for ' + what);
    };
    const click = async (target, what) => {
        const e =
            typeof target === 'function'
                ? await waitFor(target, 8000, what)
                : target;

        if (!e) {
            throw new Error('nothing to click: ' + what);
        }

        e.click();
        await sleep(120);

        return e;
    };
    // The Beitreten button on one place of a person (a chip text: provider name or "Spiel").
    const placeBtn = (row, chipText) =>
        qa('.where', row)
            .find(
                (w) =>
                    visible(w) &&
                    q('.chip', w)?.textContent.trim() === chipText,
            )
            ?.querySelector('button.where-join');
    const clickText = (sel, text, root) =>
        click(() => byText(sel, text, root), `${sel} "${text}"`);
    const typeInto = async (input, text) => {
        input.focus();
        input.value = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await sleep(60);
    };
    const pressEnter = (input) => {
        input.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Enter',
                code: 'Enter',
                bubbles: true,
                cancelable: true,
            }),
        );
    };
    const tab = async (id) => {
        await click(() => q(`#tab-${id}`), `tab ${id}`);
        await waitFor(() => visible(q(`#screen-${id}`)), 4000, `screen ${id}`);
        await sleep(500);
    };
    // Gerät und Konto > "Nach Updates suchen", then back to a neutral screen state.
    const checkForUpdates = async () => {
        await tab('mehr');

        // Mehr remembers its open page: leave a sub-page for the hub first.
        if (byText('#screen-mehr button', S.back)) {
            await clickText('#screen-mehr button', S.back);
        }

        await clickText('.list-row', S.more.account);
        await clickText('#screen-mehr button', S.update.check);
        await waitFor(
            () => !byText('#screen-mehr button', S.update.checking),
            6000,
            'the update check to finish',
        );
        await sleep(200);
    };
    const step = async (name, fn) => {
        try {
            const detail = await fn();
            await report(true, name, detail);
        } catch (e) {
            await report(false, name, e && e.message ? e.message : String(e));

            try {
                await hook(
                    `shot FAIL-${name.replace(/[^a-z0-9]+/gi, '-').slice(0, 40)}`,
                );
            } catch {
                /* ignore */
            }
        }
    };
    const text = (sel) => (q(sel) ? q(sel).textContent.trim() : '');
    const toastText = () =>
        visible(q('#toast')) ? q('#toast').textContent : '';
    const assert = (c, m) => {
        if (!c) {
            throw new Error(m);
        }
    };

    // ---------------------------------------------------------------- start: connect, guide
    const fresh = P.mode === 'full';

    if (fresh) {
        await step(
            'connect screen shows and a bad address gives a plain message',
            async () => {
                await waitFor(
                    () => visible(q('#screen-connect')),
                    10000,
                    'connect screen',
                );
                await shot('01-connect');
                await click(q('#connect-submit'));
                await waitFor(
                    () => visible(q('#screen-connect .error')),
                    3000,
                    'missing address message',
                );
                assert(
                    text('#screen-connect .error').includes(
                        S.connect.missingTitle,
                    ),
                    'plain text',
                );
            },
        );
        await step(
            'pairing through the real UI (code, wait, approval)',
            async () => {
                await typeInto(q('#server-url'), P.serverUrl);
                await click(q('#connect-submit'));
                await waitFor(
                    () =>
                        visible(q('.code')) &&
                        q('.code').textContent.length > 3,
                    6000,
                    'user code',
                );
                await shot('02-connect-wait');
                await waitFor(
                    () => visible(q('#screen-onboarding')),
                    20000,
                    'first-run guide after the approval',
                );
            },
        );
        await step(
            'first-run guide: headset, moving input meter, test tone, key',
            async () => {
                await waitFor(() => q('#onb-input'), 5000, 'device select');
                await shot('03-guide-headset');
                await click(q('#onb-next'));
                await waitFor(() => q('.meter-fill'), 5000, 'meter');
                const widths = new Set();
                const end = Date.now() + 6000;

                while (Date.now() < end && widths.size < 3) {
                    widths.add(q('.meter-fill').style.width);
                    await sleep(80);
                }

                await shot('04-guide-meter');
                assert(
                    widths.size >= 3,
                    `the meter did not move (${[...widths].join(', ')})`,
                );
                await waitFor(
                    () =>
                        text('#screen-onboarding [role=status]').includes(
                            S.onboarding.heard,
                        ),
                    6000,
                    'heard message',
                );
                await click(q('#onb-next'));
                await clickText(
                    'button',
                    S.onboarding.playTone,
                    q('#screen-onboarding'),
                );
                await sleep(500);
                await shot('05-guide-tone');
                await waitFor(
                    () =>
                        text('#screen-onboarding [role=status]').includes(
                            S.onboarding.heardQuestion.slice(0, 20),
                        ),
                    4000,
                    'tone finished',
                );
                await click(q('#onb-next'));
                await waitFor(() => q('#mode-ptt'), 3000, 'key step');
                await shot('06-guide-key');
                await click(q('#onb-next'));
                await waitFor(
                    () => visible(q('#screen-jetzt')),
                    6000,
                    'Jetzt after the guide',
                );
            },
        );
    } else {
        await waitFor(() => visible(q('#screen-jetzt')), 15000, 'Jetzt');
    }

    // ---------------------------------------------------------------- narrow window and dark scheme
    if (P.mode === 'narrow' || P.mode === 'dark') {
        await waitFor(() => visible(q('#screen-jetzt')), 15000, 'Jetzt');
        await step(
            `the screens in a ${P.mode === 'narrow' ? 'narrow window' : 'dark colour scheme'}`,
            async () => {
                if (P.mode === 'narrow') {
                    await hook('resize 430 760');
                    await sleep(900);
                }

                await waitFor(
                    () => qa('#screen-jetzt article.card').length >= 3,
                    10000,
                    'cards',
                );
                await shot(`${P.mode}-jetzt`);

                if (P.mode === 'dark') {
                    assert(
                        window.matchMedia('(prefers-color-scheme: dark)')
                            .matches,
                        'the webview reports the dark scheme',
                    );
                }

                await tab('sprache');
                await click(
                    () => q('#screen-sprache .room-row .btn.ghost'),
                    'chat of a room',
                );
                await sleep(900);
                await shot(`${P.mode}-sprache`);
                await clickText('.seg-btn', S.people.people);
                await waitFor(
                    () => qa('#screen-sprache li.pr').length >= 7,
                    6000,
                    'people rows',
                );
                await shot(`${P.mode}-personen`);
                await clickText('.seg-btn', S.people.rooms);
                await tab('spielen');
                await waitFor(
                    () => qa('#screen-spielen article.match').length >= 3,
                    6000,
                    'matches',
                );
                await shot(`${P.mode}-spielen`);
                await tab('essen');
                await waitFor(
                    () => qa('#screen-essen .menu-line').length >= 4,
                    6000,
                    'menu',
                );
                await shot(`${P.mode}-essen`);
                await tab('mehr');
                await clickText('.list-row', S.more.notifications);
                await sleep(900);
                await shot(`${P.mode}-notifications`);
                await clickText('button', S.back);
                await clickText('.list-row', S.more.friends);
                await waitFor(
                    () => qa('#screen-mehr li.pr').length >= 9,
                    6000,
                    'friend rows',
                );
                await shot(`${P.mode}-friends`);
                await clickText('button', S.back);
                await clickText('.list-row', S.more.profile);
                await waitFor(() => q('#pf-name'), 6000, 'profile form');
                await sleep(800);
                await shot(`${P.mode}-profile`);
                await clickText('button', S.back);

                // The update notice in this window and colour scheme.
                await hook('hostget __client/update-available');
                await checkForUpdates();
                await tab('jetzt');
                await waitFor(
                    () => visible(q('#update-banner')),
                    6000,
                    'update notice',
                );
                await shot(`${P.mode}-update`);

                if (P.mode === 'narrow') {
                    // Scalable text: 125 % on the narrow window.
                    document.documentElement.style.fontSize = '125%';
                    await tab('jetzt');
                    await sleep(500);
                    await shot('narrow-text-125');
                    document.documentElement.style.fontSize = '';
                }
            },
        );
        await report(true, 'SUMMARY failures=' + failures, '');
        await inv('selftest_done', { failures });

        return;
    }

    // ---------------------------------------------------------------- every screen, one state
    const tourStates = async (prefix) => {
        await tab('jetzt');
        await waitFor(
            () => !q('#screen-jetzt [aria-busy=true]'),
            8000,
            'Jetzt settled',
        );
        await shot(`${prefix}-jetzt`);
        await tab('sprache');
        await sleep(600);
        await shot(`${prefix}-sprache`);
        await clickText('.seg-btn', S.people.people);
        await sleep(1000);
        await shot(`${prefix}-sprache-personen`);
        await clickText('.seg-btn', S.people.rooms);
        await tab('spielen');
        await clickText('.seg-btn', S.play.sub.matches);
        await waitFor(
            () => !q('#screen-spielen [aria-busy=true]'),
            8000,
            'matches settled',
        );
        await shot(`${prefix}-spielen-matches`);
        await clickText('.seg-btn', S.play.sub.tournaments);
        await sleep(800);
        await shot(`${prefix}-spielen-tournaments`);
        await clickText('.seg-btn', S.play.sub.servers);
        await sleep(800);
        await shot(`${prefix}-spielen-servers`);
        await tab('essen');
        await clickText('.seg-btn', S.food.sub.menu);
        await sleep(800);
        await shot(`${prefix}-essen-menu`);
        await clickText('.seg-btn', S.food.sub.orders);
        await sleep(500);
        await shot(`${prefix}-essen-orders`);
        await tab('mehr');
        await clickText('.list-row', S.more.polls);
        await sleep(800);
        await shot(`${prefix}-polls`);
        await clickText('button', S.back);
        await clickText('.list-row', S.more.files);
        await sleep(800);
        await shot(`${prefix}-files`);
        await clickText('button', S.back);
        await clickText('.list-row', S.more.notifications);
        await sleep(800);
        await shot(`${prefix}-notifications`);
        await clickText('button', S.back);
        await clickText('.list-row', S.more.friends);
        await sleep(1000);
        await shot(`${prefix}-friends`);
        await clickText('button', S.back);
        await clickText('.list-row', S.more.profile);
        await sleep(1200);
        await shot(`${prefix}-profile`);
        await clickText('button', S.back);
    };

    if (P.mode === 'empty' || P.mode === 'errors') {
        await waitFor(() => visible(q('#screen-jetzt')), 15000, 'Jetzt');
        await step(
            `every screen in its ${P.mode === 'empty' ? 'empty' : 'error'} state`,
            async () => {
                await tourStates(P.mode === 'empty' ? '70-empty' : '80-error');
            },
        );

        if (P.mode === 'errors') {
            await step(
                'the error cards give a plain message and the retry recovers',
                async () => {
                    await tab('jetzt');
                    const btn = byText('#screen-jetzt .error button', S.retry);
                    assert(btn, 'retry button on the error card');
                    assert(
                        !text('#screen-jetzt .error').includes('{'),
                        'never raw JSON',
                    );
                    await hook('scenario normal');
                    await click(btn);
                    await waitFor(
                        () => qa('#screen-jetzt article.card').length >= 3,
                        8000,
                        'cards after the retry',
                    );
                    await shot('81-error-recovered');
                },
            );
        }

        await report(true, 'SUMMARY failures=' + failures, '');
        await inv('selftest_done', { failures });

        return;
    }

    // ---------------------------------------------------------------- Jetzt
    await step('Jetzt: prioritised cards and the voice card', async () => {
        await waitFor(
            () => qa('#screen-jetzt article.card').length >= 4,
            10000,
            'cards',
        );
        await sleep(500);
        await shot('10-jetzt');
        assert(
            visible(byText('#screen-jetzt .card.voice h2', S.now.voiceTitle)),
            'voice card always shown',
        );
        const more = byText('#screen-jetzt button', 'Mehr anzeigen');
        assert(more, 'more than five cards fold under Mehr anzeigen');
        assert(
            qa('#screen-jetzt article.card').length <= 6,
            'at most five plus the voice card',
        );

        return `${qa('#screen-jetzt article.card').length} cards`;
    });

    if (P.mode === 'mods') {
        await step('modules off: no Essen tab, no Dateien entry', async () => {
            assert(!q('#tab-essen'), 'Essen tab must be hidden');
            await tab('mehr');
            await shot('90-modules-off-mehr');
            assert(
                !byText('#screen-mehr .list-row', S.more.files),
                'Dateien entry must be hidden',
            );
            assert(
                byText('#screen-mehr .list-row', S.more.polls),
                'Abstimmungen stays',
            );
            assert(
                !byText('#screen-mehr .list-row', S.more.friends),
                'Freunde is hidden when the server module is off',
            );
            assert(
                !byText('#screen-mehr .list-row', S.more.profile),
                'Profil is hidden when the server module is off',
            );
            assert(
                !visible(q('.team-strip')),
                'no team strip without the Presence module',
            );
            await tab('sprache');
            assert(
                !byText('#screen-sprache .seg-btn', S.people.people),
                'no Personen view without the Presence module',
            );
            await tab('mehr');

            return (
                'tab bar: ' +
                qa('.tab')
                    .map((t) => t.textContent.trim())
                    .join(', ')
            );
        });
        await step('moderator sees delete in the chat', async () => {
            await tab('sprache');
            await click(
                () => q('#screen-sprache .room-row .btn.ghost'),
                'chat of the first room',
            );
            await waitFor(
                () => q('.chat-input') && !q('.chat-input').disabled,
                5000,
                'composer',
            );
            await typeInto(q('.chat-input'), 'Hallo zusammen');
            pressEnter(q('.chat-input'));
            await waitFor(() => qa('.msg').length >= 1, 5000, 'message');
            await shot('91-chat-moderator');
            assert(
                byText('.msg .btn', S.chat.remove),
                'delete button for orga',
            );
        });
        await report(true, 'SUMMARY failures=' + failures, '');
        await inv('selftest_done', { failures });

        return;
    }

    await step(
        'Jetzt: the team strip shows a teammate in voice and game and offers a Beitreten button per place',
        async () => {
            const strip = await waitFor(
                () => visible(q('.team-strip')) && q('.team-strip'),
                8000,
                'team strip',
            );
            assert(
                !strip.closest('article.card') && byText('.name', 'Ada', strip),
                'a plain section with Ada',
            );
            await shot('17-jetzt-team-strip');
            assert(
                placeBtn(strip, S.people.game) &&
                    placeBtn(strip, S.people.providers.webvoice),
                'voice and game each have their own button',
            );
            await clickText('button', S.people.stripAll, strip);
            await waitFor(
                () => visible(q('#screen-sprache')),
                4000,
                'Sprache opened',
            );
            await waitFor(
                () =>
                    byText('#screen-sprache .seg-btn', S.people.team) &&
                    byText(
                        '#screen-sprache .seg-btn',
                        S.people.team,
                    ).getAttribute('aria-pressed') === 'true',
                4000,
                'the team filter is on',
            );
            await clickText('.seg-btn', S.people.rooms);
            await tab('jetzt');
        },
    );

    await step('vote on a card in Jetzt with one tap', async () => {
        const card = await waitFor(
            () => qa('#screen-jetzt .card.vote').find(visible),
            5000,
            'vote card',
        );
        card.scrollIntoView({ block: 'center' });
        await shot('11-jetzt-vote');
        await clickText('button', 'Mirage', card);
        await waitFor(
            () => byText('#screen-jetzt .card.done', S.now.voted('Mirage')),
            6000,
            'done card',
        );
        document.getElementById('main').scrollTop = 0;
        await shot('12-jetzt-voted');
    });

    await step('order a food item from the order card', async () => {
        const card = await waitFor(
            () => qa('#screen-jetzt .card.order').find(visible),
            5000,
            'order card',
        );
        await clickText('button', 'Pizza Margherita', card);
        await waitFor(
            () =>
                byText(
                    '#screen-jetzt .card.done',
                    S.now.ordered('Pizza Margherita'),
                ),
            6000,
            'order done card',
        );
    });

    await step('check in from the card', async () => {
        const card = await waitFor(
            () =>
                qa('#screen-jetzt .card.match').find(
                    (c) => visible(c) && byText('button', S.matches.checkIn, c),
                ),
            5000,
            'check-in card',
        );
        await clickText('button', S.matches.checkIn, card);
        await waitFor(
            () => byText('#screen-jetzt .card.done', S.matches.checkedIn),
            6000,
            'check-in done',
        );
    });

    await step(
        'match card: server join falls back to copying, address copy works',
        async () => {
            const card = await waitFor(
                () =>
                    qa('#screen-jetzt .card.match').find(
                        (c) =>
                            visible(c) && byText('button', S.servers.join, c),
                    ),
                6000,
                'match card with server',
            );
            card.scrollIntoView({ block: 'center' });
            await shot('13-jetzt-match');
            await clickText('button', S.servers.join, card);
            await waitFor(
                () => toastText().length > 0,
                4000,
                'toast after Server beitreten',
            );
            const t1 = toastText();
            assert(
                t1.includes('203.0.113.5:27015') ||
                    t1.includes(S.servers.opening('').slice(0, 5)),
                'join feedback: ' + t1,
            );
            await sleep(200);
            await clickText('button', S.servers.copyAddress, card);
            await waitFor(
                () => toastText().includes(S.servers.addressCopied),
                4000,
                'address copied toast',
            );

            return t1;
        },
    );

    await step('join the match room from the card', async () => {
        const card = await waitFor(
            () =>
                qa('#screen-jetzt .card.match').find(
                    (c) =>
                        visible(c) && byText('button', S.matches.joinRoom, c),
                ),
            5000,
            'match card',
        );
        await clickText('button', S.matches.joinRoom, card);
        await waitFor(
            () => text('#screen-jetzt .card.voice h2').startsWith('Match'),
            15000,
            'voice card shows the room',
        );
        await sleep(600);
        await shot('14-jetzt-in-room');

        return text('#screen-jetzt .card.voice h2');
    });

    await step('Jetzt: help sheet opens and closes', async () => {
        await click(() => q('#screen-jetzt .help-btn'), 'help');
        await waitFor(() => q('#help[open]'), 2000, 'sheet');
        await shot('15-help');
        await click(q('#help .btn.primary'));
        assert(!q('#help[open]'), 'sheet closed');
    });

    await step(
        'keyboard: arrow keys move between the tabs, Alt+number jumps, focus is visible',
        async () => {
            q('#tab-jetzt').focus();
            await hook('key Right');
            await waitFor(
                () =>
                    document.activeElement === q('#tab-sprache') &&
                    visible(q('#screen-sprache')),
                3000,
                'Right moved to Sprache',
            );
            await hook('key alt+3');
            await waitFor(
                () => visible(q('#screen-spielen')),
                3000,
                'Alt+3 opened Spielen',
            );
            q('#tab-spielen').focus();
            await sleep(200);
            await shot('16-keyboard-focus');
            await tab('jetzt');
        },
    );

    // ---------------------------------------------------------------- Sprache + chat
    await step('Sprache: rooms, live room and chat side by side', async () => {
        await tab('sprache');
        await waitFor(
            () => q('.chat-input') && !q('.chat-input').disabled,
            8000,
            'chat composer',
        );
        await shot('20-sprache');
        assert(
            visible(q('.room-panel')) && visible(q('.chat-panel')),
            'panel and chat visible together',
        );
    });
    await step('send a chat message with Enter and see it', async () => {
        await typeInto(q('.chat-input'), 'Hallo Peer, bin gleich da');
        pressEnter(q('.chat-input'));
        await waitFor(
            () => byText('.msg', 'Hallo Peer, bin gleich da'),
            5000,
            'own message',
        );
        assert(q('.chat-input').value === '', 'input cleared');
    });
    await step(
        'a live message from another user arrives with the mention highlighted',
        async () => {
            await hook('chat 3 Peer Servus @Tester, das Match geht gleich los');
            const m = await waitFor(
                () => byText('.msg.mention', 'Servus'),
                6000,
                'live mention',
            );
            await shot('21-chat-mention');

            return m.className;
        },
    );
    await step(
        'unread mention shows as a badge on the Sprache tab',
        async () => {
            await tab('jetzt');
            await hook('chat 3 Peer @Tester bist du da?');
            await waitFor(
                () =>
                    visible(q('#badge-sprache')) &&
                    q('#badge-sprache').textContent === '1',
                6000,
                'badge 1',
            );
            await shot('22-badge-mention');
            await tab('sprache');
            await waitFor(
                () => !visible(q('#badge-sprache')),
                4000,
                'badge cleared on view',
            );
        },
    );
    await step('the rate limit is a calm hint, not an error', async () => {
        const input = q('.chat-input');

        for (let i = 0; i < 12; i++) {
            await typeInto(input, `Schnell ${i}`);
            pressEnter(input);
            await sleep(120);
        }

        await waitFor(
            () =>
                text('.chat-hint').includes(
                    S.errors.rateLimitedIn(1).slice(0, 18),
                ),
            5000,
            'calm hint',
        );
        await shot('23-chat-ratelimit');
        assert(!q('.chat-panel .error'), 'no error wall');
    });

    // ---------------------------------------------------------------- Sprache: Personen
    const personRow = (name, scope = '#screen-sprache') =>
        qa(`${scope} li.pr`).find(
            (r) =>
                visible(r) &&
                (q('.name', r)?.textContent.trim() === name ||
                    q('.name', r)?.textContent.trim().startsWith(`${name} (`)),
        );
    const people = () => qa('#screen-sprache li.pr .name');
    // With a room joined the panel is tall: bring the list to the top of the window for the shot.
    const toList = async () => {
        q('#screen-sprache .seg')?.scrollIntoView({ block: 'start' });
        await sleep(250);
    };
    await step(
        'Sprache: Personen next to the rooms, teammates first, where they are',
        async () => {
            await tab('sprache');
            await clickText('.seg-btn', S.people.people);
            await waitFor(
                () => qa('#screen-sprache li.pr').length >= 7,
                6000,
                'people rows',
            );
            const names = people().map((n) => n.textContent.trim());
            assert(
                names[0].startsWith('Tester') &&
                    names.slice(1, 3).join() === 'Ada,Fynn',
                `me first, then the teammates (${names.join(', ')})`,
            );
            const ada = personRow('Ada');
            assert(
                byText('.chip', S.people.providers.webvoice, ada) &&
                    byText('.where', 'Lobby', ada) &&
                    byText('.where', 'Counter-Strike 2', ada),
                'Ada: web voice room and game',
            );
            assert(
                byText('.chip', S.people.teammate, ada) &&
                    byText('.chip', 'Team Rot', ada),
                'team label and teammate chip',
            );
            assert(
                !visible(q('#screen-sprache .chat-col')),
                'the chat column makes room for the list',
            );
            assert(
                !byText('.chip', S.people.hidden, personRow('Tester')),
                'sharing is on: no "not shared" chip',
            );
            await toList();
            // Avatars: the picture is fetched by the app through the server and laid over the
            // monogram when the row is in view (rows far below load lazily); a person without
            // one keeps the monogram only.
            await waitFor(
                () =>
                    q('img.avatar-img', personRow('Ada')) &&
                    q('img.avatar-img', personRow('Tester')),
                8000,
                'avatar pictures in the people list',
            );
            const adaImg = q('img.avatar-img', personRow('Ada'));
            assert(
                adaImg.src.startsWith('data:image/png;base64,') &&
                    adaImg.alt === S.avatar.alt('Ada') &&
                    adaImg.naturalWidth > 0,
                'Ada: the avatar is a decoded data: image with its alt text',
            );
            assert(
                !q('img.avatar-img', personRow('Dirk')) &&
                    q('.avatar', personRow('Dirk'))?.textContent.trim() === 'D',
                'Dirk has no avatar: the monogram stays',
            );
            await shot('24-sprache-personen');
        },
    );
    await step(
        'Personen: filters Team and Freunde, search, and the empty states',
        async () => {
            await clickText('.seg-btn', S.people.team);
            await waitFor(
                () => qa('#screen-sprache li.pr').length === 3,
                5000,
                'team rows',
            );
            await toList();
            await shot('26-personen-filter-team');
            await clickText('.seg-btn', S.people.friends);
            await waitFor(
                () =>
                    personRow('Ben') &&
                    !personRow('Ada') &&
                    qa('#screen-sprache li.pr').length === 3,
                5000,
                'friend rows (me, Ben, Fynn)',
            );
            await clickText('.seg-btn', S.people.all);
            await waitFor(() => personRow('Ada'), 5000, 'all again');
            await typeInto(q('#people-search'), 'cl');
            await waitFor(
                () => personRow('Cleo') && !personRow('Ben'),
                5000,
                'search result',
            );
            await typeInto(q('#people-search'), 'zzz');
            await waitFor(
                () => byText('#screen-sprache .empty', S.people.noMatch),
                5000,
                'no match state',
            );
            await toList();
            await shot('27-personen-nomatch');
            await typeInto(q('#people-search'), '');
            await waitFor(() => personRow('Ben'), 5000, 'cleared');
        },
    );
    await step(
        'Personen: a room and server you may not see cannot be followed',
        async () => {
            const dirk = personRow('Dirk');
            const btn = placeBtn(dirk, S.people.game);
            assert(btn && btn.disabled, 'disabled button');
            assert(
                btn.title === S.people.cannotJoin,
                'the reason is on the button',
            );
            assert(
                byText('.where', 'Counter-Strike 2', dirk) &&
                    !byText('.where', 'CS2 Cup', dirk),
                'only the game name is shown',
            );
            dirk.scrollIntoView({ block: 'center' });
            await sleep(250);
            await shot('29-personen-not-joinable');
        },
    );
    await step(
        'Hinterherjoinen: Discord is only copied, Mumble opens a rewritten link, the game address copies',
        async () => {
            placeBtn(personRow('Ben'), S.people.providers.discord).click();
            await waitFor(
                () => toastText().includes(S.people.linkCopied),
                4000,
                'discord link copied',
            );
            const cleo = personRow('Cleo');
            await toList();
            await shot('25-personen-join-per-place');
            placeBtn(cleo, S.people.providers.mumble).click();
            await waitFor(
                () => toastText().includes(S.people.voiceOpened('Mumble')),
                4000,
                'mumble opened toast',
            );
            placeBtn(cleo, S.people.game).click();
            await waitFor(
                () => toastText().length > 0,
                4000,
                'game join or address copy answers',
            );
        },
    );
    await step(
        'Hinterherjoinen: a teammate in a web voice room, followed through the real join flow',
        async () => {
            const ada = personRow('Ada');
            await toList();
            await shot('25b-personen-follow-ada');
            placeBtn(ada, S.people.game).click();
            await waitFor(
                () =>
                    toastText().includes(S.servers.opening('CS2 Cup Runde 2')),
                4000,
                'steam link opened',
            );
            placeBtn(ada, S.people.providers.webvoice).click();
            await waitFor(
                () => visible(q('#screen-sprache .room-panel')),
                15000,
                'room panel',
            );
            await waitFor(
                () =>
                    text('#screen-sprache .room-panel h2') === 'Lobby' &&
                    text('#screen-sprache .room-panel [role=status]') ===
                        S.voice.phase.connected,
                15000,
                'connected to the Lobby (dev LiveKit)',
            );
            await shot('24b-personen-followed-into-room');
        },
    );
    await step(
        'Personen: my own entry says "Standort nicht geteilt" only after I opted out',
        async () => {
            await hook('hostget __me/location/off');
            await clickText('button', S.reload);
            await waitFor(
                () => byText('.chip', S.people.hidden, personRow('Tester')),
                5000,
                'hidden chip',
            );
            assert(
                byText('.small', S.people.hiddenHint, personRow('Tester')),
                'what it means',
            );
            await toList();
            await shot('28-personen-hidden-self');
            await hook('hostget __me/location/on');
            await clickText('button', S.reload);
            await waitFor(
                () => !byText('.chip', S.people.hidden, personRow('Tester')),
                5000,
                'chip gone',
            );
            await clickText('.seg-btn', S.people.rooms);
            await waitFor(
                () => visible(q('#screen-sprache .chat-col')),
                3000,
                'rooms and chat back',
            );
        },
    );

    // ---------------------------------------------------------------- Spielen
    await step(
        'Spielen: my matches, report with a tie, then a result',
        async () => {
            await tab('spielen');
            await waitFor(
                () => qa('#screen-spielen article.match').length >= 3,
                6000,
                'match cards',
            );
            await shot('30-spielen-matches');
            const card = qa('#screen-spielen article.match').find((c) =>
                byText('button', S.matches.report, c),
            );
            await clickText('button', S.matches.report, card);
            await typeInto(q('input[id^=own-]', card), '5');
            await typeInto(q('input[id^=opp-]', card), '5');
            await clickText('button', S.matches.send, card);
            await waitFor(
                () =>
                    byText('.inline-error', S.matches.noTie.slice(0, 20), card),
                3000,
                'tie message',
            );
            await typeInto(q('input[id^=own-]', card), '13');
            await typeInto(q('input[id^=opp-]', card), '7');
            await shot('31-spielen-report-form');
            await clickText('button', S.matches.send, card);
            await waitFor(
                () =>
                    byText(
                        '#screen-spielen .hint',
                        S.matches.youReported(13, 7).slice(0, 14),
                    ),
                6000,
                'pending report note',
            );
            await shot('32-spielen-reported');
            await clickText('button', S.matches.withdraw);
            await waitFor(
                () =>
                    !byText(
                        '#screen-spielen .hint',
                        S.matches.youReported(13, 7).slice(0, 14),
                    ),
                6000,
                'withdrawn',
            );
        },
    );
    await step('confirm the opponent result', async () => {
        const card = qa('#screen-spielen article.match').find((c) =>
            byText('button', S.matches.confirm, c),
        );
        await clickText('button', S.matches.confirm, card);
        await waitFor(
            () => !byText('button', S.matches.confirm),
            6000,
            'confirmed',
        );
    });
    await step('Spielen: tournaments with my enrollment state', async () => {
        await clickText('.seg-btn', S.play.sub.tournaments);
        await waitFor(
            () => qa('#screen-spielen article.tournament').length >= 3,
            5000,
            'tournament cards',
        );
        await shot('33-spielen-tournaments');
    });
    await step(
        'Spielen: servers with status, join and copy; offline has no join',
        async () => {
            await clickText('.seg-btn', S.play.sub.servers);
            await waitFor(
                () => qa('#screen-spielen article.server').length >= 3,
                6000,
                'server cards',
            );
            await shot('34-spielen-servers');
            const offline = qa('#screen-spielen article.server').find((c) =>
                c.textContent.includes('UT2004'),
            );
            assert(
                offline && !byText('button', S.servers.joinShort, offline),
                'offline server has no join button',
            );
            const val = qa('#screen-spielen article.server').find((c) =>
                c.textContent.includes('Valheim'),
            );
            await clickText('button', S.servers.copyAddress, val);
            await waitFor(
                () => toastText().includes(S.servers.addressCopied),
                3000,
                'copied toast',
            );
        },
    );

    // ---------------------------------------------------------------- Essen
    await step(
        'Essen: menu, steppers, running total, order one item',
        async () => {
            await tab('essen');
            await waitFor(
                () => qa('#screen-essen .menu-line').length >= 4,
                6000,
                'menu',
            );
            await shot('40-essen-menu');
            const lineOf = (n) =>
                qa('#screen-essen .menu-line').find((l) =>
                    l.textContent.includes(n),
                );
            await click(
                () =>
                    q(
                        'button[aria-label="' +
                            S.food.more('Pizza Salami') +
                            '"]',
                    ),
                'plus',
            );
            await click(
                () =>
                    q(
                        'button[aria-label="' +
                            S.food.more('Pizza Salami') +
                            '"]',
                    ),
                'plus',
            );
            await waitFor(
                () => text('.sum').includes('19,00'),
                3000,
                'running total 19,00 EUR',
            );
            await shot('41-essen-total');
            await clickText('button', S.food.order, lineOf('Salami'));
            await waitFor(
                () => toastText().includes(S.food.placed(2, 'Pizza Salami')),
                6000,
                'placed toast',
            );
            await shot('41b-essen-placed', { toast: true });
        },
    );
    await step(
        'Essen: the item limit shows the server message in the line',
        async () => {
            for (let i = 0; i < 9; i++) {
                await click(
                    () =>
                        q(
                            'button[aria-label="' +
                                S.food.more('Cola 0,5 l') +
                                '"]',
                        ),
                    'plus cola',
                );
            }

            const line = qa('#screen-essen .menu-line').find((l) =>
                l.textContent.includes('Cola'),
            );
            await clickText('button', S.food.order, line);
            await waitFor(
                () => qa('#screen-essen .inline-error').length > 0,
                8000,
                'limit message',
            );
            await shot('42-essen-limit');
        },
    );
    await step(
        'Essen: my orders with the timeline, cancel while open',
        async () => {
            await clickText('.seg-btn', S.food.sub.orders);
            await waitFor(
                () => qa('#screen-essen .timeline').length >= 2,
                4000,
                'timeline',
            );
            await shot('43-essen-orders');
            const before = qa('#screen-essen .menu-line').length;
            await click(
                () =>
                    qa('#screen-essen button').find(
                        (b) => visible(b) && b.textContent === S.food.cancel,
                    ),
                'cancel',
            );
            await waitFor(
                () => qa('#screen-essen .menu-line').length === before - 1,
                5000,
                'item gone',
            );
            assert(
                byText('#screen-essen .small', S.food.noCancelPaid) ||
                    byText('#screen-essen .small', S.food.noCancelClosed),
                'reason when cancel is not allowed',
            );
        },
    );

    // ---------------------------------------------------------------- Mehr
    const toHub = async () => {
        await tab('mehr');

        for (let i = 0; i < 3 && byText('#screen-mehr button', S.back); i++) {
            await click(byText('#screen-mehr button', S.back));
        }
    };
    const friendRow = (name) =>
        qa('#screen-mehr li.pr').find(
            (r) => visible(r) && q('.name', r)?.textContent.trim() === name,
        );
    await step(
        'Freunde: accept, decline, send, cancel, with the server own message when it refuses',
        async () => {
            await toHub();
            await clickText('.list-row', S.more.friends);
            await waitFor(
                () => qa('#screen-mehr li.pr').length >= 9,
                6000,
                'friend rows',
            );
            await waitFor(
                () =>
                    q('img.avatar-img', friendRow('Ben')) &&
                    q('img.avatar-img', friendRow('Cleo')),
                8000,
                'avatar pictures in the friends list',
            );
            assert(
                !q('img.avatar-img', friendRow('Fynn')) &&
                    q('.avatar', friendRow('Fynn'))?.textContent.trim() === 'F',
                'Fynn has no avatar: the monogram stays',
            );
            await shot('64-friends');
            await clickText('button', S.friends.accept, friendRow('Gero'));
            await waitFor(
                () => toastText().includes(S.friends.accepted('Gero')),
                4000,
                'accepted toast',
            );
            await waitFor(
                () =>
                    friendRow('Gero') &&
                    byText('button', S.friends.remove, friendRow('Gero')),
                4000,
                'Gero is a friend now',
            );
            await clickText('button', S.friends.decline, friendRow('Lena'));
            await waitFor(
                () =>
                    toastText().includes(S.friends.declined) &&
                    !friendRow('Lena'),
                4000,
                'declined, gone from the list',
            );
            await clickText('button', S.friends.send, friendRow('Cleo'));
            await waitFor(
                () =>
                    toastText().includes(S.friends.sent('Cleo')) &&
                    byText('button', S.friends.cancel, friendRow('Cleo')),
                4000,
                'Cleo moved to the sent requests',
            );
            await clickText('button', S.friends.send, friendRow('Karl'));
            await waitFor(
                () =>
                    byText(
                        '#screen-mehr .inline-error',
                        'Du hast Karl schon eine Anfrage gesendet.',
                    ),
                4000,
                'the server message',
            );
            await shot('65-friends-error');
            await clickText('button', S.friends.cancel, friendRow('Hanna'));
            await waitFor(
                () =>
                    toastText().includes(S.friends.cancelled) &&
                    !friendRow('Hanna'),
                4000,
                'cancelled',
            );
        },
    );
    await step(
        'Freunde: remove and block ask first, inside the page; Escape keeps the friend',
        async () => {
            await clickText('button', S.friends.remove, friendRow('Ben'));
            await waitFor(
                () => q('#screen-mehr [role=alertdialog]'),
                3000,
                'confirmation',
            );
            assert(
                text('#screen-mehr [role=alertdialog]').includes(
                    S.friends.confirmRemoveTitle('Ben'),
                ),
                'names the person',
            );
            assert(
                document.activeElement.textContent.trim() === S.friends.keep,
                'focus starts on the safe button',
            );
            await shot('66-friends-confirm');
            await hook('key Escape');
            await waitFor(
                () => !q('#screen-mehr [role=alertdialog]'),
                3000,
                'Escape closed it',
            );
            assert(friendRow('Ben'), 'Ben is still a friend');
            await clickText('button', S.friends.remove, friendRow('Ben'));
            await clickText('[role=alertdialog] button', S.friends.remove);
            await waitFor(
                () =>
                    toastText().includes(S.friends.removed('Ben')) &&
                    !friendRow('Ben'),
                4000,
                'removed',
            );
            await clickText('button', S.friends.block, friendRow('Fynn'));
            await waitFor(
                () => q('#screen-mehr [role=alertdialog]'),
                3000,
                'block confirmation',
            );
            await clickText('[role=alertdialog] button', S.friends.block);
            await waitFor(
                () =>
                    toastText().includes(S.friends.blockedDone('Fynn')) &&
                    byText('button', S.friends.unblock, friendRow('Fynn')),
                4000,
                'Fynn is blocked',
            );
            await shot('67-friends-blocked');
            assert(
                byText('#screen-mehr .hint', S.friends.blockedHint),
                'the calm hint about who blocked whom',
            );
            await clickText('button', S.friends.unblock, friendRow('Jonas'));
            await waitFor(
                () => toastText().includes(S.friends.unblocked('Jonas')),
                4000,
                'unblocked',
            );
            await clickText('button', S.back);
        },
    );
    const fieldErr = (key) => q(`#pf-${key}-err`);
    await step(
        'Profil: server data, a validation error per field, then a saved change',
        async () => {
            await toHub();
            await clickText('.list-row', S.more.profile);
            await waitFor(() => q('#pf-name'), 5000, 'form');
            assert(q('#pf-name').value === 'Tester', 'the name');
            assert(
                byText('.chip', S.profile.emailDone),
                'verified e-mail chip',
            );
            await shot('68-profile');
            await typeInto(q('#pf-steam_url'), 'http://example.test/x');
            await typeInto(q('#pf-profile_color'), 'rot');
            await clickText('button', S.profile.save);
            await waitFor(
                () => visible(fieldErr('steam_url')),
                4000,
                'steam error',
            );
            assert(
                fieldErr('steam_url').textContent.includes(
                    'https://steamcommunity.com/',
                ) && visible(fieldErr('profile_color')),
                'the server text under each field',
            );
            assert(
                !visible(fieldErr('name')) &&
                    q('#pf-steam_url').getAttribute('aria-invalid') === 'true',
                'only the wrong fields are marked',
            );
            assert(
                document.activeElement === q('#pf-steam_url'),
                'focus goes to the first wrong field',
            );
            fieldErr('steam_url').scrollIntoView({ block: 'center' });
            await sleep(250);
            await shot('69-profile-errors');
            await typeInto(
                q('#pf-steam_url'),
                'https://steamcommunity.com/id/tester',
            );
            await typeInto(q('#pf-profile_color'), '#112233');
            await typeInto(q('#pf-bio'), 'Spielt Rocket League und Valheim.');
            await clickText('button', S.profile.save);
            await waitFor(
                () => toastText().includes(S.profile.saved),
                4000,
                'saved toast',
            );
            await waitFor(
                () =>
                    q('#pf-bio') &&
                    q('#pf-bio').value ===
                        'Spielt Rocket League und Valheim.' &&
                    !visible(fieldErr('steam_url')),
                4000,
                'the form shows the saved values',
            );
        },
    );
    await step(
        'Profil: the location switch says what it shares and takes effect at once',
        async () => {
            const box = q('#pf-share');
            assert(box && box.checked, 'on by default');
            assert(
                text('#pf-share-text') === S.profile.shareText &&
                    text('#pf-share-text').includes('Sprachraum') &&
                    text('#pf-share-text').includes('sofort'),
                'one plain sentence',
            );
            q('#pf-share').scrollIntoView({ block: 'center' });
            await sleep(250);
            await shot('69b-profile-privacy');
            await click(box);
            await waitFor(
                () => text('#pf-share-status') === S.profile.shareOff,
                4000,
                'off',
            );
            const hidden = await inv('list_people', {
                event: 'lan-party-2026',
            });
            assert(
                hidden.me.voice.length === 0,
                'the server no longer shows my location',
            );
            await click(q('#pf-share'));
            await waitFor(
                () => text('#pf-share-status') === S.profile.shareOn,
                4000,
                'on again',
            );
            const shown = await inv('list_people', {
                event: 'lan-party-2026',
            });
            assert(shown.me.voice.length === 1, 'visible again');
        },
    );
    await step(
        'Profil: linked accounts, Verknüpfen waits for the browser until the status changes',
        async () => {
            const conn = (label) =>
                qa('#screen-mehr li.pr').find(
                    (r) =>
                        visible(r) &&
                        q('.name', r)?.textContent.trim() === label,
                );
            const toRow = async (label) => {
                conn(label)?.scrollIntoView({ block: 'center' });
                await sleep(250);
            };
            await waitFor(() => conn('Twitch'), 4000, 'connections');
            assert(
                byText('.small', S.profile.loginAccount, conn('Discord')) &&
                    !q('button', conn('Discord')),
                'Discord is the read-only login account',
            );
            await toRow('Twitch');
            await shot('69c-profile-connections');

            // The real command checks the link before the system browser gets it: a link of
            // another origin, another path or with a fragment is refused (a selftest build lets
            // the stand-in's own plain-http loopback origin through, nothing else).
            for (const url of [
                'https://evil.example/settings/connections/link/x',
                `${P.serverUrl}/settings/other/x`,
                `${P.serverUrl}/settings/connections/link/x#f`,
            ]) {
                const refused = await inv('open_link_url', { url }).then(
                    () => null,
                    (e) => e,
                );
                assert(
                    refused && refused.kind === 'usage',
                    `open_link_url refuses ${url}`,
                );
            }

            await clickText('button', S.profile.link, conn('Twitch'));
            await waitFor(
                () => byText('.waiting', S.profile.waiting),
                4000,
                'waiting state',
            );
            await toRow('Twitch');
            await shot('69d-profile-link-waiting');
            assert(
                !document.body.textContent.includes('demo-link'),
                'the single-use link is never on screen',
            );
            await waitFor(
                () => toastText().includes(S.profile.linked('Twitch')),
                20000,
                'the status changed on the server',
            );
            await waitFor(
                () => byText('button', S.profile.unlink, conn('Twitch')),
                4000,
                'Twitch shows as connected',
            );
            assert(
                text('#screen-mehr').includes(S.profile.as('tester_twitch')),
                'the name at the provider',
            );
            await toRow('Twitch');
            await shot('69e-profile-linked');
        },
    );
    await step(
        'Profil: cancel a link, unlink with a confirmation, link again after re-auth',
        async () => {
            const conn = (label) =>
                qa('#screen-mehr li.pr').find(
                    (r) =>
                        visible(r) &&
                        q('.name', r)?.textContent.trim() === label,
                );
            const toRow = async (label) => {
                conn(label)?.scrollIntoView({ block: 'center' });
                await sleep(250);
            };
            await clickText('button', S.profile.unlink, conn('Twitch'));
            await waitFor(
                () => q('#screen-mehr [role=alertdialog]'),
                3000,
                'unlink confirmation',
            );
            q('#screen-mehr [role=alertdialog]').scrollIntoView({
                block: 'center',
            });
            await sleep(250);
            await shot('69f-profile-unlink-confirm');
            await clickText('[role=alertdialog] button', S.profile.unlink);
            await waitFor(
                () => toastText().includes(S.profile.unlinked('Twitch')),
                4000,
                'unlinked',
            );
            await clickText('button', S.profile.link, conn('Twitch'));
            await waitFor(
                () => byText('.waiting', S.profile.waiting),
                4000,
                'waiting again',
            );
            await clickText('button', S.profile.cancelWait);
            await waitFor(() => !q('.waiting'), 3000, 'the wait is cancelled');
            // The stand-in would still flip after its delay: reset it, then show a status that
            // needs the person to link again.
            await hook('hostget __connection/twitch/not_connected');
            await hook('hostget __connection/steam/needs_reauth');
            await clickText('button', S.back);
            await clickText('.list-row', S.more.profile);
            await waitFor(
                () => byText('button', S.profile.relink, conn('Steam')),
                4000,
                'Steam asks for a new link',
            );
            await toRow('Steam');
            await shot('69g-profile-reauth');
            await hook('hostget __connection/steam/connected');
            await clickText('button', S.back);
        },
    );
    await step('Abstimmungen: list and a detail with results', async () => {
        await toHub();
        await clickText('.list-row', S.more.polls);
        await waitFor(
            () => qa('#screen-mehr .list-row').length >= 2,
            4000,
            'polls',
        );
        await shot('51-polls');
        await clickText('.list-row', 'Pizza');
        await waitFor(
            () => qa('#screen-mehr .result').length === 3,
            4000,
            'results',
        );
        await shot('52-poll-results');
        assert(
            text('#screen-mehr .results').includes('58,3'),
            'percent as the server returns it (58,3)',
        );
        await clickText('button', S.back);
    });
    await step(
        'Dateien: list, quota, download with progress and the final path',
        async () => {
            await clickText('.list-row', S.more.files);
            await waitFor(
                () => qa('#screen-mehr .file-row').length >= 3,
                5000,
                'file rows',
            );
            await shot('53-files');
            const row = qa('#screen-mehr .file-row').find((r) =>
                r.textContent.includes('maps.zip'),
            );
            await clickText('button', S.files.download, row);
            await waitFor(
                () =>
                    qa('#screen-mehr .transfer').length >= 1 &&
                    /\d+ %/.test(text('#screen-mehr .transfer')),
                6000,
                'progress',
            );
            await shot('54-files-download-progress');
            await waitFor(
                () => text('#screen-mehr .transfer').includes(S.files.savedTo),
                15000,
                'done with the final path',
            );
            await shot('55-files-download-done');

            return text('#screen-mehr .transfer .path');
        },
    );
    await step(
        'Dateien: upload by drag and drop, a forced drop, pause and continue',
        async () => {
            // The webview delivers a dropped file as a window event with paths, which a script can send itself.
            await window.__TAURI__.event.emit('tauri://drag-drop', {
                paths: [P.uploadPath],
                position: { x: 10, y: 10 },
            });
            await waitFor(
                () =>
                    qa('#screen-mehr .transfer').some(
                        (t) =>
                            t.textContent.includes('upload.bin') &&
                            /\d+ %/.test(t.textContent),
                    ),
                8000,
                'upload progress',
            );
            await shot('56-files-upload-progress');
            const row = () =>
                qa('#screen-mehr .transfer').find((t) =>
                    t.textContent.includes('upload.bin'),
                );
            await clickText('button', S.files.pause, row());
            await waitFor(
                () => row().textContent.includes(S.files.st.paused),
                5000,
                'paused',
            );
            await shot('57-files-upload-paused');
            await clickText('button', S.files.resume, row());
            await waitFor(
                () => row().textContent.includes(S.files.moderation),
                30000,
                'upload finished with the moderation note',
            );
            await shot('58-files-upload-done');
            await waitFor(
                () =>
                    qa('#screen-mehr .file-row').some((r) =>
                        r.textContent.includes('upload.bin'),
                    ),
                6000,
                'new file in the list',
            );
        },
    );
    await step('Dateien: delete my own file (two taps)', async () => {
        const row = qa('#screen-mehr .file-row').find((r) =>
            r.textContent.includes('mein-mod.zip'),
        );
        const b = byText('button', S.files.delete, row);
        await click(b);
        assert(b.textContent === S.files.sure, 'asks once');
        await click(b);
        await waitFor(
            () =>
                !qa('#screen-mehr .file-row').some((r) =>
                    r.textContent.includes('mein-mod.zip'),
                ),
            5000,
            'file gone',
        );
        await clickText('button', S.back);
    });
    await step(
        'Benachrichtigungen: history, mark read, category switches, Ruhe',
        async () => {
            await clickText('.list-row', S.more.notifications);
            await waitFor(
                () => qa('#screen-mehr .note-row').length >= 3,
                4000,
                'notifications',
            );
            await waitFor(
                () => qa('#screen-mehr .cat-row input').length > 8,
                4000,
                'category switches',
            );
            await shot('59-notifications');
            await clickText('button', S.notes.markAll);
            await waitFor(
                () => qa('#screen-mehr .note-row.unread').length === 0,
                4000,
                'all read',
            );
            const box = qa('#screen-mehr .cat-row input[type=checkbox]')[0];
            box.click();
            await sleep(400);
            await click(q('#quiet'));
            await waitFor(
                () => q('#quiet').getAttribute('aria-pressed') === 'true',
                3000,
                'Ruhe on',
            );
            await shot('60-notifications-ruhe');
            await click(q('#quiet'));
            await clickText('button', S.back);
        },
    );
    await step('Einstellungen: audio, key, window, downloads', async () => {
        await clickText('.list-row', S.more.settings);
        await waitFor(
            () => q('#input-device') && q('#input-device').options.length > 1,
            4000,
            'devices',
        );
        await shot('61-settings');
        q('#screen-mehr .stack').scrollTo?.(0, 0);
        document.getElementById('main').scrollTop = 900;
        await sleep(300);
        await shot('62-settings-lower');
        await clickText('button', S.back);
    });
    await step('Gerät und Konto', async () => {
        await clickText('.list-row', S.more.account);
        await waitFor(
            () => byText('#screen-mehr button', S.account.logout),
            3000,
            'logout',
        );
        await shot('63-account');
        await clickText('button', S.back);
    });

    // ---------------------------------------------------------------- update notice
    await step(
        'update: a newer build is offered, Später hides it for this version',
        async () => {
            const env = await inv('get_environment');
            assert(
                env.version !== 'dev',
                'the smoke needs the test build: LANOMAT_BUILD_VERSION=m13-12-g0a1b2c3 (see smoke-ui.sh)',
            );
            await hook('hostget __client/update-available');
            await checkForUpdates();
            await tab('jetzt');
            await waitFor(
                () => visible(q('#update-banner')),
                6000,
                'update notice',
            );
            const box = q('#update-banner');
            assert(box.getAttribute('role') === 'status', 'role=status');
            assert(
                text('#update-banner .update-text') ===
                    S.update.available(UPDATE_LATEST, env.version),
                `the offer names both versions, got "${text('#update-banner .update-text')}"`,
            );
            assert(
                byText('#update-banner button', S.update.download) &&
                    byText('#update-banner button', S.update.later),
                'two labelled buttons',
            );
            await shot('update-1-available');
            await clickText('#update-banner button', S.update.later);
            await waitFor(
                () => !visible(q('#update-banner')),
                2000,
                'the notice to close',
            );
            assert(
                document.activeElement === document.getElementById('main'),
                'focus moved to the main area, not lost',
            );
            // Hidden for this version; a manual check names it in words instead.
            await checkForUpdates();
            assert(
                !visible(q('#update-banner')),
                'the dismissed version stays hidden',
            );
            assert(
                text('#screen-mehr [role=status]').includes(
                    S.update.stillAvailable(UPDATE_LATEST),
                ),
                'the manual check names the dismissed version',
            );
        },
    );
    await step(
        'update: an incompatible client gets a notice on every tab, without a dismiss',
        async () => {
            await hook('hostget __client/incompatible');
            await checkForUpdates();
            await tab('jetzt');
            await waitFor(
                () => visible(q('#update-banner.required')),
                6000,
                'incompatible notice',
            );
            assert(
                text('#update-banner .update-text') ===
                    S.update.incompatible(UPDATE_LATEST),
                'the incompatible text names the minimum',
            );
            assert(
                !byText('#update-banner button', S.update.later),
                'no dismiss control on the incompatible notice',
            );
            await shot('update-2-incompatible');

            for (const id of ['sprache', 'spielen', 'essen', 'mehr']) {
                await tab(id);
                assert(
                    visible(q('#update-banner.required')),
                    `the notice shows on ${id}`,
                );
            }

            await tab('jetzt');
            await clickText('#update-banner button', S.update.download);
            await sleep(300);
        },
    );
    await step(
        'update: an up-to-date build shows nothing and the manual check says so',
        async () => {
            await hook('hostget __client/uptodate');
            await checkForUpdates();
            await tab('jetzt');
            await sleep(300);
            assert(
                !visible(q('#update-banner')),
                'no notice for an up-to-date build',
            );
            await checkForUpdates();
            await waitFor(
                () =>
                    text('#screen-mehr [role=status]').includes(
                        S.update.upToDate,
                    ),
                4000,
                'the up-to-date line',
            );
            await shot('update-3-account');
            await tab('jetzt');
        },
    );

    // ---------------------------------------------------------------- stale data and offline
    await step(
        'a failed refresh keeps the last data and says so, with a retry',
        async () => {
            await hook('scenario error');
            await tab('essen');
            await tab('spielen');
            await clickText('.seg-btn', S.play.sub.matches);
            await waitFor(
                () => byText('#screen-spielen .hint', S.stale),
                8000,
                'stale hint in Spielen',
            );
            assert(
                qa('#screen-spielen article.match').length >= 2,
                'the old matches stay visible',
            );
            await shot('80-stale-spielen');
            await tab('essen');
            await clickText('.seg-btn', S.food.sub.menu);
            await waitFor(
                () => byText('#screen-essen .hint', S.stale),
                8000,
                'stale hint in Essen',
            );
            await hook('scenario normal');
            await clickText('#screen-essen .hint button', S.retry);
            await waitFor(
                () => !byText('#screen-essen .hint', S.stale),
                8000,
                'recovered after the retry',
            );
        },
    );
    await step('offline: the app says so and keeps going', async () => {
        await hook('scenario offline');
        await tab('jetzt');
        await tab('essen');
        await tab('jetzt');
        await waitFor(
            () => visible(q('#offline-banner')),
            8000,
            'offline banner',
        );
        await shot('85-offline');
        await hook('scenario normal');
        await tab('essen');
        await tab('jetzt');
        await waitFor(
            () =>
                !visible(q('#offline-banner')) &&
                qa('#screen-jetzt article.card').length >= 2,
            12000,
            'back online',
        );
    });

    await report(true, 'SUMMARY failures=' + failures, '');
    await inv('selftest_done', { failures });
})();
