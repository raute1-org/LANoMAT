//! Everything the UI can do besides voice: events, play, polls, catering, files, chat,
//! notifications. Plain async methods over `voice-core` with no Tauri types in them (events go
//! out through an [`EventSink`]), so they are unit-tested here; the Tauri commands in `lib.rs`
//! are one-line wrappers, documented in `docs/commands.md`.

use std::collections::BTreeMap;
use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};

use serde::Serialize;
use serde_json::{json, Value};
use time::format_description::well_known::Rfc3339;
use time::OffsetDateTime;
use voice_core::api::{
    ApiClient, Catering, ChatMessage, EventSummary, EventsResponse, FilesOverview, GameServer,
    Match, MessagesPage, MineResponse, MyItem, Notification, NotificationsPage, NowCard, NowFeed,
    PollDetail, PollsList, QuotaInfo, Tournament,
};
use voice_core::notify::{
    Category, Incoming, NotificationEngine, Notifier, NotifyConfig, NotifyEvent, SeenState,
    SeenStore,
};
use voice_core::realtime::{self, ChatEvent, LinkState, RealtimeHandle, RealtimeOptions};
use voice_core::transfer::default_retry_delays;
use voice_core::transfer::manager::{TransferManager, TransferView};
use voice_core::transfer::store::UploadStore;
use voice_core::Error;

use crate::engine::{german, Engine, UiError};
use crate::settings::Settings;

/// Realtime chat events (`ChatEvent`), including link status changes.
pub const EVT_CHAT: &str = "chat-event";
/// A file transfer changed (`TransferView`).
pub const EVT_TRANSFER: &str = "transfer-updated";
/// Notification engine events (`NotifyEvent`): unread count, delivered system notification.
pub const EVT_NOTIFY: &str = "notify-event";
/// The manual "Ruhe" toggle changed: `{quiet: bool}`.
pub const EVT_QUIET: &str = "quiet-changed";
/// The server rejected the token: the UI must send the person to "Verbinden".
pub const EVT_AUTH: &str = "auth-expired";

/// Sends an event to every window: `(name, payload)`.
pub type EventSink = Arc<dyn Fn(&str, Value) + Send + Sync>;

#[derive(Clone, Default)]
pub struct FeatureOptions {
    pub realtime: RealtimeOptions,
    pub notify: NotifyConfig,
    /// Retry schedule of the transfers (`None`: the web app's).
    pub retry_delays: Option<Vec<std::time::Duration>>,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

/// `GET config` as the UI needs it: which modules run, whether voice and realtime are ready.
#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct ConfigView {
    pub api_version: u32,
    pub voice_ready: bool,
    /// Module key to enabled. A key that is missing means "unknown": show the feature.
    pub modules: BTreeMap<String, bool>,
    /// Realtime chat is configured (else the client polls).
    pub realtime: bool,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct TransferStarted {
    pub transfer_id: u64,
}

/// One entry of the answer of `upload_files`: started, or why not.
#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct UploadStarted {
    pub path: String,
    pub transfer_id: Option<u64>,
    pub error: Option<UiError>,
}

struct ChatLink {
    handle: RealtimeHandle,
    forwarder: tokio::task::JoinHandle<()>,
}

impl Drop for ChatLink {
    fn drop(&mut self) {
        self.handle.shutdown();
        self.forwarder.abort();
    }
}

struct Background {
    engine: Arc<NotificationEngine>,
    task: Option<tokio::task::JoinHandle<()>>,
}

struct SettingsSeen(Arc<Engine>);

impl SeenStore for SettingsSeen {
    fn load(&self) -> SeenState {
        self.0.settings().notify_seen
    }
    fn save(&self, state: &SeenState) {
        let s = state.clone();
        self.0.update_settings(|st| st.notify_seen = s);
    }
}

pub struct Features {
    engine: Arc<Engine>,
    emit: EventSink,
    notifier: Arc<dyn Notifier>,
    transfers: TransferManager,
    opts: FeatureOptions,
    background: Mutex<Option<Background>>,
    chat: Mutex<Option<ChatLink>>,
    me: Mutex<Option<voice_core::api::Me>>,
    quiet: AtomicBool,
    talking: AtomicBool,
    focused: AtomicBool,
}

/// Category overrides from the settings (unknown keys are ignored).
pub fn category_overrides(s: &Settings) -> BTreeMap<Category, bool> {
    s.notify_categories
        .iter()
        .filter_map(|(k, v)| {
            Category::ALL
                .iter()
                .find(|c| c.as_str() == k)
                .map(|c| (*c, *v))
        })
        .collect()
}

