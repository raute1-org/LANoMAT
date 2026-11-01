// Match and tournament cards, shared by Jetzt (compact) and Spielen (full): voice room and
// server buttons, check-in, report, confirm, dispute and withdraw with the server's own
// German messages and the lock_version handling.
import { go } from './nav.js';
import { installHint, serverActions, serverLine } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clock,
    dayTime,
    el,
    plainMessage,
    relative,
    toast,
} from './util.js';
import { joinRoom } from './voice.js';

export function scoreText(score) {
    if (!score) {
        return '';
    }

    if (typeof score === 'string') {
        return score;
    }

    if (Array.isArray(score)) {
        return `${score[0]}:${score[1]}`;
    }

    const a = score.own ?? score.me ?? score.score1;
    const b = score.opponent ?? score.score2;

    return a === undefined || b === undefined ? '' : `${a}:${b}`;
}

const STATUS_TONE = {
    ready: 'ok',
    warmup: 'warn',
    live: 'live',
    reported: 'warn',
    disputed: 'down',
    pending: '',
    completed: '',
};

export function matchTitle(m) {
    const t = m.tournament ? m.tournament.name : S.matches.match;
    const parts = [t];

    if (m.round) {
        parts.push(S.matches.round(m.round));
    }

    return parts.join(', ');
}

function mapError(error) {
    if (error.kind === 'conflict') {
        return S.matches.stale;
    }

    const f = error.fields;

    if (f) {
        const first = Object.values(f).flat()[0];

        if (first) {
            return String(first);
        }
    }

    return plainMessage(error);
}

// opts: {compact, onChange}
export function matchCard(m, opts = {}) {
    const { compact = false, onChange = () => {} } = opts;
    const can = m.can || {};
    const msg = el('div', {
        class: 'inline-error',
        role: 'alert',
        hidden: true,
    });
    const buttons = [];
    let busy = false;
    const reportForm = el('form', {
        class: 'report-form',
        hidden: true,
        novalidate: true,
    });

    const fail = (error) => {
        msg.hidden = false;
        msg.textContent = mapError(error);

        if (error.kind === 'conflict') {
            onChange();
        }
    };

    const run = async (cmd, args, doneText) => {
        if (busy) {
            return;
        }

        busy = true;

        for (const b of buttons) {
            b.disabled = true;
        }

        msg.hidden = true;
        const r = await call(cmd, args);
        busy = false;

        for (const b of buttons) {
            b.disabled = false;
        }

        if (!r.ok) {
            fail(r.error);

            return;
        }

        toast(doneText);
        onChange();
    };

    const act = (label, kind, fn) => {
        const b = button(label, { kind, onclick: fn });
        buttons.push(b);

        return b;
    };

    const actions = el('div', { class: 'row wrap' });

    if (m.voiceRoomId) {
        actions.append(
            act(
                S.matches.joinRoom,
                can.report || m.status === 'ready' ? 'primary' : '',
                async () => {
                    if (busy) {
                        return;
                    }

                    const ok = await joinRoom(m.voiceRoomId);

                    if (!ok) {
                        msg.hidden = false;
                        msg.textContent = S.matches.roomFailed;
                    }
                },
            ),
        );
    }

    if (m.server) {
        const sa = serverActions(m.server, { primary: !m.voiceRoomId });

        if (sa) {
            actions.append(...sa.childNodes);
        }
    }

    if (can.checkIn && m.tournament) {
        actions.append(
            act(S.matches.checkIn, 'primary', () =>
                run(
                    'tournament_checkin',
                    { tournamentId: m.tournament.id },
                    S.matches.checkedIn,
                ),
            ),
        );
    }

    if (can.confirm) {
        actions.append(
            act(S.matches.confirm, 'primary', () =>
                run(
                    'match_confirm',
                    { matchId: m.id, lockVersion: m.lockVersion },
                    S.matches.confirmed,
                ),
            ),
        );
    }

    if (can.dispute) {
        actions.append(
            act(S.matches.dispute, 'danger', () =>
                run('match_dispute', { matchId: m.id }, S.matches.disputed),
            ),
        );
    }

    if (can.withdrawReport) {
        actions.append(
            act(S.matches.withdraw, '', () =>
                run(
                    'match_withdraw_report',
                    { matchId: m.id, lockVersion: m.lockVersion },
                    S.matches.withdrawn,
                ),
            ),
        );
    }

    if (can.report && !compact) {
        const own = el('input', {
            type: 'number',
            min: '0',
            max: '999',
            inputmode: 'numeric',
            id: `own-${m.id}`,
            'aria-label': S.matches.ownScore,
        });
        const opp = el('input', {
            type: 'number',
            min: '0',
            max: '999',
            inputmode: 'numeric',
            id: `opp-${m.id}`,
            'aria-label': S.matches.oppScore,
        });
        const submit = el('button', {
            class: 'btn primary',
            type: 'submit',
            text: S.matches.send,
        });
        buttons.push(submit);
        reportForm.append(
            el(
                'div',
                { class: 'row wrap' },
                el('label', {
                    class: 'inline-label',
                    for: own.id,
                    text: S.matches.us,
                }),
                own,
                el('span', { class: 'colon', text: ':' }),
                opp,
                el('label', {
                    class: 'inline-label',
                    for: opp.id,
                    text: S.matches.them,
                }),
                submit,
            ),
        );
        reportForm.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const a = Number.parseInt(own.value, 10);
            const b = Number.parseInt(opp.value, 10);

            if (
                !Number.isInteger(a) ||
                !Number.isInteger(b) ||
                a < 0 ||
                b < 0
            ) {
                msg.hidden = false;
                msg.textContent = S.matches.needScores;

                return;
            }

            if (a === b) {
                msg.hidden = false;
                msg.textContent = S.matches.noTie;

                return;
            }

            run(
                'match_report',
                { matchId: m.id, score1: a, score2: b },
                S.matches.reported,
            );
        });
        actions.append(
            el('button', {
                class: 'btn' + (m.status === 'live' ? ' primary' : ''),
                type: 'button',
                text: S.matches.report,
                'aria-expanded': 'false',
                onclick: (ev) => {
                    reportForm.hidden = !reportForm.hidden;
                    ev.currentTarget.setAttribute(
                        'aria-expanded',
                        String(!reportForm.hidden),
                    );

                    if (!reportForm.hidden) {
                        own.focus();
                    }
                },
            }),
        );
    } else if (can.report && compact) {
        actions.append(
            button(S.matches.toMatch, {
                onclick: () => go('spielen', { sub: 'matches', force: true }),
            }),
        );
    }

    const pr = m.pendingReport;
    const score = scoreText(m.score);
    const opponent = m.opponent ? m.opponent.name : S.matches.opponentOpen;
    const me = m.me ? m.me.name : '';

    return el(
        'article',
        { class: `card match ${m.phase || ''}`, 'aria-label': matchTitle(m) },
        el(
            'div',
            { class: 'row between wrap' },
            compact
                ? el('div', { class: 'small muted', text: matchTitle(m) })
                : el('h3', { text: matchTitle(m) }),
            chip(
                S.matches.status[m.status] || m.status || '',
                STATUS_TONE[m.status] || '',
            ),
        ),
        el(
            'div',
            { class: 'vs' },
            el('span', { class: 'side', text: me || S.matches.you }),
            el('span', { class: 'vs-sep', text: score || S.matches.vs }),
            el('span', { class: 'side', text: opponent }),
        ),
        m.tournament && m.tournament.game
            ? el('div', {
                  class: 'small muted',
                  text:
                      m.tournament.game +
                      (m.bracket
                          ? ` · ${S.matches.bracket[m.bracket] || m.bracket}`
                          : ''),
              })
            : null,
        m.scheduledAt
            ? el('div', {
                  class: 'small muted',
                  text: S.matches.scheduled(dayTime(m.scheduledAt)),
              })
            : null,
        pr
            ? el('div', {
                  class: 'hint warn',
                  text: pr.reportedByMe
                      ? S.matches.youReported(pr.ownScore, pr.opponentScore)
                      : S.matches.theyReported(
                            opponent,
                            pr.opponentScore,
                            pr.ownScore,
                        ),
              })
            : null,
        m.server ? serverLine(m.server) : null,
        actions.childElementCount ? actions : null,
        reportForm,
        m.server ? installHint(m.server) : null,
        msg,
    );
}

