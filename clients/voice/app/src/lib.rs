//! LANoMAT Voice desktop shell: a Tauri v2 window, tray icon and speaking overlay on top of
//! `voice-core`. All behaviour lives in [`engine::Engine`]; this file wires it to Tauri
//! (commands, events, tray, overlay, global shortcut, control socket, clean shutdown).

pub mod audio_check;
pub mod avatar;
pub mod engine;
pub mod features;
pub mod joinurl;
pub mod settings;
pub mod update;
pub mod view;

#[cfg(feature = "selftest")]
mod selftest;

#[cfg(test)]
mod contract_tests;

use std::path::PathBuf;
use std::sync::{Arc, Mutex, OnceLock};
use std::time::Duration;

use serde::Serialize;
use tauri::menu::{Menu, MenuItem};
use tauri::tray::TrayIconBuilder;
use tauri::{
    AppHandle, Emitter, Manager, RunEvent, State, WebviewUrl, WebviewWindowBuilder, WindowEvent,
};
use tauri_plugin_global_shortcut::{GlobalShortcutExt, ShortcutState};
use voice_core::api::{
    Catering, ChatMessage, EventSummary, EventsResponse, FileTokenStore, FilesOverview, GameServer,
    Match, MessagesPage, MineResponse, MyItem, Notification, NotificationsPage, NowFeed,
    PollDetail, PollsList, QuotaInfo, Room, Tournament,
};
use voice_core::control::{self, ControlServer, PttCommand};
use voice_core::notify::{Notifier, SystemNotification};
use voice_core::transfer::manager::TransferView;

use audio_check::AudioCheck;
use engine::{ConnectPoll, ConnectStart, Devices, Engine, Status, UiError};
use features::{ConfigView, FeatureOptions, Features, TransferStarted, UploadStarted};
use settings::Settings;
use view::RoomView;
use voice_core::api::{
    Connection, FollowTargets, FriendRequests, FriendSuggestion, FriendUser, LinkUrl, People,
    PeopleQuery, Profile, ProfileUpdate,
};

type Eng<'a> = State<'a, Arc<Engine>>;
type Feat<'a> = State<'a, Arc<Features>>;

/// Event carrying a [`RoomView`] snapshot to every window.
pub const EVENT_ROOM: &str = "room-updated";
/// Event carrying a [`ShortcutStatus`] after the global shortcut was (re)registered.
pub const EVENT_SHORTCUT: &str = "shortcut-status";

