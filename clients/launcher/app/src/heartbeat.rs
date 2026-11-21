//! The fleet heartbeat: this terminal's row in the orga monitor.
//!
//! Every [`INTERVAL`] (30 s, the server's `LauncherTerminal::HEARTBEAT_SECONDS`)
//! and immediately on a state change (paired, install start/finish, launch,
//! logout) the launcher reports
//! `{name, os, arch, version, state, current_game_slug, installed, gamepad}` to
//! `POST /api/v1/terminals/heartbeat`. The bearer token is the identity, so a
//! cabinet can only ever write its own row.
//!
//! A heartbeat is decoration, never a precondition: a failing send is logged at
//! debug and the next tick tries again, so an unreachable server never blocks
//! the UI and never signs the user out. No error path carries the token.
//!
//! Identity: `LANOMAT_TERMINAL_NAME` when set (an arcade cabinet can be
//! "Arcade 3" without touching its hostname), else `HOSTNAME`/`COMPUTERNAME`,
//! else `PC`.

use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

use launcher_core::api::{ApiClient, TerminalHeartbeat, TerminalState, HEARTBEAT_SECONDS};
use serde::Serialize;
use tauri::{AppHandle, Manager};
use tokio::sync::{Mutex, Notify};

use crate::{env, errors, installs, AppState};

/// The cadence a paired launcher repeats its heartbeat at.
pub const INTERVAL: Duration = Duration::from_secs(HEARTBEAT_SECONDS);

/// A goodbye may not hold the window close (or the logout) hostage: two
/// seconds, then the app exits and the server's 90 s offline derivation takes
/// over.
const GOODBYE_TIMEOUT: Duration = Duration::from_secs(2);

/// The terminal name is clipped to the server's 60-character column.
const NAME_MAX: usize = 60;

/// A launched game we watch until its process is gone. The launcher spawns the
/// process itself (`InstallEngine::launch`), so the executable path is the one
/// fact that survives: a cabinet that returns to the menu is idle again without
/// the UI having to report it.
#[derive(Debug, Clone)]
struct Playing {
    slug: String,
    exe: PathBuf,
}

#[derive(Default)]
struct Tracked {
    gamepad: bool,
    install: Option<String>,
    playing: Option<Playing>,
}

/// The heartbeat's own small state: a wake-up signal plus what this terminal is
/// doing right now. `lib.rs` only calls the intent methods below.
pub struct Heartbeat {
    pulse: Arc<Notify>,
    tracked: Mutex<Tracked>,
}

impl Default for Heartbeat {
    fn default() -> Self {
        Self::new()
    }
}

impl Heartbeat {
    pub fn new() -> Self {
        Self {
            pulse: Arc::new(Notify::new()),
            tracked: Mutex::new(Tracked::default()),
        }
    }

    /// A state worth reporting now: wake the background task instead of
    /// waiting out the remaining tick.
    fn wake(&self) {
        self.pulse.notify_one();
    }

    /// The pairing just succeeded: report the terminal immediately, so the
    /// monitor shows the cabinet the moment it appears.
    pub fn paired(&self) {
        self.wake();
    }

    /// An install started. The cabinet stays `idle` (it is not playing) but
    /// names the game it is working on.
    pub async fn install_started(&self, slug: &str) {
        self.tracked.lock().await.install = Some(slug.to_string());
        self.wake();
    }

    /// The install finished, failed or was cancelled: the installed list and
    /// the current game both change now.
    pub async fn install_finished(&self) {
        self.tracked.lock().await.install = None;
        self.wake();
    }

    /// A game was launched; `state` becomes `playing` until its process exits.
    pub async fn launched(&self, slug: &str, exe: &Path) {
        self.tracked.lock().await.playing = Some(Playing {
            slug: slug.to_string(),
            exe: exe.to_path_buf(),
        });
        self.wake();
    }

    /// The UI's gamepad state (Gamepad API `gamepadconnected`/`disconnected`).
    pub async fn set_gamepad(&self, connected: bool) {
        let changed = {
            let mut tracked = self.tracked.lock().await;
            let before = tracked.gamepad;

            tracked.gamepad = connected;
            before != connected
        };

        if changed {
            self.wake();
        }
    }

    pub async fn gamepad(&self) -> bool {
        self.tracked.lock().await.gamepad
    }
}

/// One heartbeat worth sending: the client (which owns the bearer token) and
/// the payload built under the lock.
struct Snapshot {
    api: ApiClient,
    beat: TerminalHeartbeat,
}

