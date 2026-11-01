// Mehr > Dateien: the shared files, downloads and uploads with progress, pause, resume and
// cancel, the quota as one bar, and the plain-words moderation note.
import { bar } from './polls.js';
import { eventSlug, noEventState } from './shared.js';
import { S } from './strings.js';
import {
    bytes,
    button,
    call,
    chip,
    clear,
    dayTime,
    dot,
    el,
    emptyState,
    errorState,
    listen,
    plainMessage,
    say,
    skeleton,
    speed,
    staleHint,
    toast,
} from './util.js';

const transfers = new Map(); // id -> TransferView
const uploadedNames = new Set(); // names of uploads that finished: the file is waiting for the Orga
let live = null; // {box, refreshList} while the screen is open
let files = { phase: 'loading', data: null, error: null };

export function init() {
    listen('transfer-updated', (e) => {
        const t = e.payload;
        const before = transfers.get(t.id);
        transfers.set(t.id, t);

        if (t.status === 'done' && (!before || before.status !== 'done')) {
            if (t.kind === 'upload') {
                uploadedNames.add(t.name);
                toast(S.files.uploadDone(t.name));
            } else {
                toast(S.files.downloadDone(t.name));
            }

            if (live) {
                live.refreshList();
            }
        }

        if (live) {
            live.paintTransfers();
        }
    });

    // Drag and drop: the webview delivers file paths through the window events, not DOM drops.
    const onDrop = (paths) => {
        if (live && paths && paths.length) {
            live.upload(paths);
        }

        if (live) {
            live.dropzone.classList.remove('over');
        }
    };
    listen('tauri://drag-drop', (e) =>
        onDrop((e.payload && e.payload.paths) || []),
    );
    listen(
        'tauri://drag-enter',
        () => live && live.dropzone.classList.add('over'),
    );
    listen(
        'tauri://drag-leave',
        () => live && live.dropzone.classList.remove('over'),
    );
}

export function unmount() {
    live = null;
}

function describe(t) {
    const pct =
        t.total > 0
            ? Math.min(100, Math.floor((t.bytes_done / t.total) * 100))
            : 0;

    switch (t.status) {
        case 'queued':
            return S.files.st.queued;
        case 'preparing':
            return S.files.st.preparing;
        case 'active': {
            const eta = t.eta_secs > 0 ? S.files.eta(t.eta_secs) : '';

            return [
                t.total > 0 ? `${pct} %` : bytes(t.bytes_done),
                speed(t.speed_bps),
                eta,
            ]
                .filter(Boolean)
                .join(' · ');
        }
        case 'reconnecting':
            return S.files.st.reconnecting;
        case 'paused':
            return `${S.files.st.paused} · ${pct} %`;
        case 'done':
            return S.files.st.done;
        case 'cancelled':
            return S.files.st.cancelled;
        case 'error':
            return t.error && t.error.message
                ? t.error.message
                : S.files.st.error;
        default:
            return t.status;
    }
}

function transferRow(t) {
    const finished = ['done', 'error', 'cancelled'].includes(t.status);
    const pct = t.total > 0 ? Math.floor((t.bytes_done / t.total) * 100) : 0;
    const tone =
        t.status === 'error'
            ? 'down'
            : t.status === 'done'
              ? 'ok'
              : t.status === 'active'
                ? 'live'
                : 'warn';
    const act = [];

    if (t.status === 'active' || t.status === 'queued') {
        act.push(
            button(S.files.pause, {
                onclick: () => call('transfer_pause', { transferId: t.id }),
            }),
        );
    }

    if (
        t.status === 'paused' ||
        (t.status === 'error' && t.error && t.error.retryable)
    ) {
        act.push(
            button(t.status === 'paused' ? S.files.resume : S.files.retry, {
                kind: 'primary-soft',
                onclick: () => call('transfer_resume', { transferId: t.id }),
            }),
        );
    }

    if (!finished) {
        act.push(
            button(S.files.cancel, {
                kind: 'danger',
                onclick: () => call('transfer_cancel', { transferId: t.id }),
            }),
        );
    } else {
        act.push(
            button(S.files.forget, {
                kind: 'ghost',
                onclick: () =>
                    call('transfer_forget', { transferId: t.id }).then(() => {
                        transfers.delete(t.id);

                        if (live) {
                            live.paintTransfers();
                        }
                    }),
            }),
        );
    }

    const progress = el('div', {
        class: 'progress',
        role: 'progressbar',
        'aria-valuemin': '0',
        'aria-valuemax': '100',
        'aria-valuenow': String(pct),
        'aria-label': S.files.progressOf(t.name),
    });
    progress.append(bar(t.status === 'done' ? 100 : pct));

    return el(
        'li',
        { class: 'transfer' },
        el(
            'div',
            { class: 'row between wrap' },
            el(
                'div',
                { class: 'row' },
                dot(tone, t.status === 'active'),
                el('span', { class: 'name', text: t.name }),
            ),
            chip(t.kind === 'upload' ? S.files.up : S.files.down),
        ),
        t.status === 'done' && t.kind === 'download' && t.path
            ? el(
                  'div',
                  { class: 'small' },
                  el('span', { text: S.files.savedTo }),
                  ' ',
                  el('span', { class: 'mono path', text: t.path }),
              )
            : null,
        t.status === 'done' && t.kind === 'upload'
            ? el('div', { class: 'small', text: S.files.moderation })
            : null,
        t.status !== 'done' || t.kind === 'download' ? progress : null,
        el('div', {
            class: 'small ' + (t.status === 'error' ? 'err-text' : 'muted'),
            text: t.status === 'done' ? '' : describe(t),
        }),
        el('div', { class: 'row wrap' }, ...act),
    );
}