/// Whether `body` mentions `name` as `@name` (case-insensitive, ending at a word boundary).
pub fn is_mention(body: &str, name: &str) -> bool {
    let name = name.trim().to_lowercase();
    if name.is_empty() {
        return false;
    }
    let body = body.to_lowercase();
    let needle = format!("@{name}");
    let mut from = 0;
    while let Some(i) = body[from..].find(&needle) {
        let end = from + i + needle.len();
        let boundary = body[end..]
            .chars()
            .next()
            .is_none_or(|c| !(c.is_alphanumeric() || c == '_'));
        if boundary {
            return true;
        }
        from = end;
    }
    false
}

/// A client-side notification for an open vote of mine that closes within `within_secs`.
pub fn vote_closing_soon(
    card: &NowCard,
    now: OffsetDateTime,
    within_secs: i64,
) -> Option<Incoming> {
    if card.kind != "vote" || card.state != "open" {
        return None;
    }
    let closes = OffsetDateTime::parse(card.at.as_deref()?, &Rfc3339).ok()?;
    let left = (closes - now).whole_seconds();
    if left <= 0 || left > within_secs {
        return None;
    }
    let poll_id = card.data.get("pollId").and_then(Value::as_u64)?;
    let minutes = (left + 59) / 60;
    Some(Incoming {
        id: format!("vote:{poll_id}"),
        category: Category::Vote,
        title: "Eine Abstimmung endet gleich".into(),
        body: Some(format!(
            "{} (noch {} Min.)",
            card.data
                .get("question")
                .and_then(Value::as_str)
                .unwrap_or(&card.title),
            minutes
        )),
    })
}

impl Features {
    pub fn new(
        engine: Arc<Engine>,
        emit: EventSink,
        notifier: Arc<dyn Notifier>,
        opts: FeatureOptions,
    ) -> Result<Arc<Self>, UiError> {
        let cfg_dir = engine
            .settings_path()
            .parent()
            .map(PathBuf::from)
            .unwrap_or_else(|| PathBuf::from("."));
        let store = Arc::new(UploadStore::open(cfg_dir.join("uploads.json")));
        let sink_emit = emit.clone();
        let transfers = TransferManager::new(
            store,
            Arc::new(move |v: &TransferView| {
                sink_emit(EVT_TRANSFER, serde_json::to_value(v).unwrap_or(Value::Null));
            }),
            Arc::new(german),
            opts.retry_delays
                .clone()
                .unwrap_or_else(default_retry_delays),
            vec![cfg_dir],
        )?;
        Ok(Arc::new(Self {
            engine,
            emit,
            notifier,
            transfers,
            opts,
            background: Mutex::new(None),
            chat: Mutex::new(None),
            me: Mutex::new(None),
            quiet: AtomicBool::new(false),
            talking: AtomicBool::new(false),
            focused: AtomicBool::new(true),
        }))
    }

    // ---- plumbing ----

    fn api(&self) -> Result<ApiClient, UiError> {
        self.engine.client().map_err(UiError::from)
    }

    /// Maps a core error for the UI. A rejected token also stops the background work and tells
    /// every window (`auth-expired`).
    fn fail(&self, e: Error) -> UiError {
        if matches!(e, Error::Unauthorized) {
            self.stop_all();
            (self.emit)(EVT_AUTH, json!({}));
        }
        UiError::from(e)
    }

    async fn me(&self) -> Option<voice_core::api::Me> {
        if let Some(m) = lock(&self.me).clone() {
            return Some(m);
        }
        let me = self.api().ok()?.me().await.ok()?;
        *lock(&self.me) = Some(me.clone());
        Some(me)
    }

    // ---- lifecycle ----

    /// Starts the notification poll loop (idempotent). Called once the device is connected.
    pub fn ensure_started(self: &Arc<Self>) {
        let Ok(engine) = self.notification_engine() else {
            return;
        };
        let mut bg = lock(&self.background);
        if let Some(b) = bg.as_mut() {
            if b.task.is_none() {
                b.task = Some(engine.spawn());
            }
        }
    }

