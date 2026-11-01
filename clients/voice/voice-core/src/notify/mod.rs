//! Notifications engine: calm polling of `notifications/unread-count`, fetching what is new, and
//! the pure [`policy`] that decides what becomes a system notification. The OS delivery sits
//! behind the [`Notifier`] trait (Tauri's notification plugin in the app, a recording fake in
//! tests).

pub mod policy;
pub mod seen;

use std::collections::{BTreeMap, VecDeque};
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};
use std::time::{Duration, Instant};

use serde::Serialize;
use tokio_util::sync::CancellationToken;

use crate::api::ApiClient;
use crate::error::{Error, Result};
pub use policy::{Category, Decision, Dnd, Incoming, Policy, Reason, SystemNotification, Target};
pub use seen::SeenState;

/// Delivers a system notification. Implementations must not block for long.
pub trait Notifier: Send + Sync {
    fn notify(&self, n: &SystemNotification) -> std::result::Result<(), String>;
}

/// Where the de-duplication state lives (the settings file in the app).
pub trait SeenStore: Send + Sync {
    fn load(&self) -> SeenState;
    fn save(&self, state: &SeenState);
}

/// What the engine tells the UI layer.
#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum NotifyEvent {
    /// The unread count changed (also sent once at the start).
    Unread { count: u64 },
    /// A system notification was raised (the UI may show its own toast or badge too).
    Delivered { notification: SystemNotification },
    /// The token was rejected: the engine stopped, the app must log in again.
    AuthExpired,
}

pub type Sink = Arc<dyn Fn(NotifyEvent) + Send + Sync>;

#[derive(Debug, Clone)]
pub struct NotifyConfig {
    /// Poll period while the window has the focus.
    pub focused_interval: Duration,
    /// Poll period while it does not (the server recommends 30 to 60 s).
    pub unfocused_interval: Duration,
    pub max_backoff: Duration,
    /// Refetch the list at least every N ticks even when the count did not change (a read plus
    /// a new one keeps the count equal).
    pub full_refresh_every: u32,
}

impl Default for NotifyConfig {
    fn default() -> Self {
        Self {
            focused_interval: Duration::from_secs(15),
            unfocused_interval: Duration::from_secs(45),
            max_backoff: Duration::from_secs(300),
            full_refresh_every: 10,
        }
    }
}