/// What the `send_heartbeat` command reports back to the UI. `sent: false`
/// with `error: Some(..)` is not an error the UI has to show: the background
/// task keeps trying.
#[derive(Debug, Clone, Serialize)]
pub struct HeartbeatView {
    pub paired: bool,
    pub sent: bool,
    pub name: String,
    pub state: TerminalState,
    pub current_game_slug: Option<String>,
    pub installed: usize,
    pub gamepad: bool,
    pub error: Option<String>,
}

/// The name the monitor shows. `LANOMAT_TERMINAL_NAME` wins over the host name
/// so a fleet of identical cabinets is distinguishable.
pub fn terminal_name() -> String {
    terminal_name_with(|key| std::env::var(key).ok())
}

fn terminal_name_with(env: impl Fn(&str) -> Option<String>) -> String {
    for key in ["LANOMAT_TERMINAL_NAME", "HOSTNAME", "COMPUTERNAME"] {
        let Some(value) = env(key) else {
            continue;
        };
        let value = value.trim();

        if !value.is_empty() {
            return value.chars().take(NAME_MAX).collect();
        }
    }

    "PC".to_string()
}

/// Build the payload for the current state. `forced` pins the state (the
/// logout goodbye); otherwise an install or a running game is reported.
async fn take_snapshot(state: &AppState, forced: Option<TerminalState>) -> Option<Snapshot> {
    let api = { state.inner.lock().await.api.clone()? };
    let installed = installs::load(&state.dir)
        .into_iter()
        .map(|record| record.slug)
        .collect::<Vec<_>>();
    let (reported, current, gamepad) = {
        let mut tracked = state.heartbeat.tracked.lock().await;
        let (reported, current) = match forced {
            Some(forced) => (forced, None),
            None => report_state(&mut tracked),
        };

        (reported, current, tracked.gamepad)
    };

    let beat = TerminalHeartbeat::new(&terminal_name(), env::VERSION)
        .state(reported)
        .current_game(current.as_deref())
        .installed(installed)
        .gamepad(gamepad);

    Some(Snapshot { api, beat })
}

/// The state one tick reports. A launch is `playing` while its process runs;
/// clearing the marker here is the only place a game stops being "current".
fn report_state(tracked: &mut Tracked) -> (TerminalState, Option<String>) {
    let still_running = tracked
        .playing
        .take()
        .filter(|playing| process_running(&playing.exe));

    if let Some(playing) = still_running {
        let current = Some(playing.slug.clone());
        tracked.playing = Some(playing);

        return (TerminalState::Playing, current);
    }

    if let Some(slug) = &tracked.install {
        return (TerminalState::Idle, Some(slug.clone()));
    }

    (TerminalState::Idle, None)
}

async fn send(snapshot: &Snapshot) {
    match snapshot.api.heartbeat(&snapshot.beat).await {
        Ok(_) => tracing::debug!(
            target: "lanomat::heartbeat",
            name = snapshot.beat.name.as_str(),
            state = snapshot.beat.state.as_str(),
            "terminal heartbeat sent"
        ),
        Err(error) => tracing::debug!(
            target: "lanomat::heartbeat",
            %error,
            "terminal heartbeat failed; retrying on the next tick"
        ),
    }
}

/// Say goodbye while the token still exists: the monitor shows a free cabinet
/// now instead of waiting out three missed ticks. Best effort and bounded — a
/// logout never depends on the network.
pub async fn say_goodbye(state: &AppState) {
    if let Some(snapshot) = take_snapshot(state, Some(TerminalState::Offline)).await {
        let _ = tokio::time::timeout(GOODBYE_TIMEOUT, send(&snapshot)).await;
    }
}

/// The same goodbye for the app-exit callback, which is not async.
pub fn goodbye(state: &AppState) {
    tauri::async_runtime::block_on(say_goodbye(state));
}

