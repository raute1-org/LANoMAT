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
                'Friends stays hidden without its commands',
            );
            assert(
                !byText('#screen-mehr .list-row', S.more.profile),
                'Profil stays hidden without its commands',
            );

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
    await step(
        'Mehr: hub with badges; Friends and Profil hidden until their commands exist',
        async () => {
            await tab('mehr');
            await waitFor(
                () => qa('#screen-mehr .list-row').length >= 4,
                4000,
                'hub',
            );
            await shot('50-mehr');
            assert(
                !byText('#screen-mehr .list-row', S.more.friends) &&
                    !byText('#screen-mehr .list-row', S.more.profile),
                'hidden entries',
            );
        },
    );
    await step('Abstimmungen: list and a detail with results', async () => {
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