/// The delay before the next poll: the base interval, doubled per consecutive failure, capped.
pub fn next_delay(cfg: &NotifyConfig, focused: bool, failures: u32) -> Duration {
    let base = if focused {
        cfg.focused_interval
    } else {
        cfg.unfocused_interval
    };
    if failures == 0 {
        return base;
    }
    let factor = 1u32.checked_shl(failures.min(10)).unwrap_or(u32::MAX);
    base.saturating_mul(factor).min(cfg.max_backoff)
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

pub struct NotificationEngine {
    api: ApiClient,
    notifier: Arc<dyn Notifier>,
    store: Arc<dyn SeenStore>,
    sink: Sink,
    cfg: NotifyConfig,
    policy: Mutex<Policy>,
    seen: Mutex<SeenState>,
    dnd: Mutex<Dnd>,
    focused: AtomicBool,
    last_count: Mutex<Option<u64>>,
    offered: Mutex<VecDeque<String>>,
    ticks: AtomicU32,
    failures: AtomicU32,
    started: Instant,
    cancel: CancellationToken,
}

impl NotificationEngine {
    pub fn new(
        api: ApiClient,
        notifier: Arc<dyn Notifier>,
        store: Arc<dyn SeenStore>,
        sink: Sink,
        cfg: NotifyConfig,
        overrides: BTreeMap<Category, bool>,
    ) -> Arc<Self> {
        let seen = store.load();
        Arc::new(Self {
            api,
            notifier,
            store,
            sink,
            cfg,
            policy: Mutex::new(Policy::new(overrides)),
            seen: Mutex::new(seen),
            dnd: Mutex::new(Dnd::default()),
            focused: AtomicBool::new(true),
            last_count: Mutex::new(None),
            offered: Mutex::new(VecDeque::new()),
            ticks: AtomicU32::new(0),
            failures: AtomicU32::new(0),
            started: Instant::now(),
            cancel: CancellationToken::new(),
        })
    }

    fn now(&self) -> Duration {
        self.started.elapsed()
    }

    pub fn set_overrides(&self, overrides: BTreeMap<Category, bool>) {
        lock(&self.policy).set_overrides(overrides);
    }

    pub fn set_focused(&self, focused: bool) {
        self.focused.store(focused, Ordering::SeqCst);
    }

    /// Push-to-talk held / the user talks.
    pub fn set_talking(&self, talking: bool) {
        lock(&self.dnd).talking = talking;
    }

    /// The manual "Ruhe" toggle.
    pub fn set_quiet(&self, quiet: bool) {
        lock(&self.dnd).manual = quiet;
    }

    pub fn quiet(&self) -> bool {
        lock(&self.dnd).manual
    }

    pub fn stop(&self) {
        self.cancel.cancel();
    }

    fn emit(&self, e: NotifyEvent) {
        (self.sink)(e);
    }

    fn deliver(&self, n: SystemNotification) {
        if let Err(e) = self.notifier.notify(&n) {
            tracing::warn!("system notification failed: {e}");
        }
        self.emit(NotifyEvent::Delivered { notification: n });
    }

    /// Offers a client-side notification (a chat mention, a vote closing soon). `incoming.id` is
    /// the de-duplication key (for example `chat:412` or `vote:3`); it goes through the same
    /// policy as the server's.
    pub fn offer(&self, incoming: Incoming) {
        {
            let mut offered = lock(&self.offered);
            if offered.iter().any(|i| *i == incoming.id) {
                return;
            }
            offered.push_back(incoming.id.clone());
            while offered.len() > 500 {
                offered.pop_front();
            }
        }
        self.handle(&incoming);
    }

    fn handle(&self, incoming: &Incoming) {
        let dnd = *lock(&self.dnd);
        let decision = lock(&self.policy).decide(self.now(), incoming, dnd);
        if let Decision::Show(n) = decision {
            self.deliver(n);
        }
    }

    /// Emits grouped summaries that are due.
    pub fn flush(&self) {
        let dnd = *lock(&self.dnd);
        let due = lock(&self.policy).flush(self.now(), dnd);
        for n in due {
            self.deliver(n);
        }
    }

    /// One poll round: the unread count, and the list when something may be new.
    pub async fn tick(&self) -> Result<()> {
        let tick_no = self.ticks.fetch_add(1, Ordering::SeqCst);
        let count = self.api.unread_count().await?;
        let changed = {
            let mut last = lock(&self.last_count);
            let changed = *last != Some(count);
            *last = Some(count);
            changed
        };
        if changed {
            self.emit(NotifyEvent::Unread { count });
        }
        let refresh = changed || tick_no.is_multiple_of(self.cfg.full_refresh_every.max(1));
        let baselined = lock(&self.seen).baselined;
        if count == 0 && baselined {
            return Ok(());
        }
        if !refresh && baselined {
            return Ok(());
        }
        let page = self.api.notifications(true, 1, 50).await?;
        let mut items = page.notifications;
        items.sort_by(|a, b| a.created_at.cmp(&b.created_at));

        let first_look = !baselined;
        let mut dirty = first_look;
        for n in items {
            let is_new = lock(&self.seen).is_new(&n.id, &n.created_at);
            if !is_new {
                continue;
            }
            {
                let mut seen = lock(&self.seen);
                seen.mark(&n.id, &n.created_at);
                dirty = true;
            }
            if first_look {
                continue; // baseline: record only
            }
            self.handle(&Incoming {
                id: n.id,
                category: Category::from_server(&n.category),
                title: n.title,
                body: n.body.filter(|b| !b.trim().is_empty()),
            });
        }
        if first_look {
            lock(&self.seen).baselined = true;
        }
        if dirty {
            let snapshot = lock(&self.seen).clone();
            self.store.save(&snapshot);
        }
        Ok(())
    }

    /// Runs the poll loop until [`stop`](Self::stop) or until the token is rejected.
    pub fn spawn(self: &Arc<Self>) -> tokio::task::JoinHandle<()> {
        let me = self.clone();
        tokio::spawn(async move {
            loop {
                match me.tick().await {
                    Ok(()) => me.failures.store(0, Ordering::SeqCst),
                    Err(Error::Unauthorized) => {
                        me.emit(NotifyEvent::AuthExpired);
                        return;
                    }
                    Err(e) => {
                        tracing::debug!("notification poll failed: {e}");
                        me.failures.fetch_add(1, Ordering::SeqCst);
                    }
                }
                me.flush();
                // Sleep in short steps: a due grouped summary goes out on time, and a change of
                // focus shortens (or lengthens) the wait immediately.
                let mut waited = Duration::ZERO;
                loop {
                    let delay = next_delay(
                        &me.cfg,
                        me.focused.load(Ordering::SeqCst),
                        me.failures.load(Ordering::SeqCst),
                    );
                    if waited >= delay {
                        break;
                    }
                    let step = Duration::from_secs(2).min(delay - waited);
                    tokio::select! {
                        _ = me.cancel.cancelled() => return,
                        _ = tokio::time::sleep(step) => {}
                    }
                    waited += step;
                    me.flush();
                }
            }
        })
    }
}

#[cfg(test)]
mod tests;