    /// The notification engine, created on first use (offers from chat and the "now" feed work
    /// before the poll loop starts).
    fn notification_engine(self: &Arc<Self>) -> Result<Arc<NotificationEngine>, UiError> {
        let mut bg = lock(&self.background);
        if let Some(b) = bg.as_ref() {
            return Ok(b.engine.clone());
        }
        let ev = self.emit.clone();
        let engine = NotificationEngine::new(
            self.api()?,
            self.notifier.clone(),
            Arc::new(SettingsSeen(self.engine.clone())),
            Arc::new(move |e: NotifyEvent| {
                ev(EVT_NOTIFY, serde_json::to_value(&e).unwrap_or(Value::Null));
            }),
            self.opts.notify.clone(),
            category_overrides(&self.engine.settings()),
        );
        engine.set_quiet(self.quiet.load(Ordering::SeqCst));
        engine.set_talking(self.talking.load(Ordering::SeqCst));
        engine.set_focused(self.focused.load(Ordering::SeqCst));
        *bg = Some(Background {
            engine: engine.clone(),
            task: None,
        });
        Ok(engine)
    }

    /// Stops notifications and chat (logout, or the token was rejected).
    pub fn stop_all(&self) {
        if let Some(b) = lock(&self.background).take() {
            b.engine.stop();
            if let Some(t) = b.task {
                t.abort();
            }
        }
        *lock(&self.chat) = None;
        *lock(&self.me) = None;
    }

    pub fn apply_settings(&self, s: &Settings) {
        if let Some(b) = lock(&self.background).as_ref() {
            b.engine.set_overrides(category_overrides(s));
        }
    }

    pub fn set_focused(&self, focused: bool) {
        self.focused.store(focused, Ordering::SeqCst);
        if let Some(b) = lock(&self.background).as_ref() {
            b.engine.set_focused(focused);
        }
    }

    /// Push-to-talk held / the user talks: no system notifications meanwhile.
    pub fn set_talking(&self, talking: bool) {
        self.talking.store(talking, Ordering::SeqCst);
        if let Some(b) = lock(&self.background).as_ref() {
            b.engine.set_talking(talking);
        }
    }

    pub fn set_quiet(&self, quiet: bool) -> bool {
        self.quiet.store(quiet, Ordering::SeqCst);
        if let Some(b) = lock(&self.background).as_ref() {
            b.engine.set_quiet(quiet);
        }
        (self.emit)(EVT_QUIET, json!({ "quiet": quiet }));
        quiet
    }

    pub fn quiet(&self) -> bool {
        self.quiet.load(Ordering::SeqCst)
    }

    // ---- config, events ----

    pub async fn config(&self) -> Result<ConfigView, UiError> {
        let c = self.api()?.config().await.map_err(|e| self.fail(e))?;
        Ok(ConfigView {
            api_version: c.api_version,
            voice_ready: c.voice_ready,
            realtime: c.realtime.is_some(),
            modules: c.modules,
        })
    }

    pub async fn events(&self) -> Result<EventsResponse, UiError> {
        self.api()?.events().await.map_err(|e| self.fail(e))
    }

    pub async fn current_event(&self) -> Result<Option<EventSummary>, UiError> {
        self.api()?.current_event().await.map_err(|e| self.fail(e))
    }

    // ---- play ----

    pub async fn now(self: &Arc<Self>, event: &str) -> Result<NowFeed, UiError> {
        let feed = self.api()?.now(event).await.map_err(|e| self.fail(e))?;
        // A vote of mine that closes within ten minutes becomes a (grouped, quiet-aware) system
        // notification, once per poll.
        if let Ok(engine) = self.notification_engine() {
            let now = OffsetDateTime::now_utc();
            for card in feed.cards.iter().chain(feed.more.iter()) {
                if let Some(n) = vote_closing_soon(card, now, 600) {
                    engine.offer(n);
                }
            }
        }
        Ok(feed)
    }

