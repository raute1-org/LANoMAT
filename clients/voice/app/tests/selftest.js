// Command self-test through the real IPC (see app/src/selftest.rs). Evaluated in the main webview
// of an app built with `--features selftest`; talks to the stand-in server
// (standin_server.py --features). Parameters arrive as window.__SELFTEST:
//   { downloadDir, uploadPath, secretPath, waitMs? }
(async () => {
    const P = window.__SELFTEST || {};
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    while (!window.__TAURI__) {
        await sleep(100);
    }

    const inv = (name, args) => window.__TAURI__.core.invoke(name, args);
    const listen = window.__TAURI__.event.listen;
    let failures = 0;

    const report = async (ok, name, detail) => {
        if (!ok) {
            failures++;
        }

        await inv('selftest_report', {
            line: `${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' - ' + detail : ''}`,
        });
    };
    const text = (v) =>
        typeof v === 'string'
            ? v
            : v instanceof Error
              ? v.message
              : JSON.stringify(v);
    const check = async (name, fn) => {
        try {
            const detail = await fn();
            await report(true, name, detail);
        } catch (e) {
            await report(false, name, text(e));
        }
    };
    const expectErr = async (name, test, fn) => {
        try {
            await fn();
            await report(false, name, 'did not fail');
        } catch (e) {
            const ok = test(e);
            await report(ok, name, text(e));
        }
    };
    const waitFor = async (pred, ms, what) => {
        const end = Date.now() + ms;

        while (Date.now() < end) {
            if (pred()) {
                return;
            }

            await sleep(50);
        }

        throw new Error('timed out waiting for ' + what);
    };
    const assert = (cond, msg) => {
        if (!cond) {
            throw new Error(msg);
        }
    };

    const chatEvents = [];
    const transfers = [];
    const notify = [];
    await listen('chat-event', (e) => chatEvents.push(e.payload));
    await listen('transfer-updated', (e) => transfers.push(e.payload));
    await listen('notify-event', (e) => notify.push(e.payload));

    // ---- config, events, now feed, play
    let slug = null;
    await check('get_config: modules and the realtime flag', async () => {
        const c = await inv('get_config');
        assert(
            c.modules.game_servers === true && c.modules.schedule === false,
            'modules',
        );
        assert(c.realtime === true && c.api_version === 1, 'realtime flag');
        assert(
            !('key' in c) && !JSON.stringify(c).includes('standin-app-key'),
            'no connection details',
        );

        return `modules=${Object.keys(c.modules).length} realtime=${c.realtime}`;
    });
    await check(
        'list_events and current_event pick the live event',
        async () => {
            const e = await inv('list_events');
            const cur = await inv('current_event');
            slug = e.current_event_slug;
            assert(
                slug && cur && cur.slug === slug && cur.current === true,
                'current event',
            );

            return slug;
        },
    );
    await check(
        'now_feed (camelCase arg `event`): match and vote cards',
        async () => {
            const f = await inv('now_feed', { event: slug });
            assert(f.cards.length === 2, 'two cards');
            assert(
                f.cards[0].kind === 'match' &&
                    f.cards[0].data.match.server.joinUrl.startsWith(
                        'steam://connect/',
                    ),
                'match card',
            );
            assert(
                f.cards[1].kind === 'vote' &&
                    f.cards[1].data.options.length === 2,
                'vote card',
            );

            return f.cards.map((c) => c.id).join(',');
        },
    );
    await check(
        'list_my_matches: server DTO with joinUrl and copyText',
        async () => {
            const m = await inv('list_my_matches', { event: slug });
            const s = m[0].server;
            assert(
                s.joinUrl === 'steam://connect/203.0.113.5:27015' &&
                    s.copyText === '203.0.113.5:27015',
                'server fields',
            );
            assert(
                m[0].lockVersion === 0 && m[0].can.report === true,
                'match fields',
            );

            return `match ${m[0].id}`;
        },
    );
    await check(
        'list_tournaments and tournament_checkin (tournamentId)',
        async () => {
            const t = await inv('list_tournaments', { event: slug });
            assert(t[0].can.checkIn === true, 'can check in');
            const r = await inv('tournament_checkin', {
                tournamentId: t[0].id,
            });

            return `checked in ${r.id}`;
        },
    );
    await check('list_event_servers and list_permanent_servers', async () => {
        const s = await inv('list_event_servers', { event: slug });
        const p = await inv('list_permanent_servers');
        assert(s.length === 1 && Array.isArray(p), 'lists');

        return `${s.length} event, ${p.length} permanent`;
    });
    await check('polls: list, detail, vote (pollId, optionId)', async () => {
        const l = await inv('list_polls', { event: slug });
        const d = await inv('get_poll', { pollId: l.polls[0].id });
        const v = await inv('vote_poll', {
            pollId: d.id,
            optionId: d.options[0].id,
        });
        assert(v.my_vote_option_id === d.options[0].id, 'vote recorded');

        return `poll ${d.id}`;
    });
    await check('catering: menu', async () => {
        const c = await inv('get_catering', { event: slug });
        assert(c.orders[0].menu[0].price_cents === 850, 'price in cents');

        return c.orders[0].title;
    });

    // ---- the error object {kind, message, retryable, code?, fields?}
    await expectErr(
        'unknown event is not_found (retryable=false)',
        (e) => e.kind === 'not_found' && e.retryable === false,
        () => inv('list_polls', { event: 'no-such-event' }),
    );
    await expectErr(
        'tie score is validation with field errors',
        (e) => e.kind === 'validation' && e.fields && e.fields.score2,
        () => inv('match_report', { matchId: 88, score1: 2, score2: 2 }),
    );
    await expectErr(
        'stale lock_version is conflict with the server code',
        (e) =>
            e.kind === 'conflict' &&
            e.code === 'tournaments.errors.stale_match',
        () => inv('match_confirm', { matchId: 88, lockVersion: 0 }),
    );
    await expectErr(
        'an unregistered command is rejected',
        () => true,
        () => inv('definitely_not_a_command'),
    );
    await expectErr(
        'open_join_url refuses anything but steam://connect',
        (e) => e.kind === 'usage',
        () => inv('open_join_url', { url: 'steam://run/730' }),
    );
    await expectErr(
        'open_join_url refuses other schemes',
        (e) => e.kind === 'usage',
        () => inv('open_join_url', { url: 'https://example.org/' }),
    );
    await check(
        'open_join_url passes validation for a real join link',
        async () => {
            try {
                await inv('open_join_url', {
                    url: 'steam://connect/203.0.113.5:27015',
                });

                return 'handler started';
            } catch (e) {
                // No URL handler in a headless container is a local error, never a validation one.
                assert(e.kind === 'local', 'expected local, got ' + text(e));

                return 'validated; no system handler here (' + e.kind + ')';
            }
        },
    );
    await check('copy_text', async () => {
        try {
            await inv('copy_text', { text: '203.0.113.5:27015' });

            return 'written';
        } catch (e) {
            assert(e.kind === 'local', 'expected local, got ' + text(e));

            return 'no clipboard available here (' + e.kind + ')';
        }
    });

    // ---- notifications (the engine polls every 15 s while focused: mark one read now, look later)
    await check(
        'notifications: unread count, list, read, quiet toggle',
        async () => {
            const n = await inv('unread_count');
            const l = await inv('list_notifications', {
                unreadOnly: true,
                page: 1,
                perPage: 20,
            });
            assert(
                n === 1 &&
                    l.notifications.length === 1 &&
                    l.notifications[0].category === 'match',
                'list',
            );
            const prefs = await inv('get_notification_preferences');
            const eff = await inv('set_notification_preferences', {
                preferences: { friends: false },
            });
            assert(
                prefs.match === true && eff.friends === false,
                'preferences',
            );
            assert(
                (await inv('set_quiet', { quiet: true })) === true &&
                    (await inv('get_quiet')) === true,
                'quiet on',
            );
            assert(
                (await inv('set_quiet', { quiet: false })) === false,
                'quiet off',
            );
            const one = await inv('mark_notification_read', {
                id: l.notifications[0].id,
            });
            assert(
                one.read === true && (await inv('unread_count')) === 0,
                'mark read',
            );

            return 'ok';
        },
    );
    // ---- chat over the realtime link
    await check(
        'chat: realtime link goes live and a posted message comes back signed',
        async () => {
            await inv('chat_subscribe', { roomIds: [1] });
            await waitFor(
                () =>
                    chatEvents.some(
                        (e) => e.type === 'status' && e.state === 'live',
                    ),
                8000,
                'the live status',
            );
            const body = 'hallo zusammen ' + Date.now();
            const msg = await inv('chat_post', { roomId: 1, body });
            await waitFor(
                () =>
                    chatEvents.some(
                        (e) =>
                            e.type === 'message_posted' &&
                            e.message.id === msg.id,
                    ),
                8000,
                'the pushed message',
            );
            const h = await inv('chat_history', { roomId: 1 });
            assert(
                h.messages.some((m) => m.id === msg.id) &&
                    h.next_before === null,
                'history',
            );
            const st = chatEvents
                .filter((e) => e.type === 'status')
                .map((e) => e.state)
                .join('>');

            return `message ${msg.id}, link ${st}`;
        },
    );
    await expectErr(
        'chat_post rejects an empty message before any request',
        (e) => e.kind === 'validation',
        () => inv('chat_post', { roomId: 1, body: '   ' }),
    );
    await check('chat_unsubscribe', async () => {
        await inv('chat_unsubscribe');

        return 'stopped';
    });

    // ---- download with progress
    await check(
        'download_file: progress events and the file lands in the chosen folder',
        async () => {
            const s = await inv('load_settings');
            const saved = await inv('save_settings', {
                settings: { ...s, download_dir: P.downloadDir },
            });
            assert(
                saved.settings.download_dir === P.downloadDir,
                'download_dir saved',
            );
            assert(
                (await inv('get_download_dir')) === P.downloadDir,
                'get_download_dir',
            );
            const { transfer_id: id } = await inv('download_file', {
                fileId: 21,
                name: 'maps.zip',
            });
            await waitFor(
                () => transfers.some((t) => t.id === id && t.status === 'done'),
                20000,
                'the download',
            );
            const mine = transfers.filter((t) => t.id === id);
            const progress = mine
                .filter((t) => t.status === 'active')
                .map((t) => t.bytes_done);
            assert(
                progress.length >= 2 &&
                    progress.some(
                        (b) => b > 0 && b < mine[mine.length - 1].total,
                    ),
                'progress events',
            );
            const done = mine[mine.length - 1];
            assert(
                done.path === P.downloadDir + '/maps.zip' &&
                    done.bytes_done === done.total,
                'final path and bytes',
            );

            return `${progress.length} progress events, ${done.total} bytes -> ${done.path}`;
        },
    );

    // ---- tus upload with a forced mid-chunk drop
    await check(
        'upload_files: refused app files are reported per entry',
        async () => {
            const r = await inv('upload_files', {
                event: slug,
                paths: [P.secretPath],
            });
            assert(
                r[0].transfer_id === null && r[0].error.kind === 'usage',
                'refused',
            );

            return r[0].error.message;
        },
    );
    await check(
        'upload_files: tus upload resumes after a connection cut mid-chunk',
        async () => {
            const r = await inv('upload_files', {
                event: slug,
                paths: [P.uploadPath],
            });
            const id = r[0].transfer_id;
            assert(id, 'started');
            await waitFor(
                () =>
                    transfers.some(
                        (t) =>
                            t.id === id &&
                            (t.status === 'done' || t.status === 'error'),
                    ),
                60000,
                'the upload',
            );
            const mine = transfers.filter((t) => t.id === id);
            const last = mine[mine.length - 1];
            assert(last.status === 'done', 'finished: ' + text(last));
            assert(
                mine.some((t) => t.status === 'reconnecting'),
                'went through reconnecting',
            );
            assert(
                last.resumed === true && last.bytes_done === last.total,
                'resumed and complete',
            );

            return `${last.total} bytes, resumed=${last.resumed}`;
        },
    );
    await check('list_transfers, transfer_forget', async () => {
        const l = await inv('list_transfers');
        assert(
            l.length === 2 && l.every((t) => t.status === 'done'),
            'two finished transfers',
        );

        for (const t of l) {
            await inv('transfer_forget', { transferId: t.id });
        }

        assert((await inv('list_transfers')).length === 0, 'forgotten');

        return 'ok';
    });

    await check(
        'notify-event: the engine reports the new unread count',
        async () => {
            await waitFor(
                () => notify.some((e) => e.type === 'unread' && e.count === 0),
                40000,
                'the unread event',
            );

            return 'unread=0 seen in notify-event';
        },
    );

    await inv('selftest_report', { line: `SUMMARY failures=${failures}` });
    await inv('selftest_done', { failures });
})();