export function tournamentCard(t, { onChange = () => {} } = {}) {
    const msg = el('div', {
        class: 'inline-error',
        role: 'alert',
        hidden: true,
    });
    const mine = t.myEntry;
    const checkIn =
        t.can && t.can.checkIn
            ? button(S.matches.checkIn, {
                  kind: 'primary',
                  onclick: async (ev) => {
                      const b = ev.currentTarget;
                      b.disabled = true;
                      msg.hidden = true;
                      const r = await call('tournament_checkin', {
                          tournamentId: t.id,
                      });
                      b.disabled = false;

                      if (!r.ok) {
                          msg.hidden = false;
                          msg.textContent = mapError(r.error);

                          return;
                      }

                      toast(S.matches.checkedIn);
                      onChange();
                  },
              })
            : null;
    let state = S.tournaments.notEnrolled;
    let tone = '';

    if (mine) {
        state = S.tournaments.entry[mine.status] || S.tournaments.enrolled;
        tone = 'ok';
    } else if (t.registration && t.registration.open) {
        state = S.tournaments.registerOnWeb;
    }

    return el(
        'article',
        { class: 'card tournament', 'aria-label': t.name },
        el(
            'div',
            { class: 'row between wrap' },
            el('h3', { text: t.name }),
            chip(
                S.tournaments.status[t.status] || t.status || '',
                t.status === 'live' ? 'live' : '',
            ),
        ),
        el('div', {
            class: 'small muted',
            text: [t.game, S.tournaments.entries(t.entryCount, t.maxEntries)]
                .filter(Boolean)
                .join(' · '),
        }),
        t.startsAt
            ? el('div', {
                  class: 'small',
                  text: S.tournaments.starts(dayTime(t.startsAt)),
              })
            : null,
        t.checkinClosesAt && t.can && t.can.checkIn
            ? el('div', {
                  class: 'small',
                  text: S.tournaments.checkinCloses(
                      clock(t.checkinClosesAt),
                      relative(t.checkinClosesAt),
                  ),
              })
            : null,
        el('div', { class: 'row' }, chip(state, tone)),
        checkIn,
        msg,
    );
}