/// One manual heartbeat for the `send_heartbeat` command. Never fails: an
/// unpaired launcher sends nothing, a failed send names its reason.
pub async fn send_now(state: &AppState) -> HeartbeatView {
    let Some(snapshot) = take_snapshot(state, None).await else {
        return HeartbeatView {
            paired: false,
            sent: false,
            name: terminal_name(),
            state: TerminalState::Idle,
            current_game_slug: None,
            installed: installs::load(&state.dir).len(),
            gamepad: state.heartbeat.gamepad().await,
            error: None,
        };
    };

    let error = match snapshot.api.heartbeat(&snapshot.beat).await {
        Ok(_) => None,
        Err(error) => Some(errors::redact(error.to_string(), snapshot.api.token())),
    };

    HeartbeatView {
        paired: true,
        sent: error.is_none(),
        name: snapshot.beat.name.clone(),
        state: snapshot.beat.state,
        current_game_slug: snapshot.beat.current_game_slug.clone(),
        installed: snapshot.beat.installed.len(),
        gamepad: snapshot.beat.gamepad,
        error,
    }
}

/// Start the background heartbeat task. Idempotent; safe to call from `setup`
/// and again after pairing. While unpaired a tick sends nothing (the API
/// client only exists once the pairing does), so the first tick after a
/// pairing is the wake-up from [`Heartbeat::paired`].
pub fn spawn(app: AppHandle) {
    static STARTED: AtomicBool = AtomicBool::new(false);

    if STARTED.swap(true, Ordering::SeqCst) {
        return;
    }

    tauri::async_runtime::spawn(async move {
        loop {
            tick(&app).await;

            // A state change wakes this early; otherwise the server's cadence
            // applies. Nothing here can starve the UI task.
            let pulse = app.state::<AppState>().heartbeat.pulse.clone();
            let _ = tokio::time::timeout(INTERVAL, pulse.notified()).await;
        }
    });
}

async fn tick(app: &AppHandle) {
    let state = app.state::<AppState>();

    if let Some(snapshot) = take_snapshot(&state, None).await {
        send(&snapshot).await;
    }
}

/// Whether a process is still running `exe`. Linux reads `/proc/<pid>/exe`
/// (the canonical path a running binary was started from); Windows asks
/// `tasklist` for the image name. Anything else keeps the last report: the
/// launcher is built for Linux and Windows only.
#[cfg(target_os = "linux")]
fn process_running(exe: &Path) -> bool {
    let Ok(entries) = std::fs::read_dir("/proc") else {
        return false;
    };

    for entry in entries.flatten() {
        let name = entry.file_name();
        let pid = name.to_string_lossy();

        if pid.is_empty() || !pid.chars().all(|c| c.is_ascii_digit()) {
            continue;
        }

        if std::fs::read_link(entry.path().join("exe"))
            .map(|running| running == exe)
            .unwrap_or(false)
        {
            return true;
        }
    }

    false
}

#[cfg(target_os = "windows")]
fn process_running(exe: &Path) -> bool {
    let Some(name) = exe.file_name().and_then(|name| name.to_str()) else {
        return false;
    };

    let filter = format!("IMAGENAME eq {name}");
    let output = std::process::Command::new("tasklist")
        .args(["/FI", &filter, "/NH"])
        .output();

    output
        .map(|output| {
            String::from_utf8_lossy(&output.stdout)
                .to_ascii_lowercase()
                .contains(&name.to_ascii_lowercase())
        })
        .unwrap_or(false)
}

#[cfg(not(any(target_os = "linux", target_os = "windows")))]
fn process_running(_exe: &Path) -> bool {
    true
}

#[cfg(test)]
mod tests {
    use super::*;
    use launcher_core::state::{self, LauncherConfig};

    fn paired_state(dir: &Path) -> AppState {
        let config = LauncherConfig::new("https://lan.test".into(), "tok".into(), String::new());
        state::save(dir, &config).unwrap();

        AppState::new(dir.to_path_buf())
    }

    #[tokio::test]
    async fn an_unpaired_launcher_reports_nothing() {
        let dir = tempfile::tempdir().unwrap();
        let app = AppState::new(dir.path().to_path_buf());

        assert!(take_snapshot(&app, None).await.is_none());

        let view = send_now(&app).await;

        assert!(!view.paired);
        assert!(!view.sent);
        assert!(view.error.is_none());
    }

    #[tokio::test]
    async fn a_paired_idle_terminal_reports_idle_with_its_install_ledger() {
        let dir = tempfile::tempdir().unwrap();
        installs::save(
            dir.path(),
            &installs::InstallRecord {
                slug: "unreal-tournament-2004".into(),
                target_dir: "/games/ut2004".into(),
                version: Some("3374".into()),
                verified: Vec::new(),
            },
        )
        .unwrap();

        let app = paired_state(dir.path());
        let snapshot = take_snapshot(&app, None).await.unwrap();

        assert_eq!(snapshot.beat.state, TerminalState::Idle);
        assert_eq!(snapshot.beat.current_game_slug, None);
        assert_eq!(
            snapshot.beat.installed,
            vec!["unreal-tournament-2004".to_string()]
        );
        assert!(!snapshot.beat.gamepad);
        assert!(!snapshot.beat.name.is_empty());
        assert_eq!(
            snapshot.beat.os,
            launcher_core::types::Os::current().as_str()
        );
    }

