//! LANoMAT Voice desktop shell: a Tauri v2 window, tray icon and speaking overlay on top of
//! `voice-core`. All behaviour lives in [`engine::Engine`]; this file wires it to Tauri
//! (commands, events, tray, overlay, global shortcut, control socket, clean shutdown).

pub mod engine;
pub mod settings;
pub mod view;

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
use voice_core::api::{FileTokenStore, Room};
use voice_core::control::{self, ControlServer, PttCommand};

use engine::{ConnectPoll, ConnectStart, Devices, Engine, Status, UiError};
use settings::Settings;
use view::RoomView;

type Eng<'a> = State<'a, Arc<Engine>>;

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
    pub version: &'static str,
}

#[derive(Default)]
struct Runtime {
    socket_path: Option<PathBuf>,
    socket_error: Option<String>,
    shortcut: Option<ShortcutStatus>,
}

struct TrayItems {
    mute: MenuItem<tauri::Wry>,
}

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
async fn get_status(engine: Eng<'_>) -> Result<Status, UiError> {
    Ok(engine.status().await)
}

#[tauri::command]
async fn connect_start(engine: Eng<'_>, server_url: String) -> Result<ConnectStart, UiError> {
    engine.connect_start(&server_url).await
}

#[tauri::command]
async fn connect_poll(engine: Eng<'_>) -> Result<ConnectPoll, UiError> {
    Ok(engine.connect_poll().await)
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
    settings: Settings,
) -> Result<SaveResult, UiError> {
    let previous = engine.settings();
    let saved = engine.save_settings(settings)?;
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
        version: env!("CARGO_PKG_VERSION"),
    })
}

#[tauri::command]
async fn logout(engine: Eng<'_>) -> Result<(), UiError> {
    engine.inner().logout().await
}

#[tauri::command]
async fn open_url(engine: Eng<'_>, url: String) -> Result<(), UiError> {
    if !engine.url_allowed(&url).await {
        return Err(UiError {
            kind: "usage",
            message: "Diese Adresse darf die App nicht öffnen.".into(),
        });
    }
    open_in_browser(&url).map_err(|e| UiError {
        kind: "local",
        message: format!(
            "Der Browser konnte nicht gestartet werden ({e}). Kopiere den Link stattdessen."
        ),
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
    let leave = MenuItem::with_id(app, "leave", "Verlassen", true, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Beenden", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&show, &mute, &overlay, &leave, &quit])?;
    app.manage(TrayItems { mute });
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
        let _ = std::fs::remove_file(path);
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
        }
    });
    let engine = Engine::new(store, settings_path, emit, synthetic_audio());

    let app = tauri::Builder::default()
        .plugin(tauri_plugin_global_shortcut::Builder::new().build())
        .manage(engine.clone())
        .manage(Arc::new(Mutex::new(Runtime::default())))
        .invoke_handler(tauri::generate_handler![
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
            quit
        ])
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
            let h = handle.clone();
            tauri::async_runtime::spawn(async move {
                wait_for_termination().await;
                h.exit(0);
            });
            Ok(())
        })
        .on_window_event(|window, event| {
            if let WindowEvent::CloseRequested { api, .. } = event {
                if window.label() != "main" {
                    return;
                }
                let app = window.app_handle();
                if app.state::<Arc<Engine>>().settings().close_to_tray {
                    api.prevent_close();
                    let _ = window.hide();
                } else {
                    app.exit(0);
                }
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

#[cfg(not(unix))]
async fn wait_for_termination() {
    let _ = tokio::signal::ctrl_c().await;
}