static APP: OnceLock<AppHandle> = OnceLock::new();

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct ShortcutStatus {
    /// A shortcut is registered and listening.
    pub active: bool,
    /// Why not (or a caveat): shown to the person as a hint, never fatal.
    pub message: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SaveResult {
    pub settings: Settings,
    pub shortcut: ShortcutStatus,
}

#[derive(Debug, Clone, Serialize)]
pub struct EnvInfo {
    pub wayland: bool,
    pub synthetic_audio: bool,
    pub settings_path: String,
    pub socket_path: Option<String>,
    /// `None` when the control socket is up, else why not.
    pub socket_error: Option<String>,
    pub shortcut: ShortcutStatus,
    /// The LANoMAT version this client was built for (`dev` for a local build).
    pub version: &'static str,
    /// The git commit of the build (`unknown` for a local build).
    pub commit: &'static str,
}

#[derive(Default)]
struct Runtime {
    socket_path: Option<PathBuf>,
    socket_error: Option<String>,
    shortcut: Option<ShortcutStatus>,
}

struct TrayItems {
    mute: MenuItem<tauri::Wry>,
    quiet: MenuItem<tauri::Wry>,
}

const QUIET_OFF: &str = "Ruhe (keine Benachrichtigungen)";
const QUIET_ON: &str = "Ruhe aufheben";

fn is_wayland() -> bool {
    std::env::var("XDG_SESSION_TYPE").is_ok_and(|v| v.eq_ignore_ascii_case("wayland"))
        || std::env::var_os("WAYLAND_DISPLAY").is_some_and(|v| !v.is_empty())
}

fn rt(app: &AppHandle) -> Arc<Mutex<Runtime>> {
    app.state::<Arc<Mutex<Runtime>>>().inner().clone()
}

// ---- global shortcut (best effort) ----

fn apply_shortcut(app: &AppHandle, engine: &Arc<Engine>, spec: &str) -> ShortcutStatus {
    let gs = app.global_shortcut();
    let _ = gs.unregister_all();
    let status = if spec.is_empty() {
        ShortcutStatus {
            active: false,
            message: None,
        }
    } else {
        let eng = engine.clone();
        let registered = gs.on_shortcut(spec, move |_app, _shortcut, event| {
            let cmd = match event.state() {
                ShortcutState::Pressed => PttCommand::Down,
                ShortcutState::Released => PttCommand::Up,
            };
            let _ = eng.ptt_command(cmd);
        });
        match registered {
            Ok(()) if is_wayland() => ShortcutStatus {
                active: true,
                message: Some(
                    "Unter Wayland darf eine App keine globalen Tasten abfangen; die Taste wirkt \
                     hier höchstens, solange ein XWayland-Fenster den Fokus hat. Binde stattdessen \
                     im Compositor: lanomat-voice ptt down / up."
                        .into(),
                ),
            },
            Ok(()) => ShortcutStatus {
                active: true,
                message: None,
            },
            Err(e) => ShortcutStatus {
                active: false,
                message: Some(format!(
                    "Die globale Taste „{spec}“ konnte nicht registriert werden ({e}). Wähle eine \
                     andere Kombination oder binde im Compositor: lanomat-voice ptt down / up."
                )),
            },
        }
    };
    rt(app).lock().expect("runtime").shortcut = Some(status.clone());
    let _ = app.emit(EVENT_SHORTCUT, &status);
    status
}

// ---- overlay ----

fn create_overlay(app: &AppHandle) -> tauri::Result<()> {
    WebviewWindowBuilder::new(app, "overlay", WebviewUrl::App("overlay.html".into()))
        .title("LANoMAT Voice Overlay")
        .inner_size(260.0, 130.0)
        .position(24.0, 24.0)
        .decorations(false)
        .transparent(true)
        .always_on_top(true)
        .skip_taskbar(true)
        .resizable(false)
        .shadow(false)
        .focused(false)
        .visible(false)
        .build()?;
    Ok(())
}

fn set_overlay(app: &AppHandle, visible: bool) {
    if let Some(w) = app.get_webview_window("overlay") {
        let _ = if visible { w.show() } else { w.hide() };
        if visible {
            let _ = w.set_always_on_top(true);
        }
    }
}

fn show_main(app: &AppHandle) {
    if let Some(w) = app.get_webview_window("main") {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

// ---- commands ----

#[tauri::command]
async fn get_status(engine: Eng<'_>, features: Feat<'_>) -> Result<Status, UiError> {
    let status = engine.status().await;
    if status.state == "connected" {
        features.inner().ensure_started();
    }
    Ok(status)
}

#[tauri::command]
async fn connect_start(engine: Eng<'_>, server_url: String) -> Result<ConnectStart, UiError> {
    engine.connect_start(&server_url).await
}

#[tauri::command]
async fn connect_poll(engine: Eng<'_>, features: Feat<'_>) -> Result<ConnectPoll, UiError> {
    let poll = engine.connect_poll().await;
    if matches!(poll, ConnectPoll::Connected { .. }) {
        features.inner().ensure_started();
    }
    Ok(poll)
}

#[tauri::command]
async fn connect_cancel(engine: Eng<'_>) -> Result<(), UiError> {
    engine.connect_cancel().await;
    Ok(())
}

#[tauri::command]
async fn list_rooms(engine: Eng<'_>) -> Result<Vec<Room>, UiError> {
    engine.list_rooms().await
}

#[tauri::command]
async fn join_room(engine: Eng<'_>, room_id: u64) -> Result<RoomView, UiError> {
    engine.inner().join_room(room_id).await
}

#[tauri::command]
async fn leave_room(engine: Eng<'_>) -> Result<(), UiError> {
    engine.inner().leave_room().await;
    Ok(())
}

#[tauri::command]
fn set_talking(engine: Eng<'_>, talking: bool) -> Result<bool, UiError> {
    engine.set_talking(talking)
}

#[tauri::command]
fn set_muted(engine: Eng<'_>, muted: bool) -> Result<RoomView, UiError> {
    Ok(engine.set_muted(muted))
}

#[tauri::command]
fn get_room_view(engine: Eng<'_>) -> Result<RoomView, UiError> {
    Ok(engine.view())
}

#[tauri::command]
fn list_devices(engine: Eng<'_>) -> Result<Devices, UiError> {
    engine.list_devices()
}

#[tauri::command]
fn load_settings(engine: Eng<'_>) -> Result<Settings, UiError> {
    Ok(engine.settings())
}

#[tauri::command]
fn save_settings(
    app: AppHandle,
    engine: Eng<'_>,
    features: Feat<'_>,
    settings: Settings,
) -> Result<SaveResult, UiError> {
    let previous = engine.settings();
    let saved = engine.save_settings(settings)?;
    features.apply_settings(&saved);
    let shortcut = if saved.ptt_shortcut != previous.ptt_shortcut
        || rt(&app).lock().expect("runtime").shortcut.is_none()
    {
        apply_shortcut(&app, engine.inner(), &saved.ptt_shortcut)
    } else {
        rt(&app)
            .lock()
            .expect("runtime")
            .shortcut
            .clone()
            .unwrap_or(ShortcutStatus {
                active: false,
                message: None,
            })
    };
    set_overlay(&app, saved.overlay);
    Ok(SaveResult {
        settings: saved,
        shortcut,
    })
}

#[tauri::command]
async fn get_update_status(engine: Eng<'_>) -> Result<voice_core::update::UpdateStatus, UiError> {
    update::update_status(engine.inner()).await
}

#[tauri::command]
fn get_environment(app: AppHandle, engine: Eng<'_>) -> Result<EnvInfo, UiError> {
    let rt = rt(&app);
    let rt = rt.lock().expect("runtime");
    Ok(EnvInfo {
        wayland: is_wayland(),
        synthetic_audio: synthetic_audio(),
        settings_path: engine.settings_path().display().to_string(),
        socket_path: rt.socket_path.as_ref().map(|p| p.display().to_string()),
        socket_error: rt.socket_error.clone(),
        shortcut: rt.shortcut.clone().unwrap_or(ShortcutStatus {
            active: false,
            message: None,
        }),
        version: voice_core::build_info().version,
        commit: voice_core::build_info().commit,
    })
}

#[tauri::command]
async fn logout(engine: Eng<'_>, features: Feat<'_>) -> Result<(), UiError> {
    features.stop_all();
    engine.inner().logout().await
}

#[tauri::command]
async fn open_url(engine: Eng<'_>, url: String) -> Result<(), UiError> {
    if !engine.url_allowed(&url).await {
        return Err(UiError::new(
            "usage",
            "Diese Adresse darf die App nicht öffnen.",
        ));
    }
    open_in_browser(&url).map_err(|e| {
        UiError::new(
            "local",
            format!(
                "Der Browser konnte nicht gestartet werden ({e}). Kopiere den Link stattdessen."
            ),
        )
    })
}

/// Opens the download page of the connected server (`config.client.download_path`, `/talk/client`)
/// in the system browser. The address comes from [`update::download_url`], never from the webview.
#[tauri::command]
async fn open_client_download(engine: Eng<'_>) -> Result<(), UiError> {
    let url = update::download_url(engine.inner()).await?;
    open_in_browser(&url).map_err(|e| {
        UiError::new(
            "local",
            format!(
                "Der Browser konnte nicht gestartet werden ({e}). Öffne die Seite stattdessen selbst."
            ),
        )
    })
}

fn open_in_browser(url: &str) -> std::io::Result<()> {
    #[cfg(target_os = "macos")]
    let mut cmd = std::process::Command::new("open");
    #[cfg(target_os = "windows")]
    let mut cmd = {
        let mut c = std::process::Command::new("rundll32");
        c.arg("url.dll,FileProtocolHandler");
        c
    };
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    let mut cmd = std::process::Command::new("xdg-open");
    cmd.arg(url)
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn()
        .map(|_| ())
}

// ---- feature commands: thin wrappers over `features::Features` (see docs/commands.md) ----

#[tauri::command]
async fn get_config(f: Feat<'_>) -> Result<ConfigView, UiError> {
    f.config().await
}

#[tauri::command]
async fn list_events(f: Feat<'_>) -> Result<EventsResponse, UiError> {
    f.events().await
}

#[tauri::command]
async fn current_event(f: Feat<'_>) -> Result<Option<EventSummary>, UiError> {
    f.current_event().await
}

#[tauri::command]
async fn now_feed(f: Feat<'_>, event: String) -> Result<NowFeed, UiError> {
    f.inner().now(&event).await
}

#[tauri::command]
async fn list_tournaments(f: Feat<'_>, event: String) -> Result<Vec<Tournament>, UiError> {
    f.tournaments(&event).await
}

#[tauri::command]
async fn list_my_matches(f: Feat<'_>, event: String) -> Result<Vec<Match>, UiError> {
    f.my_matches(&event).await
}

#[tauri::command]
async fn tournament_checkin(f: Feat<'_>, tournament_id: u64) -> Result<Tournament, UiError> {
    f.tournament_checkin(tournament_id).await
}

#[tauri::command]
async fn match_report(
    f: Feat<'_>,
    match_id: u64,
    score1: u32,
    score2: u32,
) -> Result<Match, UiError> {
    f.match_report(match_id, score1, score2).await
}

#[tauri::command]
async fn match_confirm(f: Feat<'_>, match_id: u64, lock_version: u64) -> Result<Match, UiError> {
    f.match_confirm(match_id, lock_version).await
}

#[tauri::command]
async fn match_dispute(f: Feat<'_>, match_id: u64) -> Result<Match, UiError> {
    f.match_dispute(match_id).await
}

#[tauri::command]
async fn match_withdraw_report(
    f: Feat<'_>,
    match_id: u64,
    lock_version: u64,
) -> Result<Match, UiError> {
    f.match_withdraw_report(match_id, lock_version).await
}

#[tauri::command]
async fn list_event_servers(f: Feat<'_>, event: String) -> Result<Vec<GameServer>, UiError> {
    f.event_servers(&event).await
}

#[tauri::command]
async fn list_permanent_servers(f: Feat<'_>) -> Result<Vec<GameServer>, UiError> {
    f.permanent_servers().await
}

/// The participants of an event who share their location, with where they are.
#[tauri::command]
async fn list_people(
    f: Feat<'_>,
    event: String,
    team: Option<bool>,
    friends: Option<bool>,
    q: Option<String>,
    page: Option<u32>,
    per_page: Option<u32>,
) -> Result<People, UiError> {
    f.people(
        &event,
        &PeopleQuery {
            team: team.unwrap_or(false),
            friends: friends.unwrap_or(false),
            q,
            page,
            per_page,
        },
    )
    .await
}

/// The join targets to go where a person is (voice rooms and game).
#[tauri::command]
async fn follow_person(f: Feat<'_>, event: String, user_id: u64) -> Result<FollowTargets, UiError> {
    f.follow_person(&event, user_id).await
}

#[tauri::command]
async fn list_polls(f: Feat<'_>, event: String) -> Result<PollsList, UiError> {
    f.polls(&event).await
}

#[tauri::command]
async fn get_poll(f: Feat<'_>, poll_id: u64) -> Result<PollDetail, UiError> {
    f.poll(poll_id).await
}

#[tauri::command]
async fn vote_poll(f: Feat<'_>, poll_id: u64, option_id: u64) -> Result<PollDetail, UiError> {
    f.vote(poll_id, option_id).await
}

#[tauri::command]
async fn get_catering(f: Feat<'_>, event: String) -> Result<Catering, UiError> {
    f.catering(&event).await
}

#[tauri::command]
async fn my_catering_items(f: Feat<'_>, event: String) -> Result<MineResponse, UiError> {
    f.catering_mine(&event).await
}

#[tauri::command]
async fn place_food_item(
    f: Feat<'_>,
    order_id: u64,
    option_key: String,
    note: Option<String>,
) -> Result<MyItem, UiError> {
    f.place_food_item(order_id, &option_key, note.as_deref())
        .await
}

#[tauri::command]
async fn cancel_food_item(f: Feat<'_>, item_id: u64) -> Result<(), UiError> {
    f.cancel_food_item(item_id).await
}

#[tauri::command]
async fn list_files(f: Feat<'_>, event: String) -> Result<FilesOverview, UiError> {
    f.files(&event).await
}

#[tauri::command]
async fn files_quota(f: Feat<'_>, event: String) -> Result<QuotaInfo, UiError> {
    f.files_quota(&event).await
}

#[tauri::command]
async fn delete_file(f: Feat<'_>, file_id: u64) -> Result<(), UiError> {
    f.delete_file(file_id).await
}

#[tauri::command]
async fn download_file(
    f: Feat<'_>,
    file_id: u64,
    name: Option<String>,
) -> Result<TransferStarted, UiError> {
    f.download_file(file_id, name.as_deref().unwrap_or("download"))
        .await
}

#[tauri::command]
async fn upload_files(
    f: Feat<'_>,
    event: String,
    paths: Vec<String>,
) -> Result<Vec<UploadStarted>, UiError> {
    f.upload_files(&event, paths).await
}

#[tauri::command]
fn list_transfers(f: Feat<'_>) -> Result<Vec<TransferView>, UiError> {
    Ok(f.transfers())
}

#[tauri::command]
async fn transfer_pause(f: Feat<'_>, transfer_id: u64) -> Result<(), UiError> {
    f.transfer_pause(transfer_id)
}

#[tauri::command]
async fn transfer_resume(f: Feat<'_>, transfer_id: u64) -> Result<(), UiError> {
    f.transfer_resume(transfer_id)
}

#[tauri::command]
async fn transfer_cancel(f: Feat<'_>, transfer_id: u64) -> Result<(), UiError> {
    f.transfer_cancel(transfer_id)
}

#[tauri::command]
async fn transfer_forget(f: Feat<'_>, transfer_id: u64) -> Result<(), UiError> {
    f.transfer_forget(transfer_id);
    Ok(())
}

#[tauri::command]
async fn list_notifications(
    f: Feat<'_>,
    unread_only: Option<bool>,
    page: Option<u32>,
    per_page: Option<u32>,
) -> Result<NotificationsPage, UiError> {
    f.notifications(
        unread_only.unwrap_or(false),
        page.unwrap_or(1),
        per_page.unwrap_or(20),
    )
    .await
}

#[tauri::command]
async fn unread_count(f: Feat<'_>) -> Result<u64, UiError> {
    f.unread_count().await
}

#[tauri::command]
async fn mark_notification_read(f: Feat<'_>, id: String) -> Result<Notification, UiError> {
    f.mark_notification_read(&id).await
}

#[tauri::command]
async fn mark_all_notifications_read(f: Feat<'_>) -> Result<u64, UiError> {
    f.mark_all_notifications_read().await
}

#[tauri::command]
async fn get_notification_preferences(
    f: Feat<'_>,
) -> Result<std::collections::BTreeMap<String, bool>, UiError> {
    f.notification_preferences().await
}

#[tauri::command]
async fn set_notification_preferences(
    f: Feat<'_>,
    preferences: std::collections::BTreeMap<String, bool>,
) -> Result<std::collections::BTreeMap<String, bool>, UiError> {
    f.set_notification_preferences(preferences).await
}

#[tauri::command]
async fn audio_check_start(
    a: State<'_, Arc<AudioCheck>>,
    input_device: Option<String>,
) -> Result<(), UiError> {
    a.start(input_device)
}

#[tauri::command]
async fn audio_check_stop(a: State<'_, Arc<AudioCheck>>) -> Result<(), UiError> {
    a.stop();
    Ok(())
}

#[tauri::command]
async fn play_test_tone(
    a: State<'_, Arc<AudioCheck>>,
    output_device: Option<String>,
) -> Result<(), UiError> {
    a.play_tone(output_device).await
}

#[tauri::command]
fn set_quiet(f: Feat<'_>, quiet: bool) -> Result<bool, UiError> {
    Ok(f.set_quiet(quiet))
}

#[tauri::command]
fn take_notification_target(f: Feat<'_>) -> Result<Option<voice_core::notify::Target>, UiError> {
    Ok(f.take_notification_target())
}

#[tauri::command]
fn get_quiet(f: Feat<'_>) -> Result<bool, UiError> {
    Ok(f.quiet())
}

#[tauri::command]
async fn chat_history(
    f: Feat<'_>,
    room_id: u64,
    before: Option<u64>,
) -> Result<MessagesPage, UiError> {
    f.chat_history(room_id, before).await
}

#[tauri::command]
async fn chat_post(f: Feat<'_>, room_id: u64, body: String) -> Result<ChatMessage, UiError> {
    f.chat_post(room_id, &body).await
}

#[tauri::command]
async fn chat_delete(f: Feat<'_>, message_id: u64) -> Result<(), UiError> {
    f.chat_delete(message_id).await
}

#[tauri::command]
async fn chat_subscribe(f: Feat<'_>, room_ids: Vec<u64>) -> Result<(), UiError> {
    f.inner().chat_subscribe(room_ids).await
}

#[tauri::command]
fn chat_unsubscribe(f: Feat<'_>) -> Result<(), UiError> {
    f.chat_unsubscribe();
    Ok(())
}

// ---- profile, linked accounts, friends ----

#[tauri::command]
async fn get_profile(f: Feat<'_>) -> Result<Profile, UiError> {
    f.profile().await
}

#[tauri::command]
async fn update_profile(f: Feat<'_>, changes: ProfileUpdate) -> Result<Profile, UiError> {
    f.update_profile(&changes).await
}

#[tauri::command]
async fn list_connections(f: Feat<'_>) -> Result<Vec<Connection>, UiError> {
    f.connections().await
}

#[tauri::command]
async fn unlink_connection(f: Feat<'_>, provider: String) -> Result<Connection, UiError> {
    f.unlink_connection(&provider).await
}

#[tauri::command]
async fn connection_link_url(f: Feat<'_>, provider: String) -> Result<LinkUrl, UiError> {
    f.connection_link_url(&provider).await
}

/// Opens the single-use link of `connection_link_url` in the system browser. Only an https URL
/// of the logged-in server below `/settings/connections/link/` is accepted.
#[tauri::command]
fn open_link_url(f: Feat<'_>, url: String) -> Result<(), UiError> {
    let url = f.checked_link_url(&url)?;
    open_in_browser(&url).map_err(|e| {
        UiError::new(
            "local",
            format!("Der Browser konnte nicht gestartet werden ({e})."),
        )
    })
}

#[tauri::command]
async fn list_friends(f: Feat<'_>) -> Result<Vec<FriendUser>, UiError> {
    f.friends().await
}

#[tauri::command]
async fn list_friend_requests(f: Feat<'_>) -> Result<FriendRequests, UiError> {
    f.friend_requests().await
}

#[tauri::command]
async fn list_friend_suggestions(f: Feat<'_>) -> Result<Vec<FriendSuggestion>, UiError> {
    f.friend_suggestions().await
}

#[tauri::command]
async fn list_blocked_users(f: Feat<'_>) -> Result<Vec<FriendUser>, UiError> {
    f.blocked_users().await
}

#[tauri::command]
async fn send_friend_request(f: Feat<'_>, user_id: u64) -> Result<serde_json::Value, UiError> {
    f.send_friend_request(user_id).await
}

#[tauri::command]
async fn accept_friend_request(f: Feat<'_>, request_id: u64) -> Result<serde_json::Value, UiError> {
    f.accept_friend_request(request_id).await
}

#[tauri::command]
async fn decline_friend_request(
    f: Feat<'_>,
    request_id: u64,
) -> Result<serde_json::Value, UiError> {
    f.decline_friend_request(request_id).await
}

#[tauri::command]
async fn cancel_friend_request(f: Feat<'_>, request_id: u64) -> Result<(), UiError> {
    f.cancel_friend_request(request_id).await
}

#[tauri::command]
async fn remove_friend(f: Feat<'_>, user_id: u64) -> Result<(), UiError> {
    f.remove_friend(user_id).await
}

#[tauri::command]
async fn block_user(f: Feat<'_>, user_id: u64) -> Result<(), UiError> {
    f.block_user(user_id).await
}

#[tauri::command]
async fn unblock_user(f: Feat<'_>, user_id: u64) -> Result<(), UiError> {
    f.unblock_user(user_id).await
}

/// Opens a join link with the system handler: a game server's `steam://connect/...` link, or the
/// `mumble://` / `ts3server://` link of a voice room. Nothing else is accepted (see `joinurl`):
/// the webview may not make the OS open arbitrary URLs. A voice link is rewritten strictly
/// (names percent-encoded) before it is opened.
#[tauri::command]
fn open_join_url(url: String) -> Result<(), UiError> {
    let (url, program) = joinurl::validate_join_url(&url).map_err(|m| UiError::new("usage", m))?;
    open_in_browser(&url).map_err(|e| {
        UiError::new(
            "local",
            format!(
                "{program} konnte nicht gestartet werden ({e}). Kopiere die Adresse stattdessen."
            ),
        )
    })
}

/// Puts text on the clipboard (a server address, a connect string).
#[tauri::command]
fn copy_text(app: AppHandle, text: String) -> Result<(), UiError> {
    use tauri_plugin_clipboard_manager::ClipboardExt;
    if text.chars().count() > 4096 {
        return Err(UiError::new("usage", "Der Text ist zu lang."));
    }
    app.clipboard().write_text(text).map_err(|e| {
        UiError::new(
            "local",
            format!("Die Zwischenablage ist nicht erreichbar ({e})."),
        )
    })
}

/// Shows the system file picker; returns the chosen paths (empty when cancelled).
#[tauri::command]
async fn pick_files(app: AppHandle) -> Result<Vec<String>, UiError> {
    use tauri_plugin_dialog::DialogExt;
    let (tx, rx) = tokio::sync::oneshot::channel();
    app.dialog().file().pick_files(move |p| {
        let _ = tx.send(p);
    });
    let picked = rx.await.ok().flatten().unwrap_or_default();
    Ok(picked
        .into_iter()
        .filter_map(|p| p.into_path().ok())
        .map(|p| p.display().to_string())
        .collect())
}

/// Shows the system folder picker; returns the folder or null when cancelled.
#[tauri::command]
async fn pick_directory(app: AppHandle) -> Result<Option<String>, UiError> {
    use tauri_plugin_dialog::DialogExt;
    let (tx, rx) = tokio::sync::oneshot::channel();
    app.dialog().file().pick_folder(move |p| {
        let _ = tx.send(p);
    });
    Ok(rx
        .await
        .ok()
        .flatten()
        .and_then(|p| p.into_path().ok())
        .map(|p| p.display().to_string()))
}

#[tauri::command]
async fn get_avatar(engine: Eng<'_>, path: String) -> Result<Option<String>, UiError> {
    let api = engine.client().map_err(UiError::from)?;
    avatar::avatar_data_uri(&api, avatar::AvatarCache::shared(), &path)
        .await
        .map_err(UiError::from)
}

#[tauri::command]
fn get_download_dir(f: Feat<'_>) -> Result<String, UiError> {
    Ok(f.download_dir().display().to_string())
}

#[tauri::command]
fn quit(app: AppHandle) {
    app.exit(0);
}

fn synthetic_audio() -> bool {
    std::env::var("LANOMAT_VOICE_SYNTHETIC_AUDIO").is_ok_and(|v| !v.is_empty() && v != "0")
}

// ---- tray ----

fn build_tray(app: &AppHandle) -> tauri::Result<()> {
    let show = MenuItem::with_id(app, "show", "Anzeigen", true, None::<&str>)?;
    let mute = MenuItem::with_id(app, "mute", "Stumm schalten", true, None::<&str>)?;
    let overlay = MenuItem::with_id(app, "overlay", "Overlay ein/aus", true, None::<&str>)?;
    let quiet_label = if app.state::<Arc<Features>>().quiet() {
        QUIET_ON
    } else {
        QUIET_OFF
    };
    let quiet = MenuItem::with_id(app, "quiet", quiet_label, true, None::<&str>)?;
    let leave = MenuItem::with_id(app, "leave", "Verlassen", true, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Beenden", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&show, &mute, &quiet, &overlay, &leave, &quit])?;
    app.manage(TrayItems { mute, quiet });
    let mut builder = TrayIconBuilder::with_id("main")
        .tooltip("LANoMAT Voice")
        .menu(&menu)
        .show_menu_on_left_click(true)
        .on_menu_event(|app, event| {
            let engine = app.state::<Arc<Engine>>().inner().clone();
            match event.id.as_ref() {
                "show" => show_main(app),
                "mute" => {
                    let next = !engine.is_muted();
                    engine.set_muted(next);
                }
                "quiet" => {
                    // The label follows through the `quiet-changed` event the toggle sends.
                    let f = app.state::<Arc<Features>>();
                    let next = !f.quiet();
                    f.set_quiet(next);
                }
                "overlay" => {
                    let mut s = engine.settings();
                    s.overlay = !s.overlay;
                    if engine.save_settings(s.clone()).is_ok() {
                        set_overlay(app, s.overlay);
                        let _ = app.emit("settings-changed", &s);
                    }
                }
                "leave" => {
                    tauri::async_runtime::spawn(async move { engine.leave_room().await });
                }
                "quit" => app.exit(0),
                _ => {}
            }
        });
    if let Some(icon) = app.default_window_icon() {
        builder = builder.icon(icon.clone());
    }
    builder.build(app)?;
    Ok(())
}

fn refresh_tray(app: &AppHandle, view: &RoomView) {
    if let Some(items) = app.try_state::<TrayItems>() {
        let _ = items.mute.set_text(if view.muted {
            "Stummschaltung aufheben"
        } else {
            "Stumm schalten"
        });
    }
    if let Some(tray) = app.tray_by_id("main") {
        let tip = match (&view.room, view.talking) {
            (Some(r), true) => format!("LANoMAT Voice: {} (du sprichst)", r.name),
            (Some(r), false) => format!("LANoMAT Voice: {}", r.name),
            _ => "LANoMAT Voice".to_owned(),
        };
        let _ = tray.set_tooltip(Some(tip));
    }
}

// ---- control socket ----

async fn start_control_socket(app: AppHandle, engine: Arc<Engine>) {
    let outcome = match control::default_socket_path() {
        Ok(path) => ControlServer::bind(&path).await.map(|s| (path, s)),
        Err(e) => Err(e),
    };
    match outcome {
        Ok((path, server)) => {
            rt(&app).lock().expect("runtime").socket_path = Some(path);
            let handler: control::Handler = Arc::new(move |cmd| engine.ptt_command(cmd));
            // The task owns the server; the file is removed again at exit (see `cleanup`).
            tauri::async_runtime::spawn(async move { server.serve(handler).await });
        }
        Err(e) => {
            tracing::warn!("control socket unavailable: {e}");
            rt(&app).lock().expect("runtime").socket_error = Some(e.to_string());
        }
    }
}

fn cleanup(app: &AppHandle) {
    let engine = app.state::<Arc<Engine>>().inner().clone();
    tauri::async_runtime::block_on(async {
        let _ = tokio::time::timeout(Duration::from_secs(3), engine.leave_room()).await;
    });
    if let Some(path) = rt(app).lock().expect("runtime").socket_path.take() {
        // A unix socket leaves a file behind; a Windows named pipe vanishes with the process
        // (and a DeleteFile on a pipe name would open the pipe), so there is nothing to remove.
        #[cfg(unix)]
        let _ = std::fs::remove_file(path);
        #[cfg(not(unix))]
        drop(path);
    }
}

/// One list for the registration and for the test that keeps `docs/commands.md` honest.
macro_rules! command_set {
    ($($name:ident),* $(,)?) => {
        /// Every command the UI may invoke.
        pub const COMMAND_NAMES: &[&str] = &[$(stringify!($name)),*];

        #[cfg(not(feature = "selftest"))]
        fn register(b: tauri::Builder<tauri::Wry>) -> tauri::Builder<tauri::Wry> {
            b.invoke_handler(tauri::generate_handler![$($name),*])
        }

        #[cfg(feature = "selftest")]
        fn register(b: tauri::Builder<tauri::Wry>) -> tauri::Builder<tauri::Wry> {
            b.invoke_handler(tauri::generate_handler![
                $($name,)* selftest::selftest_report, selftest::selftest_done, selftest::selftest_hook
            ])
        }
    };
}

command_set!(
    get_update_status,
    get_status,
    connect_start,
    connect_poll,
    connect_cancel,
    list_rooms,
    join_room,
    leave_room,
    set_talking,
    set_muted,
    get_room_view,
    list_devices,
    load_settings,
    save_settings,
    get_environment,
    logout,
    open_url,
    open_client_download,
    quit,
    get_config,
    list_events,
    current_event,
    now_feed,
    list_tournaments,
    list_my_matches,
    tournament_checkin,
    match_report,
    match_confirm,
    match_dispute,
    match_withdraw_report,
    list_event_servers,
    list_permanent_servers,
    list_people,
    follow_person,
    list_polls,
    get_poll,
    vote_poll,
    get_catering,
    my_catering_items,
    place_food_item,
    cancel_food_item,
    list_files,
    files_quota,
    delete_file,
    download_file,
    upload_files,
    list_transfers,
    transfer_pause,
    transfer_resume,
    transfer_cancel,
    transfer_forget,
    list_notifications,
    unread_count,
    mark_notification_read,
    mark_all_notifications_read,
    get_notification_preferences,
    set_notification_preferences,
    set_quiet,
    get_quiet,
    audio_check_start,
    audio_check_stop,
    play_test_tone,
    take_notification_target,
    chat_history,
    chat_post,
    chat_delete,
    chat_subscribe,
    chat_unsubscribe,
    get_profile,
    update_profile,
    list_connections,
    unlink_connection,
    connection_link_url,
    open_link_url,
    list_friends,
    list_friend_requests,
    list_friend_suggestions,
    list_blocked_users,
    send_friend_request,
    accept_friend_request,
    decline_friend_request,
    cancel_friend_request,
    remove_friend,
    block_user,
    unblock_user,
    open_join_url,
    copy_text,
    pick_files,
    pick_directory,
    get_download_dir,
    get_avatar
);

/// Raises system notifications through `tauri-plugin-notification`. The desktop plugin has no
/// click callback, so the target screen travels in the `notify-event` instead; the UI opens it
/// when the window is shown (see docs/commands.md).
struct TauriNotifier;

impl Notifier for TauriNotifier {
    fn notify(&self, n: &SystemNotification) -> Result<(), String> {
        use tauri_plugin_notification::NotificationExt;
        let app = APP.get().ok_or("the app is not ready")?;
        let mut b = app.notification().builder().title(&n.title);
        if let Some(body) = &n.body {
            b = b.body(body);
        }
        b.show().map_err(|e| e.to_string())
    }
}

/// Builds and runs the application; returns when it has exited.
pub fn run() {
    if let Ok(filter) = std::env::var("RUST_LOG") {
        let _ = tracing_subscriber::fmt()
            .with_env_filter(filter)
            .with_writer(std::io::stderr)
            .try_init();
    }
    voice_core::install_crypto_provider();

    let store = FileTokenStore::default_location().expect("no config directory");
    let settings_path = settings::default_path().expect("no config directory");
    let emit: engine::Emit = Arc::new(|view| {
        if let Some(app) = APP.get() {
            let _ = app.emit(EVENT_ROOM, view);
            refresh_tray(app, view);
            // Talking (push-to-talk held) silences system notifications.
            if let Some(f) = app.try_state::<Arc<Features>>() {
                f.set_talking(view.talking_for_dnd());
            }
        }
    });
    let engine = Engine::new(store, settings_path, emit, synthetic_audio());
    let event_sink: features::EventSink = Arc::new(|name, payload| {
        if let Some(app) = APP.get() {
            if name == features::EVT_QUIET {
                if let Some(items) = app.try_state::<TrayItems>() {
                    let on = payload.get("quiet").and_then(|q| q.as_bool()) == Some(true);
                    let _ = items.quiet.set_text(if on { QUIET_ON } else { QUIET_OFF });
                }
            }
            let _ = app.emit(name, payload);
        }
    });
    let audio_check = AudioCheck::new(engine.clone(), event_sink.clone(), synthetic_audio());
    let features = Features::new(
        engine.clone(),
        event_sink,
        Arc::new(TauriNotifier),
        FeatureOptions::default(),
    )
    .expect("transfer manager");

    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_global_shortcut::Builder::new().build())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_clipboard_manager::init())
        .plugin(tauri_plugin_dialog::init())
        .manage(engine.clone())
        .manage(features.clone())
        .manage(audio_check)
        .manage(Arc::new(Mutex::new(Runtime::default())));
    let app = register(builder)
        .setup(move |app| {
            engine.set_runtime(tauri::async_runtime::handle().inner().clone());
            let handle = app.handle().clone();
            let _ = APP.set(handle.clone());
            if let Err(e) = build_tray(&handle) {
                // No tray host (some Wayland setups, headless): the window still works.
                tracing::warn!("tray icon unavailable: {e}");
            }
            if let Err(e) = create_overlay(&handle) {
                tracing::warn!("overlay window unavailable: {e}");
            }
            let settings = engine.settings();
            apply_shortcut(&handle, &engine, &settings.ptt_shortcut);
            set_overlay(&handle, settings.overlay);
            let (h, e) = (handle.clone(), engine.clone());
            tauri::async_runtime::spawn(async move { start_control_socket(h, e).await });
            if engine.client().is_ok() {
                // A stored token: notifications run from the start, also with the window hidden.
                let f = features.clone();
                tauri::async_runtime::spawn(async move { f.ensure_started() });
            }
            #[cfg(feature = "selftest")]
            selftest::arm(&handle);
            let h = handle.clone();
            tauri::async_runtime::spawn(async move {
                wait_for_termination().await;
                h.exit(0);
            });
            Ok(())
        })
        .on_window_event(|window, event| {
            if window.label() != "main" {
                return;
            }
            let app = window.app_handle();
            match event {
                WindowEvent::Focused(focused) => {
                    app.state::<Arc<Features>>().set_focused(*focused);
                }
                WindowEvent::CloseRequested { api, .. } => {
                    if app.state::<Arc<Engine>>().settings().close_to_tray {
                        api.prevent_close();
                        let _ = window.hide();
                        app.state::<Arc<Features>>().set_focused(false);
                    } else {
                        app.exit(0);
                    }
                }
                _ => {}
            }
        })
        .build(tauri::generate_context!())
        .expect("error while building the application");

    app.run(|app, event| match event {
        RunEvent::ExitRequested { api, code, .. } => {
            // All windows hidden is not "exit" while the tray keeps the app alive.
            if code.is_none() && app.state::<Arc<Engine>>().settings().close_to_tray {
                api.prevent_exit();
            }
        }
        RunEvent::Exit => cleanup(app),
        _ => {}
    });
}

#[cfg(unix)]
async fn wait_for_termination() {
    use tokio::signal::unix::{signal, SignalKind};
    let (Ok(mut term), Ok(mut int)) = (
        signal(SignalKind::terminate()),
        signal(SignalKind::interrupt()),
    ) else {
        return std::future::pending().await;
    };
    tokio::select! {
        _ = term.recv() => {}
        _ = int.recv() => {}
    }
}

#[cfg(windows)]
async fn wait_for_termination() {
    use tokio::signal::windows::{ctrl_break, ctrl_close, ctrl_shutdown};
    let (Ok(mut brk), Ok(mut close), Ok(mut shutdown)) =
        (ctrl_break(), ctrl_close(), ctrl_shutdown())
    else {
        return std::future::pending().await;
    };
    tokio::select! {
        _ = tokio::signal::ctrl_c() => {}
        _ = brk.recv() => {}
        _ = close.recv() => {}
        _ = shutdown.recv() => {}
    }
}

#[cfg(not(any(unix, windows)))]
async fn wait_for_termination() {
    let _ = tokio::signal::ctrl_c().await;
}