    #[tokio::test]
    async fn an_install_names_the_game_but_does_not_look_like_playing() {
        let dir = tempfile::tempdir().unwrap();
        let app = paired_state(dir.path());

        app.heartbeat
            .install_started("unreal-tournament-2004")
            .await;

        let snapshot = take_snapshot(&app, None).await.unwrap();

        assert_eq!(snapshot.beat.state, TerminalState::Idle);
        assert_eq!(
            snapshot.beat.current_game_slug.as_deref(),
            Some("unreal-tournament-2004")
        );

        app.heartbeat.install_finished().await;

        let after = take_snapshot(&app, None).await.unwrap();

        assert_eq!(after.beat.state, TerminalState::Idle);
        assert_eq!(after.beat.current_game_slug, None);
    }

    #[tokio::test]
    async fn a_launched_game_is_playing_while_its_process_lives() {
        let dir = tempfile::tempdir().unwrap();
        let app = paired_state(dir.path());
        // The test binary itself is a process we know is running.
        let exe = std::env::current_exe().unwrap();

        app.heartbeat.launched("unreal-tournament-2004", &exe).await;

        let snapshot = take_snapshot(&app, None).await.unwrap();

        assert_eq!(snapshot.beat.state, TerminalState::Playing);
        assert_eq!(
            snapshot.beat.current_game_slug.as_deref(),
            Some("unreal-tournament-2004")
        );

        app.heartbeat
            .launched("ut2004", Path::new("/nonexistent/ut2004-bin"))
            .await;

        let after = take_snapshot(&app, None).await.unwrap();

        assert_eq!(after.beat.state, TerminalState::Idle);
        assert_eq!(after.beat.current_game_slug, None);
        assert!(app.heartbeat.tracked.lock().await.playing.is_none());
    }

    #[tokio::test]
    async fn the_gamepad_flag_reaches_the_payload() {
        let dir = tempfile::tempdir().unwrap();
        let app = paired_state(dir.path());

        app.heartbeat.set_gamepad(true).await;

        assert!(take_snapshot(&app, None).await.unwrap().beat.gamepad);

        app.heartbeat.set_gamepad(false).await;

        assert!(!app.heartbeat.tracked.lock().await.gamepad);
    }

    #[tokio::test]
    async fn the_logout_goodbye_is_offline_and_names_no_game() {
        let dir = tempfile::tempdir().unwrap();
        let app = paired_state(dir.path());

        app.heartbeat.install_started("ut2004").await;
        app.heartbeat
            .launched("ut2004", std::env::current_exe().unwrap().as_path())
            .await;

        let snapshot = take_snapshot(&app, Some(TerminalState::Offline))
            .await
            .unwrap();

        assert_eq!(snapshot.beat.state, TerminalState::Offline);
        assert_eq!(snapshot.beat.current_game_slug, None);
    }

    #[test]
    fn the_terminal_name_prefers_the_explicit_override() {
        let env = |key: &str| match key {
            "LANOMAT_TERMINAL_NAME" => Some("  Arcade 3  ".to_string()),
            "HOSTNAME" => Some("tinybox".to_string()),
            _ => None,
        };

        assert_eq!(terminal_name_with(env), "Arcade 3");
    }

    #[test]
    fn a_blank_name_falls_back_to_the_host_name() {
        let env = |key: &str| match key {
            "LANOMAT_TERMINAL_NAME" => Some("   ".to_string()),
            "HOSTNAME" => Some("lan-pc-07.local".to_string()),
            _ => None,
        };

        assert_eq!(terminal_name_with(env), "lan-pc-07.local");
    }

    #[test]
    fn without_any_name_the_terminal_is_a_pc() {
        assert_eq!(terminal_name_with(|_| None), "PC");
    }

    #[test]
    fn a_long_name_is_clipped_to_the_server_column() {
        let long = "x".repeat(NAME_MAX + 20);
        let env = |_key: &str| Some(long.clone());

        assert_eq!(terminal_name_with(env).chars().count(), NAME_MAX);
    }
}