export function mount(box, { back } = {}) {
    const slug = eventSlug();
    const head = el(
        'div',
        { class: 'page-head' },
        el(
            'div',
            { class: 'row' },
            button(S.back, { kind: 'ghost', onclick: back }),
            el('h1', { text: S.more.files }),
        ),
    );
    const body = el('div', { class: 'stack' });
    box.append(head, body);

    if (!slug) {
        body.append(noEventState());

        return;
    }

    const quotaBox = el('div', {});
    const dropzone = el('div', {
        class: 'dropzone',
        role: 'group',
        'aria-label': S.files.uploadTitle,
    });
    const transferBox = el('div', {});
    const listBox = el('div', { class: 'stack-sm' });
    const upMsg = el('div', {
        class: 'inline-error',
        role: 'alert',
        hidden: true,
    });

    const upload = async (paths) => {
        upMsg.hidden = true;
        const r = await call('upload_files', { event: slug, paths });

        if (!r.ok) {
            upMsg.hidden = false;
            upMsg.textContent = plainMessage(r.error);

            return;
        }

        const bad = r.value.filter((x) => x.error);

        if (bad.length) {
            upMsg.hidden = false;
            upMsg.textContent = bad.map((x) => x.error.message).join(' ');
        }

        const seed = await call('list_transfers');

        if (seed.ok) {
            for (const t of seed.value) {
                transfers.set(t.id, t);
            }
        }

        paintTransfers();
        refreshQuota();
    };

    const pick = async () => {
        const r = await call('pick_files');

        if (r.ok && r.value.length) {
            upload(r.value);
        } else if (!r.ok) {
            upMsg.hidden = false;
            upMsg.textContent = plainMessage(r.error);
        }
    };

    function paintQuota() {
        clear(quotaBox);
        const d = files.data;

        if (!d || !d.quota) {
            return;
        }

        const q = d.quota;
        const pct = q.cap_bytes > 0 ? (q.used_bytes / q.cap_bytes) * 100 : 0;
        quotaBox.append(
            el(
                'div',
                { class: 'quota', role: 'group', 'aria-label': S.files.quota },
                el(
                    'div',
                    { class: 'row between small' },
                    el('span', { text: S.files.quota }),
                    el('span', {
                        class: 'mono',
                        text: S.files.quotaOf(
                            bytes(q.used_bytes),
                            bytes(q.cap_bytes),
                        ),
                    }),
                ),
                bar(pct),
                pct >= 90
                    ? el('div', {
                          class: 'small warn-text',
                          text: S.files.quotaLow(bytes(q.remaining_bytes)),
                      })
                    : null,
            ),
        );
    }

    function paintDrop() {
        clear(dropzone);
        const up = files.data && files.data.upload;

        if (files.data && !(up && up.resumable)) {
            dropzone.append(
                el('div', { class: 'small muted', text: S.files.noUpload }),
            );

            return;
        }

        dropzone.append(
            el('div', { class: 'strong', text: S.files.dropHere }),
            el('div', {
                class: 'small muted',
                text: S.files.dropHint(up ? bytes(up.max_file_bytes) : ''),
            }),
            el(
                'div',
                { class: 'row' },
                button(S.files.pick, { kind: 'primary-soft', onclick: pick }),
            ),
            upMsg,
        );
    }

    function paintTransfers() {
        clear(transferBox);
        const list = [...transfers.values()].sort((a, b) => b.id - a.id);

        if (!list.length) {
            return;
        }

        transferBox.append(
            el('div', { class: 'group-title', text: S.files.transfers }),
            el('ul', { class: 'transfers' }, ...list.map(transferRow)),
        );
    }

    function paintList() {
        clear(listBox);

        if (files.phase === 'loading') {
            listBox.append(skeleton(3));

            return;
        }

        if (files.phase === 'error') {
            listBox.append(errorState(files.error, refreshList));

            return;
        }

        if (files.stale) {
            listBox.append(staleHint(refreshList));
        }

        const list = files.data.files || [];

        if (!list.length) {
            listBox.append(emptyState(S.files.none, S.files.noneHint));

            return;
        }

        for (const f of list) {
            listBox.append(fileRow(f));
        }
    }

    function fileRow(f) {
        const pending = f.visibility === 'pending';
        const remove = f.can_delete
            ? el('button', {
                  class: 'btn danger',
                  type: 'button',
                  text: S.files.delete,
                  'aria-label': S.files.deleteAria(f.name),
                  onclick: async (ev) => {
                      const b = ev.currentTarget;

                      if (b.dataset.armed !== '1') {
                          b.dataset.armed = '1';
                          b.textContent = S.files.sure;
                          setTimeout(() => {
                              b.dataset.armed = '0';
                              b.textContent = S.files.delete;
                          }, 4000);

                          return;
                      }

                      b.disabled = true;
                      const r = await call('delete_file', { fileId: f.id });

                      if (!r.ok) {
                          b.disabled = false;
                          toast(plainMessage(r.error), 'error');

                          return;
                      }

                      toast(S.files.deleted(f.name));
                      refreshList();
                  },
              })
            : null;

        return el(
            'div',
            { class: 'file-row' },
            el(
                'div',
                { class: 'grow' },
                el('div', { class: 'name', text: f.name }),
                el('div', {
                    class: 'small muted',
                    text: [
                        bytes(f.size_bytes),
                        f.mine ? S.files.you : f.uploader_name,
                        dayTime(f.created_at),
                    ]
                        .filter(Boolean)
                        .join(' · '),
                }),
                pending
                    ? el(
                          'div',
                          { class: 'small' },
                          chip(S.files.moderation, 'warn'),
                      )
                    : null,
            ),
            f.can_download
                ? el('button', {
                      class: 'btn primary-soft',
                      type: 'button',
                      text: S.files.download,
                      'aria-label': S.files.downloadAria(f.name),
                      onclick: async (ev) => {
                          const b = ev.currentTarget;
                          b.disabled = true;
                          const r = await call('download_file', {
                              fileId: f.id,
                              name: f.name,
                          });
                          b.disabled = false;

                          if (!r.ok) {
                              toast(plainMessage(r.error), 'error');

                              return;
                          }

                          say(S.files.downloadStarted(f.name));
                          const seed = await call('list_transfers');

                          if (seed.ok) {
                              for (const t of seed.value) {
                                  transfers.set(t.id, t);
                              }
                          }

                          paintTransfers();
                      },
                  })
                : null,
            remove,
        );
    }

    async function refreshQuota() {
        const r = await call('files_quota', { event: slug });

        if (r.ok && files.data) {
            files.data = {
                ...files.data,
                quota: r.value.quota,
                upload: r.value.upload,
            };
            paintQuota();
        }
    }

    async function refreshList() {
        if (files.phase !== 'ready') {
            files.phase = 'loading';
            paintList();
        }

        const r = await call('list_files', { event: slug });

        if (r.ok) {
            files = {
                phase: 'ready',
                data: r.value,
                error: null,
                stale: false,
            };
        } else if (files.phase !== 'ready') {
            files = { phase: 'error', data: null, error: r.error };
        } else {
            files = { ...files, stale: true };
        }

        paintQuota();
        paintDrop();
        paintList();
    }

    live = { dropzone, upload, paintTransfers, refreshList };
    body.append(
        quotaBox,
        dropzone,
        transferBox,
        el('div', { class: 'group-title', text: S.files.shared }),
        listBox,
    );
    paintDrop();
    paintTransfers();
    paintList();
    call('list_transfers').then((r) => {
        if (r.ok) {
            for (const t of r.value) {
                transfers.set(t.id, t);
            }

            paintTransfers();
        }
    });
    refreshList();
}