    pub async fn tournaments(&self, event: &str) -> Result<Vec<Tournament>, UiError> {
        self.api()?
            .tournaments(event)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn my_matches(&self, event: &str) -> Result<Vec<Match>, UiError> {
        self.api()?
            .my_matches(event)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn tournament_checkin(&self, id: u64) -> Result<Tournament, UiError> {
        self.api()?
            .tournament_checkin(id)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn match_report(&self, id: u64, score1: u32, score2: u32) -> Result<Match, UiError> {
        self.api()?
            .match_report(id, score1, score2)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn match_confirm(&self, id: u64, lock_version: u64) -> Result<Match, UiError> {
        self.api()?
            .match_confirm(id, lock_version)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn match_dispute(&self, id: u64) -> Result<Match, UiError> {
        self.api()?
            .match_dispute(id)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn match_withdraw_report(
        &self,
        id: u64,
        lock_version: u64,
    ) -> Result<Match, UiError> {
        self.api()?
            .match_withdraw_report(id, lock_version)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn event_servers(&self, event: &str) -> Result<Vec<GameServer>, UiError> {
        self.api()?
            .event_servers(event)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn permanent_servers(&self) -> Result<Vec<GameServer>, UiError> {
        self.api()?
            .permanent_servers()
            .await
            .map_err(|e| self.fail(e))
    }

    // ---- polls, catering ----

    pub async fn polls(&self, event: &str) -> Result<PollsList, UiError> {
        self.api()?.polls(event).await.map_err(|e| self.fail(e))
    }

    pub async fn poll(&self, id: u64) -> Result<PollDetail, UiError> {
        self.api()?.poll(id).await.map_err(|e| self.fail(e))
    }

    pub async fn vote(&self, poll_id: u64, option_id: u64) -> Result<PollDetail, UiError> {
        self.api()?
            .vote(poll_id, option_id)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn catering(&self, event: &str) -> Result<Catering, UiError> {
        self.api()?.catering(event).await.map_err(|e| self.fail(e))
    }

    pub async fn catering_mine(&self, event: &str) -> Result<MineResponse, UiError> {
        self.api()?
            .catering_mine(event)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn place_food_item(
        &self,
        order_id: u64,
        option_key: &str,
        note: Option<&str>,
    ) -> Result<MyItem, UiError> {
        self.api()?
            .place_food_item(order_id, option_key, note)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn cancel_food_item(&self, item_id: u64) -> Result<(), UiError> {
        self.api()?
            .cancel_food_item(item_id)
            .await
            .map_err(|e| self.fail(e))
    }

    // ---- files and transfers ----

    pub async fn files(&self, event: &str) -> Result<FilesOverview, UiError> {
        self.api()?.files(event).await.map_err(|e| self.fail(e))
    }

    pub async fn files_quota(&self, event: &str) -> Result<QuotaInfo, UiError> {
        self.api()?
            .files_quota(event)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn delete_file(&self, id: u64) -> Result<(), UiError> {
        self.api()?.delete_file(id).await.map_err(|e| self.fail(e))
    }

    /// Where downloads go: the setting, else the platform's download folder.
    pub fn download_dir(&self) -> PathBuf {
        effective_download_dir(&self.engine.settings())
    }

    /// Starts a download into the download folder; progress arrives as `transfer-updated`.
    pub async fn download_file(
        &self,
        file_id: u64,
        name_hint: &str,
    ) -> Result<TransferStarted, UiError> {
        let api = self.api()?;
        let id =
            self.transfers
                .start_download(api, file_id, name_hint.to_owned(), self.download_dir());
        Ok(TransferStarted { transfer_id: id })
    }

    /// Starts an upload per path; a path that is refused (not a file, the app's own files)
    /// is reported in its entry, the others still start.
    pub async fn upload_files(
        &self,
        event: &str,
        paths: Vec<String>,
    ) -> Result<Vec<UploadStarted>, UiError> {
        let api = self.api()?;
        Ok(paths
            .into_iter()
            .map(|p| {
                match self.transfers.start_upload(
                    api.clone(),
                    event.to_owned(),
                    std::path::Path::new(&p),
                ) {
                    Ok(id) => UploadStarted {
                        path: p,
                        transfer_id: Some(id),
                        error: None,
                    },
                    Err(e) => UploadStarted {
                        path: p,
                        transfer_id: None,
                        error: Some(UiError::from(e)),
                    },
                }
            })
            .collect())
    }

    pub fn transfers(&self) -> Vec<TransferView> {
        self.transfers.list()
    }

    pub fn transfer_pause(&self, id: u64) -> Result<(), UiError> {
        Ok(self.transfers.pause(id)?)
    }

    pub fn transfer_resume(&self, id: u64) -> Result<(), UiError> {
        Ok(self.transfers.resume(id)?)
    }

    pub fn transfer_cancel(&self, id: u64) -> Result<(), UiError> {
        Ok(self.transfers.cancel(id)?)
    }

    pub fn transfer_forget(&self, id: u64) {
        self.transfers.forget(id);
    }

    // ---- notifications (the bell list) ----

    pub async fn notifications(
        &self,
        unread_only: bool,
        page: u32,
        per_page: u32,
    ) -> Result<NotificationsPage, UiError> {
        self.api()?
            .notifications(unread_only, page, per_page)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn unread_count(&self) -> Result<u64, UiError> {
        self.api()?.unread_count().await.map_err(|e| self.fail(e))
    }

    pub async fn mark_notification_read(&self, id: &str) -> Result<Notification, UiError> {
        self.api()?
            .mark_notification_read(id)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn mark_all_notifications_read(&self) -> Result<u64, UiError> {
        self.api()?
            .mark_all_notifications_read()
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn notification_preferences(&self) -> Result<BTreeMap<String, bool>, UiError> {
        self.api()?
            .notification_preferences()
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn set_notification_preferences(
        &self,
        changes: BTreeMap<String, bool>,
    ) -> Result<BTreeMap<String, bool>, UiError> {
        self.api()?
            .set_notification_preferences(&changes)
            .await
            .map_err(|e| self.fail(e))
    }

    // ---- chat ----

    pub async fn chat_history(
        &self,
        room_id: u64,
        before: Option<u64>,
    ) -> Result<MessagesPage, UiError> {
        self.api()?
            .messages(room_id, before)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn chat_post(&self, room_id: u64, body: &str) -> Result<ChatMessage, UiError> {
        let body = body.trim();
        if body.is_empty() {
            return Err(UiError::new("validation", "Schreibe zuerst etwas."));
        }
        if body.chars().count() > 500 {
            return Err(UiError::new(
                "validation",
                "Eine Nachricht darf höchstens 500 Zeichen lang sein.",
            ));
        }
        self.api()?
            .post_message(room_id, body)
            .await
            .map_err(|e| self.fail(e))
    }

    pub async fn chat_delete(&self, message_id: u64) -> Result<(), UiError> {
        self.api()?
            .delete_message(message_id)
            .await
            .map_err(|e| self.fail(e))
    }

    /// Follows the text chat of these rooms (replacing the previous set; empty follows only the
    /// room directory). Starts the realtime link on first use; events arrive as `chat-event`.
    pub async fn chat_subscribe(self: &Arc<Self>, room_ids: Vec<u64>) -> Result<(), UiError> {
        if let Some(link) = lock(&self.chat).as_ref() {
            link.handle.set_rooms(room_ids);
            return Ok(());
        }
        let api = self.api()?;
        let realtime_cfg = api.config().await.map_err(|e| self.fail(e))?.realtime;
        let me = self.me().await;
        let (handle, mut rx) = realtime::spawn(api, realtime_cfg, self.opts.realtime.clone());
        handle.set_rooms(room_ids);
        let me_for_task = Arc::clone(self);
        let forwarder = tokio::spawn(async move {
            while let Some(ev) = rx.recv().await {
                if let ChatEvent::MessagePosted { message, .. } = &ev {
                    if let Some(me) = &me {
                        if message.author.id != me.id && is_mention(&message.body, &me.name) {
                            if let Ok(engine) = me_for_task.notification_engine() {
                                engine.offer(Incoming {
                                    id: format!("chat:{}", message.id),
                                    category: Category::Direct,
                                    title: format!("{} hat dich erwähnt", message.author.name),
                                    body: Some(message.body.clone()),
                                });
                            }
                        }
                    }
                }
                if matches!(
                    &ev,
                    ChatEvent::Status {
                        state: LinkState::Offline,
                        ..
                    }
                ) {
                    (me_for_task.emit)(EVT_AUTH, json!({}));
                }
                (me_for_task.emit)(EVT_CHAT, serde_json::to_value(&ev).unwrap_or(Value::Null));
            }
        });
        let mut slot = lock(&self.chat);
        if slot.is_some() {
            // Someone else won the race; ours is dropped (and shut down) here.
            drop(ChatLink { handle, forwarder });
        } else {
            *slot = Some(ChatLink { handle, forwarder });
        }
        Ok(())
    }

    pub fn chat_unsubscribe(&self) {
        *lock(&self.chat) = None;
    }
}

/// The settings' download folder, else the platform's, else the home folder, else the temp dir.
pub fn effective_download_dir(s: &Settings) -> PathBuf {
    if let Some(d) = s.download_dir.as_deref().filter(|d| !d.is_empty()) {
        return PathBuf::from(d);
    }
    directories::UserDirs::new()
        .and_then(|u| u.download_dir().map(PathBuf::from))
        .or_else(|| directories::UserDirs::new().map(|u| u.home_dir().join("Downloads")))
        .unwrap_or_else(std::env::temp_dir)
}

#[cfg(test)]
mod tests;
