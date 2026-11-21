//! LANoMAT Launcher desktop shell.
//!
//! The Rust side owns auth, the install plan, downloads and extraction; the
//! static UI renders state and progress events. Every command returns JSON with
//! stable field names (see `clients/launcher/app/ui/README.md`), every failure is
//! a plain string, and no message ever contains the bearer token.

mod arcade;
mod deeplink;
mod env;
mod errors;
mod heartbeat;
mod installs;

use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::{Duration, Instant};

use launcher_core::api::{ApiClient, ApiError};
use launcher_core::auth::{device_name, DeviceLogin, LoginError, LoginPoll};
use launcher_core::download::{origin_of, CancelToken};
use launcher_core::install::{
    launch_command_for, required_file_groups, InstallEngine, InstallOptions, InstallPhase,
    InstallProgress,
};
use launcher_core::state::{self, LauncherConfig};
use launcher_core::types::{MeUser, Os, RecipePlan, RecipeSummary, ServerInfo, ServerList};
use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager, State};
use tauri_plugin_deep_link::DeepLinkExt;
use tokio::sync::Mutex;

pub use arcade::ArcadeMode;
pub use deeplink::{is_valid_slug, parse_deep_link, DeepLinkAction, DeepLinkEvent};
pub use env::{EnvironmentView, UpdateView};
pub use installs::InstallRecord;

/// How long the public `/launcher` page may take before the footer stays quiet.
const UPDATE_TIMEOUT: Duration = Duration::from_secs(8);
/// The most HTML the update check reads; the Inertia page is far smaller.
const MAX_PAGE_BYTES: usize = 1 << 20;

/// Progress of the one running install. `phase` is `planning|downloading|
/// extracting|patching|verifying|ready|cancelled|error`.
pub const EVENT_INSTALL: &str = "install-progress";
/// A valid `lanomat://install|launch/<slug>` link, `{action, slug}`.
pub const EVENT_DEEP_LINK: &str = "deep-link";

/// The `install-progress` payload: the core `InstallProgress` shape plus the
/// recipe slug, so the UI can ignore events from a previous run.
#[derive(Debug, Clone, Serialize)]
pub struct InstallEvent {
    pub slug: String,
    #[serde(flatten)]
    pub progress: InstallProgress,
}

/// What the shell can say about a recorded install without downloading anything:
/// `ok` mirrors `InstallEngine::verify_install`, `exists` says whether the
/// recorded folder is still there (gone means reinstall, present but incomplete
/// means repair), and `missing` names the absent requirement groups, with
/// alternatives joined by `or` (only when none of them exists).
#[derive(Debug, Clone, Serialize)]
pub struct InstallCheck {
    pub ok: bool,
    pub exists: bool,
    pub missing: Vec<String>,
}

#[derive(Default)]
struct Inner {
    api: Option<ApiClient>,
    login: Option<DeviceLogin>,
    config: Option<LauncherConfig>,
    pending_url: Option<String>,
    /// A link that arrived before the launcher was paired (or before the UI was
    /// listening); replayed after connect, or taken by the UI.
    pending_deep_link: Option<deeplink::DeepLink>,
    cancel: CancelToken,
    installing: bool,
}

impl Inner {
    fn paired(&self) -> bool {
        self.api.is_some() && self.config.is_some()
    }

    fn token(&self) -> Option<String> {
        self.config.as_ref().map(|config| config.token.clone())
    }

    /// Forget the pairing itself: the API client (and with it the bearer
    /// token), the stored config, a half-finished device login and the server
    /// address. The address also feeds the update hint, so no stale release
    /// hint survives. A pending deep link is kept: it belongs to the user's
    /// intent, not to the pairing, and the next connect replays it.
    fn forget_pairing(&mut self) {
        self.api = None;
        self.config = None;
        self.login = None;
        self.pending_url = None;
    }

    /// Signing out is local-only and must leave no session behind: the pairing
    /// goes, and so does a pending deep link (it must not replay into the next
    /// pairing, where it would act on a game the user did not ask for now).
    fn sign_out(&mut self) {
        self.forget_pairing();
        self.pending_deep_link = None;
    }
}

pub struct AppState {
    dir: PathBuf,
    inner: Mutex<Inner>,
    /// The fleet heartbeat's own state (what this terminal is doing) plus the
    /// wake-up signal that makes a state change report immediately.
    heartbeat: heartbeat::Heartbeat,
}

impl AppState {
    fn new(dir: PathBuf) -> Self {
        let config = state::load(&dir).ok().flatten();
        let api = config.as_ref().and_then(|config| {
            ApiClient::new(&config.url)
                .ok()
                .map(|api| api.with_token(&config.token))
        });
        let pending_url = config.as_ref().map(|config| config.url.clone());

        Self {
            dir,
            inner: Mutex::new(Inner {
                api,
                login: None,
                config,
                pending_url,
                pending_deep_link: None,
                cancel: CancelToken::new(),
                installing: false,
            }),
            heartbeat: heartbeat::Heartbeat::new(),
        }
    }

    /// The server no longer accepts the stored token (401/403 or no token at
    /// all). Drop the pairing in memory and on disk *before* the error reaches
    /// the UI, so `get_status` reports `paired: false` and the UI lands on
    /// connect instead of reconnecting with the same dead token forever.
    async fn forget_pairing(&self) {
        let mut inner = self.inner.lock().await;
        inner.forget_pairing();

        // The token is already gone from memory; a failed delete only means
        // the next start would read it again, which is worth a log, not a
        // second error the UI has to show.
        if let Err(error) = state::delete(&self.dir) {
            tracing::warn!(target: "lanomat::auth", %error, "could not delete the rejected pairing");
        }
    }
}

#[derive(Debug, Clone, Serialize)]
pub struct StatusView {
    pub paired: bool,
    pub url: Option<String>,
    pub user: Option<MeUser>,
    pub server: ServerInfo,
    pub installs: Vec<InstallRecord>,
    pub installing: bool,
}

#[derive(Debug, Clone, Serialize)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum ConnectPollView {
    Pending { remaining: u64 },
    SlowDown { interval: u64 },
    Connected { user: MeUser, server: ServerInfo },
    Failed { message: String },
}

#[derive(Debug, Clone, Serialize)]
pub struct DeviceView {
    pub user_code: String,
    pub verification_uri: String,
    pub verification_uri_complete: Option<String>,
    pub expires_in: u64,
    pub interval: u64,
    /// What the phone scans: the server's `verification_uri_complete`
    /// (`/mcp/connect?code=…`), or the verification address with the code
    /// appended when the server sent only the bare URI. The UI draws the QR
    /// from exactly this string, so the printed code and the scanned link can
    /// never disagree.
    pub qr_payload: String,
}

/// The link behind the QR code. Kept next to [`DeviceView`] (not in the UI)
/// because it is the one payload a camera — and a server approval — depends on.
fn verification_payload(device: &launcher_core::types::DeviceStart) -> String {
    let complete = device
        .verification_uri_complete
        .as_deref()
        .map(str::trim)
        .filter(|uri| !uri.is_empty());

    if let Some(complete) = complete {
        return complete.to_string();
    }

    let base = device.verification_uri.trim();
    let code = device.user_code.trim();

    if base.contains('?') || code.is_empty() {
        return base.to_string();
    }

    format!("{}?code={}", base.trim_end_matches('/'), code)
}

impl From<launcher_core::types::DeviceStart> for DeviceView {
    fn from(device: launcher_core::types::DeviceStart) -> Self {
        let qr_payload = verification_payload(&device);

        Self {
            user_code: device.user_code,
            verification_uri: device.verification_uri,
            verification_uri_complete: device.verification_uri_complete,
            expires_in: device.expires_in,
            interval: device.interval,
            qr_payload,
        }
    }
}

fn api_or_error(inner: &Inner) -> Result<ApiClient, String> {
    inner
        .api
        .clone()
        .ok_or_else(|| "Nicht verbunden — zuerst mit LANoMAT verbinden.".to_string())
}

fn invalid_slug() -> String {
    "Ungültige Spiel-Kennung.".to_string()
}

/// Only a token the server actually rejected may drop the pairing. A network
/// blip, a 500, an unreadable body or a bad URL must never sign the user out:
/// retrying those is exactly what the UI's retry buttons are for.
fn token_rejected(error: &ApiError) -> bool {
    error.is_auth()
}

/// Why a token-bearing command failed, in the two pieces the shell needs: the
/// redacted message for the UI, and whether the stored token is dead. The token
/// itself is never carried here, so no error path can leak it.
struct Failure {
    message: String,
    auth: bool,
}

impl Failure {
    fn from_api(error: ApiError, secret: Option<&str>) -> Self {
        Self {
            message: errors::redact(error.to_string(), secret),
            auth: token_rejected(&error),
        }
    }

    fn other(message: String) -> Self {
        Self {
            message,
            auth: false,
        }
    }
}

/// Finish a token-bearing call. A rejected token is forgotten before the error
/// reaches the UI, so the next `get_status` says `paired: false`; every other
/// failure leaves the pairing untouched.
async fn settle<T>(state: &AppState, result: Result<T, Failure>) -> Result<T, String> {
    match result {
        Ok(value) => Ok(value),
        Err(failure) => {
            if failure.auth {
                state.forget_pairing().await;
            }

            Err(failure.message)
        }
    }
}

#[tauri::command]
async fn get_status(state: State<'_, AppState>) -> Result<StatusView, String> {
    let inner = state.inner.lock().await;
    let config = inner.config.as_ref();

    Ok(StatusView {
        paired: inner.paired(),
        url: inner
            .pending_url
            .clone()
            .or_else(|| config.map(|config| config.url.clone())),
        user: config.and_then(|config| config.user.clone()),
        server: ServerInfo {
            name: config
                .and_then(|config| config.server_name.clone())
                .unwrap_or_default(),
            url: config.map(|config| config.url.clone()).unwrap_or_default(),
            version: config
                .and_then(|config| config.server_version.clone())
                .unwrap_or_default(),
        },
        installs: installs::load(&state.dir),
        installing: inner.installing,
    })
}

#[tauri::command]
async fn connect_start(
    state: State<'_, AppState>,
    server_url: String,
) -> Result<DeviceView, String> {
    let api = ApiClient::new(&server_url).map_err(|error| error.to_string())?;
    // The device label on the server's approved-devices list and the name the
    // header shows are the same fact: this terminal, not the event.
    let login = DeviceLogin::start(&api, &device_name(&env::hostname()), Instant::now())
        .await
        .map_err(|error| error.to_string())?;
    let device = login.device.clone();

    {
        let mut inner = state.inner.lock().await;
        inner.login = Some(login);
        inner.pending_url = Some(server_url);
        inner.api = None;
    }

    Ok(device.into())
}

#[tauri::command]
async fn connect_poll(
    app: AppHandle,
    state: State<'_, AppState>,
) -> Result<ConnectPollView, String> {
    let (mut login, api, token) = {
        let mut inner = state.inner.lock().await;
        let login = inner
            .login
            .take()
            .ok_or_else(|| "Keine laufende Anmeldung.".to_string())?;
        let url = inner
            .pending_url
            .clone()
            .ok_or_else(|| "Keine Serveradresse.".to_string())?;
        let token = inner.token();
        let api = ApiClient::new(&url).map_err(|error| error.to_string())?;

        (login, api, token)
    };

    let now = Instant::now();

    match login.poll(&api, now).await {
        Ok(LoginPoll::Pending) => {
            let remaining = login.expires_in(Instant::now()).as_secs();
            state.inner.lock().await.login = Some(login);

            Ok(ConnectPollView::Pending { remaining })
        }
        Ok(LoginPoll::SlowDown { interval }) => {
            let interval = interval.as_secs();
            state.inner.lock().await.login = Some(login);

            Ok(ConnectPollView::SlowDown { interval })
        }
        Ok(LoginPoll::Done {
            token: access_token,
            expires_at,
            ..
        }) => {
            let api = api.with_token(&access_token);
            let me = api
                .me()
                .await
                .map_err(|error| errors::redact(error.to_string(), Some(access_token.as_str())))?;

            let mut config = LauncherConfig::new(
                api.base_url().trim_end_matches('/').to_string(),
                access_token,
                expires_at,
            );
            config.user = Some(me.user.clone());
            config.server_name = Some(me.server.name.clone());
            config.server_version = Some(me.server.version.clone());
            state::save(&state.dir, &config).map_err(|error| error.to_string())?;

            let pending = {
                let mut inner = state.inner.lock().await;
                inner.api = Some(api);
                inner.login = None;
                inner.pending_url = Some(config.url.clone());
                inner.config = Some(config);
                inner.pending_deep_link.take()
            };

            if let Some(link) = pending {
                let _ = app.emit(EVENT_DEEP_LINK, DeepLinkEvent::from(link));
            }

            // The monitor should show this terminal the moment it exists, not
            // on the next 30 s tick.
            state.heartbeat.paired();

            Ok(ConnectPollView::Connected {
                user: me.user,
                server: me.server,
            })
        }
        Err(error) => {
            let message = errors::redact(error.to_string(), token.as_deref());

            // A network blip must not end the login: put the login back so the
            // UI keeps polling with the server's interval.
            if matches!(&error, LoginError::Api(ApiError::Network(_))) {
                state.inner.lock().await.login = Some(login);

                return Err(message);
            }

            Ok(ConnectPollView::Failed { message })
        }
    }
}

#[tauri::command]
async fn logout(state: State<'_, AppState>) -> Result<(), String> {
    // Say goodbye while the token still exists: the monitor shows a free
    // cabinet now instead of waiting out three missed ticks. Best effort, a
    // logout never depends on the network.
    heartbeat::say_goodbye(&state).await;

    let mut inner = state.inner.lock().await;
    state::delete(&state.dir).map_err(|error| error.to_string())?;
    // Local-only: the server token is revoked on `/mcp/connect`. Clear the
    // whole session, including the pending deep link and the server address
    // that feeds the update hint.
    inner.sign_out();
    Ok(())
}

/// The one recipe this build's install engine has a dedicated path for. The
/// engine is generic now (per-recipe verify steps from the plan, launch target
/// from the plan); UT2004 keeps its own `System/` verifier and OldUnreal patch
/// path, and every other recipe runs through the plan-driven fallback.
pub const LAUNCHER_ENGINE_SLUG: &str = "unreal-tournament-2004";

/// Whether this launcher build can drive a recipe: install it (dedicated
/// engine or the server's generic flag) or open its browser `play_url`. UT2004
/// stays supported because this build has the dedicated path; an older server
/// sends no capability flag, so the slug is the back-compat fallback.
pub fn launcher_supports(recipe: &RecipeSummary) -> bool {
    recipe.slug == LAUNCHER_ENGINE_SLUG
        || recipe.launcher_supported
        || recipe
            .play_url
            .as_deref()
            .is_some_and(|url| !url.trim().is_empty())
}

/// What `list_games` hands the UI: the whole shared server catalogue, each
/// entry marked with what this build can do with it.
///
/// The catalogue belongs to the server (the orga publishes it once for the
/// event), the *installs* belong to this terminal (each machine pairs on its
/// own and keeps its own ledger). The pilot head stays first so the primary
/// action of the screen is the game that is actually installable here.
fn prepare_games(mut recipes: Vec<RecipeSummary>) -> Vec<RecipeSummary> {
    for recipe in &mut recipes {
        recipe.launcher_supported = launcher_supports(recipe);
    }

    // Stable sort: the supported pilot first, the rest in the server's order
    // (title) instead of being dropped.
    recipes.sort_by_key(|recipe| !launcher_supports(recipe));

    recipes
}

#[tauri::command]
async fn list_games(state: State<'_, AppState>) -> Result<Vec<RecipeSummary>, String> {
    let (api, token) = {
        let inner = state.inner.lock().await;
        (api_or_error(&inner)?, inner.token())
    };

    let result = match api.recipes().await {
        Ok(recipes) => Ok(prepare_games(recipes)),
        Err(error) => Err(Failure::from_api(error, token.as_deref())),
    };

    settle(&state, result).await
}

/// The aggregated server browser for the chat: active/recent event servers
/// plus permanent ones, projected by the server without player names.
#[tauri::command]
async fn list_servers(state: State<'_, AppState>) -> Result<ServerList, String> {
    let (api, token) = {
        let inner = state.inner.lock().await;
        (api_or_error(&inner)?, inner.token())
    };

    let result = api
        .servers()
        .await
        .map_err(|error| Failure::from_api(error, token.as_deref()));

    settle(&state, result).await
}

#[tauri::command]
async fn get_plan(state: State<'_, AppState>, slug: String) -> Result<RecipePlan, String> {
    if !is_valid_slug(&slug) {
        return Err(invalid_slug());
    }

    let (api, token) = {
        let inner = state.inner.lock().await;
        (api_or_error(&inner)?, inner.token())
    };

    let result = api
        .plan(&slug, Os::current())
        .await
        .map_err(|error| Failure::from_api(error, token.as_deref()));

    settle(&state, result).await
}

#[tauri::command]
async fn install(
    app: AppHandle,
    state: State<'_, AppState>,
    slug: String,
) -> Result<InstallRecord, String> {
    if !is_valid_slug(&slug) {
        return Err(invalid_slug());
    }

    let (api, cancel, token) = {
        let mut inner = state.inner.lock().await;

        if inner.installing {
            return Err("Eine Installation läuft bereits.".to_string());
        }

        let api = api_or_error(&inner)?;
        let token = inner.token();
        inner.cancel = CancelToken::new();
        inner.installing = true;

        (api, inner.cancel.clone(), token)
    };

    let progress_app = app.clone();
    let progress_slug = slug.clone();
    let on_progress = Arc::new(move |progress: InstallProgress| {
        let _ = progress_app.emit(
            EVENT_INSTALL,
            InstallEvent {
                slug: progress_slug.clone(),
                progress,
            },
        );
    });

    // The monitor names the game this cabinet is working on while it installs.
    state.heartbeat.install_started(&slug).await;

    let result = run_install(&app, &slug, api, token, cancel, on_progress, &state.dir).await;

    {
        let mut inner = state.inner.lock().await;
        inner.installing = false;
    }

    // The ledger changed (or the run failed): report the new installed list.
    state.heartbeat.install_finished().await;

    settle(&state, result).await
}

async fn run_install(
    app: &AppHandle,
    slug: &str,
    api: ApiClient,
    bearer: Option<String>,
    cancel: CancelToken,
    on_progress: Arc<dyn Fn(InstallProgress) + Send + Sync>,
    dir: &Path,
) -> Result<InstallRecord, Failure> {
    let secret = bearer.clone();

    let outcome = async {
        let plan = api
            .plan(slug, Os::current())
            .await
            .map_err(|error| Failure::from_api(error, secret.as_deref()))?;
        let version = plan.recipe.version.clone();
        // Keep the OS-specific start command in the local ledger so a launch
        // after install works without another server round-trip.
        let launch_command = launch_command_for(&plan, Os::current());

        let outcome = InstallEngine::install(InstallOptions {
            plan,
            bearer,
            // The token is scoped to the paired server, never to a file host
            // named by the plan.
            bearer_origin: origin_of(api.base_url()),
            target_dir: None,
            cancel,
            on_progress,
        })
        .await
        .map_err(|error| Failure::other(errors::install(&error, secret.as_deref())))?;

        let record = InstallRecord {
            slug: slug.to_string(),
            target_dir: outcome.target_dir,
            version,
            verified: outcome.verified,
            launch_command,
        };

        installs::save(dir, &record).map_err(|error| {
            Failure::other(errors::redact(
                format!("Die Installationsliste konnte nicht gespeichert werden: {error}"),
                secret.as_deref(),
            ))
        })?;

        Ok::<InstallRecord, Failure>(record)
    }
    .await;

    if let Err(failure) = &outcome {
        // The engine reports `ready` itself; the shell owns the two ends it
        // cannot report: a cancelled run and a failed one.
        let phase = if errors::is_cancelled(&failure.message) {
            InstallPhase::Cancelled
        } else {
            InstallPhase::Error
        };

        let _ = app.emit(
            EVENT_INSTALL,
            InstallEvent {
                slug: slug.to_string(),
                progress: InstallProgress::phase(phase, failure.message.clone()),
            },
        );
    }

    outcome
}

#[tauri::command]
async fn cancel_install(state: State<'_, AppState>) -> Result<(), String> {
    let inner = state.inner.lock().await;
    inner.cancel.cancel();
    Ok(())
}

/// Check one recorded install against the disk. A missing ledger entry is the
/// only error: a moved target or incomplete `System/` is a fact for the UI, not
/// a failure. Nothing is downloaded or executed.
#[tauri::command]
async fn verify_install(state: State<'_, AppState>, slug: String) -> Result<InstallCheck, String> {
    if !is_valid_slug(&slug) {
        return Err(invalid_slug());
    }

    let record = installs::find(&state.dir, &slug)
        .ok_or_else(|| "Das Spiel ist noch nicht installiert.".to_string())?;

    Ok(check_install(Path::new(&record.target_dir), &record))
}

/// The pure part of `verify_install`, testable without an `AppState`.
///
/// The UT2004 pilot keeps its `System/` groups. A generic recipe is checked
/// against the verified paths its own install recorded (the plan's
/// `file_exists` checks), so NFSU2 does not have to look like UT2004.
fn check_install(target: &Path, record: &InstallRecord) -> InstallCheck {
    let exists = target.is_dir();
    let generic = record.slug != LAUNCHER_ENGINE_SLUG;

    let (ok, missing) = if generic && !record.verified.is_empty() {
        let missing = record
            .verified
            .iter()
            .filter(|file| !recorded_path_exists(target, file))
            .cloned()
            .collect::<Vec<_>>();

        (missing.is_empty(), missing)
    } else {
        let ok = launcher_core::install::verify_install(target).is_ok();
        let missing = if ok {
            Vec::new()
        } else {
            // A group is missing only when none of its alternatives exists — the
            // Linux build accepts `System/ut2004-bin-amd64` or `System/UT2004`.
            required_file_groups(Os::current())
                .iter()
                .filter(|group| !group.iter().any(|file| target.join(file).is_file()))
                .map(|group| group.join(" or "))
                .collect()
        };

        (ok, missing)
    };

    InstallCheck {
        ok,
        exists,
        missing,
    }
}

/// A recorded verify path may keep the recipe's spelling (`speed.exe`) while
/// the Windows archive stores `SPEED.EXE`; on Linux the case still differs.
/// Walk the path case-insensitively, exactly like the install verifier.
fn recorded_path_exists(target: &Path, relative: &str) -> bool {
    let mut current = target.to_path_buf();

    for part in relative.replace('\\', "/").split('/') {
        if part.is_empty() || part == "." {
            continue;
        }

        let exact = current.join(part);

        if exact.exists() {
            current = exact;
            continue;
        }

        let Ok(entries) = std::fs::read_dir(&current) else {
            return false;
        };
        let Some(found) = entries.flatten().find(|entry| {
            entry
                .file_name()
                .to_string_lossy()
                .eq_ignore_ascii_case(part)
        }) else {
            return false;
        };
        current = found.path();
    }

    current.exists()
}

#[tauri::command]
async fn launch(state: State<'_, AppState>, slug: String) -> Result<String, String> {
    if !is_valid_slug(&slug) {
        return Err(invalid_slug());
    }

    let record = installs::find(&state.dir, &slug)
        .ok_or_else(|| "Das Spiel ist noch nicht installiert.".to_string())?;
    let target = PathBuf::from(&record.target_dir);

    if !target.is_dir() {
        return Err(
            "Der Installationsordner fehlt — bitte das Spiel neu installieren.".to_string(),
        );
    }

    let exe = match record.launch_command.as_deref() {
        Some(command) => InstallEngine::launch_stored(&target, command),
        None => InstallEngine::launch(&target),
    }
    .map_err(|error| match error {
        launcher_core::install::InstallError::Verification(file) => {
            format!("launch failed: {file}")
        }
        other => other.to_string(),
    })?;

    // The monitor shows "playing <game>" until that process is gone; the
    // heartbeat checks the executable path every tick.
    state.heartbeat.launched(&slug, &exe).await;

    Ok(exe.to_string_lossy().to_string())
}

/// The URL schemes the shell will hand to the OS. `steam://` and `ut2004://`
/// are the two game join lists the server's `joinOptions` use; http(s) covers
/// verification, server pages and launcher updates.
const OPEN_SCHEMES: [&str; 4] = ["https://", "http://", "steam://", "ut2004://"];

fn is_allowed_open_url(url: &str) -> bool {
    let lower = url.to_ascii_lowercase();

    OPEN_SCHEMES.iter().any(|scheme| lower.starts_with(scheme))
        && !url.chars().any(|c| c.is_whitespace() || c.is_control())
}

#[tauri::command]
async fn open_url(url: String) -> Result<(), String> {
    let url = url.trim();

    if !is_allowed_open_url(url) {
        return Err("Nur http(s)-, steam://- und ut2004://-Links werden geöffnet.".to_string());
    }

    open::that(url).map_err(|error| format!("Link konnte nicht geöffnet werden: {error}"))
}

#[tauri::command]
async fn get_environment(
    state: State<'_, AppState>,
    mode: State<'_, ArcadeMode>,
) -> Result<EnvironmentView, String> {
    Ok(env::view(&state.dir, *mode))
}

/// Escape / gamepad B in the UI. Leaves the arcade fullscreen when the mode
/// allows it; a kiosk cabinet answers `false` so the UI can say why nothing
/// happened. `true` means "the fullscreen was the step back, stay on the
/// screen"; `false` means there was nothing to leave and the UI may go back a
/// screen instead.
#[tauri::command]
async fn leave_fullscreen(app: AppHandle, mode: State<'_, ArcadeMode>) -> Result<bool, String> {
    if mode.kiosk {
        return Ok(false);
    }

    let Some(window) = app.get_webview_window("main") else {
        return Ok(false);
    };

    if !window.is_fullscreen().unwrap_or(false) {
        return Ok(false);
    }

    window
        .set_fullscreen(false)
        .map_err(|error| format!("Vollbild konnte nicht verlassen werden: {error}"))?;

    Ok(true)
}

/// One heartbeat right now, for the UI's own status line. Never an error: an
/// unpaired launcher sends nothing and a failed send reports `sent: false` with
/// a redacted reason, because the background task keeps trying anyway.
#[tauri::command]
async fn send_heartbeat(state: State<'_, AppState>) -> Result<heartbeat::HeartbeatView, String> {
    Ok(heartbeat::send_now(&state).await)
}

/// The UI's gamepad state (Gamepad API `gamepadconnected`/`disconnected`), so
/// the monitor can mark a cabinet that has a controller plugged in.
#[tauri::command]
async fn set_gamepad(state: State<'_, AppState>, connected: bool) -> Result<bool, String> {
    state.heartbeat.set_gamepad(connected).await;

    Ok(connected)
}

/// Compare the running build with what the server publishes on its public
/// `/launcher` page. Best effort by design: an unreachable server, a missing
/// release or an unexpected page all mean "no hint", never an error the UI has
/// to show while the library works.
#[tauri::command]
async fn check_launcher_update(state: State<'_, AppState>) -> Result<UpdateView, String> {
    let base = {
        let inner = state.inner.lock().await;

        inner
            .config
            .as_ref()
            .map(|config| config.url.clone())
            .or_else(|| inner.pending_url.clone())
    };

    let Some(base) = base else {
        return Ok(env::update_view(None, None));
    };

    Ok(fetch_launcher_update(&base).await)
}

async fn fetch_launcher_update(base: &str) -> UpdateView {
    let Some(page) = launcher_page_url(base) else {
        return env::update_view(None, None);
    };

    let Ok(client) = reqwest::Client::builder()
        .user_agent(concat!("lanomat-launcher/", env!("CARGO_PKG_VERSION")))
        .connect_timeout(Duration::from_secs(5))
        .timeout(UPDATE_TIMEOUT)
        .build()
    else {
        return env::update_view(None, Some(page));
    };

    let html = match client
        .get(&page)
        .header(reqwest::header::ACCEPT, "text/html")
        .send()
        .await
    {
        Ok(response) if response.status().is_success() => match response.bytes().await {
            Ok(bytes) => {
                let bytes = &bytes[..bytes.len().min(MAX_PAGE_BYTES)];

                String::from_utf8_lossy(bytes).into_owned()
            }
            Err(_) => return env::update_view(None, Some(page)),
        },
        _ => return env::update_view(None, Some(page)),
    };

    env::update_view(env::release_from_page(&html), Some(page))
}

/// The `/launcher` route lives at the server root, whatever path the stored
/// server address carries. A non-http(s) or host-less address yields `None`.
fn launcher_page_url(base: &str) -> Option<String> {
    let mut url = reqwest::Url::parse(base.trim()).ok()?;

    if !matches!(url.scheme(), "http" | "https") || url.host_str().is_none_or(str::is_empty) {
        return None;
    }

    url.set_path("/launcher");
    url.set_query(None);
    url.set_fragment(None);

    Some(url.to_string())
}

/// A deep link that arrived before the UI was listening (cold start) or before
/// the launcher was paired. Exactly one consumer gets it.
#[tauri::command]
async fn take_pending_deep_link(
    state: State<'_, AppState>,
) -> Result<Option<DeepLinkEvent>, String> {
    let link = state.inner.lock().await.pending_deep_link.take();

    Ok(link.map(DeepLinkEvent::from))
}

/// Parse a raw `lanomat://` argument, tell the UI when the event is live, and
/// keep it pending while the launcher is unpaired so connect can replay it.
async fn process_deep_link(app: &AppHandle, raw: &str, live: bool) {
    let Some(link) = parse_deep_link(raw) else {
        tracing::debug!(target: "lanomat::deeplink", "ignored a link that is not lanomat://install|launch/<slug>");
        return;
    };

    let state = app.state::<AppState>();
    let paired = {
        let inner = state.inner.lock().await;
        inner.paired()
    };

    if live {
        let _ = app.emit(EVENT_DEEP_LINK, DeepLinkEvent::from(link.clone()));
    }

    if !paired || !live {
        let mut inner = state.inner.lock().await;
        inner.pending_deep_link = Some(link);
    }
}

/// The link a cold start should queue. `urls` is the process's start-up
/// argument list; every unparseable entry is skipped and the last valid one
/// wins, like the most recent command-line argument would. Pure, so the choice
/// is testable without a Tauri app.
fn select_start_up_link(
    urls: impl DoubleEndedIterator<Item = String>,
) -> Option<deeplink::DeepLink> {
    urls.filter_map(|raw| parse_deep_link(&raw)).next_back()
}

/// Queue the link the process was started with, synchronously and before the
/// webview can call `take_pending_deep_link`.
///
/// This must not be a spawned task: `get_current()` is only populated for the
/// cold start, and the UI's first `take_pending_deep_link` can win a race
/// against a task that still has to wait for the state mutex — the link would
/// then sit pending until a connect that never comes (the launcher is already
/// paired), i.e. the deep link would silently die.
fn prime_pending_deep_link(app: &AppHandle) {
    let Ok(Some(urls)) = app.deep_link().get_current() else {
        return;
    };

    let Some(link) = select_start_up_link(urls.into_iter().map(|url| url.to_string())) else {
        tracing::debug!(
            target: "lanomat::deeplink",
            "the start-up arguments carry no valid lanomat:// link"
        );
        return;
    };

    tracing::debug!(
        target: "lanomat::deeplink",
        action = link.action.as_str(),
        slug = link.slug.as_str(),
        "queued the start-up deep link"
    );

    let state = app.state::<AppState>();

    // `setup` runs on the main thread before the event loop, so the state mutex
    // is uncontended here: `try_lock` succeeds and keeps the write synchronous.
    // The fallback exists only so a future lock holder cannot silently swallow
    // the link.
    match state.inner.try_lock() {
        Ok(mut inner) => inner.pending_deep_link = Some(link),
        Err(_) => {
            tauri::async_runtime::block_on(state.inner.lock()).pending_deep_link = Some(link);
        }
    };
}

fn focus_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

fn register_deep_links(app: &AppHandle) {
    // Best effort: a source checkout without a desktop entry still works, the
    // link then has to reach the process as an argument (single instance).
    if let Err(error) = app.deep_link().register_all() {
        tracing::debug!(target: "lanomat::deeplink", %error, "could not register lanomat://");
    }

    // The cold-start link is primed in `setup`, not here: the UI's first
    // `take_pending_deep_link` is the one and only consumer of it.
    let handle = app.clone();
    app.deep_link().on_open_url(move |event| {
        for url in event.urls() {
            let raw = url.to_string();
            let app = handle.clone();

            tauri::async_runtime::spawn(async move {
                process_deep_link(&app, &raw, true).await;
            });
        }
    });
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run(mode: ArcadeMode) {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    tracing::debug!(
        target: "lanomat::startup",
        version = env::VERSION,
        commit = env::COMMIT,
        os = std::env::consts::OS,
        arcade = mode.arcade,
        kiosk = mode.kiosk
    );

    let dir = state::config_dir();

    tauri::Builder::default()
        // The single-instance plugin must be registered first: a second start
        // hands its arguments (including a `lanomat://` link) to this instance
        // and exits. Its `deep-link` feature forwards them to the deep-link
        // plugin above our `on_open_url` handler.
        .plugin(tauri_plugin_single_instance::init(|app, _argv, _cwd| {
            focus_main(app);
        }))
        .plugin(tauri_plugin_deep_link::init())
        .manage(AppState::new(dir))
        .manage(mode)
        .invoke_handler(tauri::generate_handler![
            get_status,
            connect_start,
            connect_poll,
            logout,
            list_games,
            list_servers,
            get_plan,
            install,
            cancel_install,
            verify_install,
            launch,
            open_url,
            get_environment,
            leave_fullscreen,
            send_heartbeat,
            set_gamepad,
            check_launcher_update,
            take_pending_deep_link
        ])
        .setup(move |app| {
            // Before the UI can ask: the cold-start link is queued synchronously
            // (see `prime_pending_deep_link`), so exactly one consumer sees it.
            prime_pending_deep_link(app.handle());
            register_deep_links(app.handle());

            // The fleet heartbeat runs from the first tick, but only sends once
            // a pairing exists (`Heartbeat::paired` wakes it right after
            // connect), so the bootstrap exchange and `restart-terminal.sh`
            // both work without a server.
            heartbeat::spawn(app.handle().clone());

            // `install-terminal.sh --arcade|--kiosk` (or `LANOMAT_ARCADE=1`)
            // starts the cabinet in fullscreen. The mode was parsed once in
            // `main`; `enter` is idempotent, so an already-fullscreen restart
            // does not flicker.
            arcade::enter(app.handle(), mode);

            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while building LANoMAT Launcher")
        .run(|app, event| {
            // A clean close is a clean goodbye: one final `offline` heartbeat,
            // bounded so an unreachable server cannot hold the exit. Logging
            // out already sent its own goodbye; the server just overwrites the
            // same row.
            if matches!(event, tauri::RunEvent::ExitRequested { .. }) {
                let state = app.state::<AppState>();
                heartbeat::goodbye(&state);
            }
        });
}

#[cfg(test)]
mod tests {
    use super::*;

    fn device(
        complete: Option<&str>,
        verification_uri: &str,
        user_code: &str,
    ) -> launcher_core::types::DeviceStart {
        launcher_core::types::DeviceStart {
            device_code: "dev-secret".into(),
            user_code: user_code.into(),
            verification_uri: verification_uri.into(),
            verification_uri_complete: complete.map(str::to_string),
            expires_in: 600,
            interval: 5,
        }
    }

    #[test]
    fn the_qr_payload_is_the_servers_complete_verification_link() {
        let view = DeviceView::from(device(
            Some("https://lan.raute1.org/mcp/connect?code=ABCD-1234"),
            "https://lan.raute1.org/mcp/connect",
            "ABCD-1234",
        ));

        assert_eq!(
            view.qr_payload,
            "https://lan.raute1.org/mcp/connect?code=ABCD-1234"
        );
        assert!(view.qr_payload.contains("/mcp/connect"));
        assert!(view.qr_payload.contains("ABCD-1234"));
    }

    #[test]
    fn without_a_complete_link_the_payload_carries_the_code_anyway() {
        let view = DeviceView::from(device(
            None,
            "https://lan.raute1.org/mcp/connect",
            "ABCD-1234",
        ));

        assert_eq!(
            view.qr_payload,
            "https://lan.raute1.org/mcp/connect?code=ABCD-1234"
        );

        // A bare link that already has a query is never bent into a second one.
        let with_query = DeviceView::from(device(
            None,
            "https://lan.raute1.org/mcp/connect?lang=de",
            "ABCD-1234",
        ));

        assert_eq!(
            with_query.qr_payload,
            "https://lan.raute1.org/mcp/connect?lang=de"
        );

        // No code from the server: the link alone is still scannable.
        let bare = DeviceView::from(device(None, "https://lan.raute1.org/mcp/connect", ""));

        assert_eq!(bare.qr_payload, "https://lan.raute1.org/mcp/connect");
    }

    #[test]
    fn a_blank_complete_link_falls_back_to_the_verification_address() {
        let view = DeviceView::from(device(
            Some("   "),
            "https://lan.raute1.org/mcp/connect/",
            "WXYZ-9876",
        ));

        assert_eq!(
            view.qr_payload,
            "https://lan.raute1.org/mcp/connect?code=WXYZ-9876"
        );
    }

    #[test]
    fn the_device_view_exposes_the_qr_payload_to_the_ui() {
        let json = serde_json::to_value(DeviceView::from(device(
            Some("https://lan.raute1.org/mcp/connect?code=ABCD-1234"),
            "https://lan.raute1.org/mcp/connect",
            "ABCD-1234",
        )))
        .unwrap();

        assert_eq!(json["user_code"], "ABCD-1234");
        assert_eq!(
            json["qr_payload"],
            "https://lan.raute1.org/mcp/connect?code=ABCD-1234"
        );
        assert_eq!(json["interval"], 5);
        assert_eq!(json["expires_in"], 600);
    }

    #[test]
    fn the_install_event_keeps_the_progress_shape_and_names_the_slug() {
        let event = InstallEvent {
            slug: "unreal-tournament-2004".into(),
            progress: InstallProgress::phase(InstallPhase::Verifying, "Installation wird geprüft"),
        };
        let json = serde_json::to_value(&event).unwrap();

        assert_eq!(json["slug"], "unreal-tournament-2004");
        assert_eq!(json["phase"], "verifying");
        assert_eq!(json["message"], "Installation wird geprüft");
        assert_eq!(json["bytes_done"], 0);
        assert_eq!(json["bytes_total"], 0);
        assert_eq!(json["bytes_per_second"], 0);
        assert!(json["file"].is_null() || json.get("file").is_none());
    }

    #[test]
    fn cancelled_and_failed_phases_are_serialised_as_the_ui_expects() {
        for (phase, name) in [
            (InstallPhase::Cancelled, "cancelled"),
            (InstallPhase::Error, "error"),
            (InstallPhase::Ready, "ready"),
        ] {
            let json = serde_json::to_value(InstallProgress::phase(phase, "x")).unwrap();
            assert_eq!(json["phase"], name);
        }
    }

    #[test]
    fn the_install_check_tells_a_repair_from_a_reinstall() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("ut2004");
        let groups = required_file_groups(Os::current());
        let record = InstallRecord {
            slug: LAUNCHER_ENGINE_SLUG.into(),
            target_dir: target.to_string_lossy().to_string(),
            version: None,
            verified: Vec::new(),
            launch_command: None,
        };

        let absent = check_install(&target, &record);

        assert!(!absent.ok);
        assert!(!absent.exists);
        assert_eq!(absent.missing.len(), groups.len());

        std::fs::create_dir_all(target.join("System")).unwrap();

        // One file per group is enough; take the last alternative so the
        // platform's fallback name is exercised too.
        let mut created = Vec::new();

        for group in groups {
            let file = group.last().unwrap();

            std::fs::write(target.join(file), b"x").unwrap();
            created.push(*file);
        }

        let complete = check_install(&target, &record);

        assert!(complete.ok);
        assert!(complete.exists);
        assert!(complete.missing.is_empty());

        std::fs::remove_file(target.join(created[0])).unwrap();

        let incomplete = check_install(&target, &record);

        assert!(!incomplete.ok);
        assert!(incomplete.exists);
        assert_eq!(incomplete.missing, vec![groups[0].join(" or ")]);
    }

    #[test]
    fn a_generic_install_checks_its_recorded_plan_paths() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("nfsu2");
        std::fs::create_dir_all(target.join("scripts")).unwrap();
        std::fs::write(target.join("SPEED.EXE"), b"x").unwrap();
        std::fs::write(target.join("scripts/net_u2.asi"), b"x").unwrap();

        let record = InstallRecord {
            slug: "need-for-speed-underground-2".into(),
            target_dir: target.to_string_lossy().to_string(),
            version: Some("1.2 + NFS Online".into()),
            verified: vec![
                "speed.exe".into(),
                "scripts/net_u2.asi".into(),
                "dinput8.dll".into(),
            ],
            launch_command: Some(
                "WINEDLLOVERRIDES=\"dinput8=n,b\" wine \"{{target}}/speed.exe\"".into(),
            ),
        };

        let incomplete = check_install(&target, &record);
        assert!(!incomplete.ok);
        assert_eq!(incomplete.missing, vec!["dinput8.dll".to_string()]);

        std::fs::write(target.join("dinput8.dll"), b"x").unwrap();
        assert!(check_install(&target, &record).ok);
    }

    #[test]
    fn a_bad_slug_never_reaches_the_server() {
        assert!(is_valid_slug("unreal-tournament-2004"));
        assert!(!is_valid_slug("../etc/passwd"));
        assert!(!is_valid_slug("UT2004"));
    }

    #[test]
    fn a_cold_start_queues_the_last_valid_link_and_nothing_else() {
        // The last valid argument wins, junk between them is skipped.
        let link = select_start_up_link(
            [
                "lanomat://launch/ut2004",
                "not a link",
                "lanomat://install/unreal-tournament-2004",
            ]
            .map(String::from)
            .into_iter(),
        );

        assert_eq!(
            link,
            parse_deep_link("lanomat://install/unreal-tournament-2004")
        );

        // No arguments, or none that carry a valid link: nothing is queued, so
        // the UI's `take_pending_deep_link` stays `null`.
        assert_eq!(select_start_up_link(Vec::<String>::new().into_iter()), None);
        assert_eq!(
            select_start_up_link(vec!["lanomat://remove/ut2004".to_string()].into_iter()),
            None
        );
    }

    #[test]
    fn open_url_keeps_to_the_join_and_web_schemes() {
        for url in [
            "https://lan.test/servers",
            "http://127.0.0.1:8000/events/lan-2026/servers",
            "steam://connect/203.0.113.5:27015",
            "steam://run/13230//203.0.113.5:27015",
            "ut2004://203.0.113.5:7777",
        ] {
            assert!(is_allowed_open_url(url), "{url}");
        }

        for url in [
            "",
            "file:///etc/passwd",
            "javascript:alert(1)",
            "steam://connect/203.0.113.5:27015 with space",
            "https://lan.test/a\nb",
        ] {
            assert!(!is_allowed_open_url(url), "{url}");
        }
    }

    #[test]
    fn the_update_check_looks_at_the_server_root() {
        assert_eq!(
            launcher_page_url("https://lan.example").as_deref(),
            Some("https://lan.example/launcher")
        );
        assert_eq!(
            launcher_page_url("https://lan.example/some/prefix/").as_deref(),
            Some("https://lan.example/launcher")
        );
        assert_eq!(
            launcher_page_url("http://127.0.0.1:8000").as_deref(),
            Some("http://127.0.0.1:8000/launcher")
        );
        assert_eq!(launcher_page_url("lan.example"), None);
        assert_eq!(launcher_page_url("ftp://lan.example"), None);
        assert_eq!(launcher_page_url("https://"), None);
    }

    #[test]
    fn only_a_rejected_token_drops_the_stored_pairing() {
        for (error, rejected) in [
            (ApiError::NotSignedIn, true),
            (
                ApiError::Http {
                    status: 401,
                    message: "unauthorized".into(),
                },
                true,
            ),
            (
                ApiError::Http {
                    status: 403,
                    message: "forbidden".into(),
                },
                true,
            ),
            (
                ApiError::Http {
                    status: 404,
                    message: "not found".into(),
                },
                false,
            ),
            (
                ApiError::Http {
                    status: 500,
                    message: "boom".into(),
                },
                false,
            ),
            (ApiError::Network("timed out".into()), false),
            (ApiError::Decode("bad json".into()), false),
            (ApiError::Url("no host".into()), false),
        ] {
            assert_eq!(token_rejected(&error), rejected, "{error}");
        }
    }

    #[test]
    fn a_failure_never_carries_the_token() {
        let failure = Failure::from_api(
            ApiError::Http {
                status: 401,
                message: "token abc123 was rejected".into(),
            },
            Some("abc123"),
        );

        assert!(failure.auth);
        assert_eq!(
            failure.message,
            "server answered HTTP 401: token *** was rejected"
        );
        assert!(!failure.message.contains("abc123"));
    }

    #[test]
    fn signing_out_forgets_the_pairing_and_the_pending_link() {
        let mut inner = Inner {
            pending_url: Some("https://lan.test".into()),
            pending_deep_link: parse_deep_link("lanomat://install/ut2004"),
            ..Default::default()
        };

        inner.sign_out();

        assert!(inner.pending_url.is_none());
        assert!(inner.pending_deep_link.is_none());
        assert!(inner.token().is_none());
    }

    #[tokio::test]
    async fn a_rejected_pairing_is_dropped_in_memory_and_on_disk() {
        let dir = tempfile::tempdir().unwrap();
        let config = LauncherConfig::new("https://lan.test".into(), "tok".into(), String::new());
        state::save(dir.path(), &config).unwrap();

        let app = AppState::new(dir.path().to_path_buf());

        {
            let inner = app.inner.lock().await;
            assert!(inner.paired());
            assert_eq!(inner.token().as_deref(), Some("tok"));
        }

        app.forget_pairing().await;

        {
            let inner = app.inner.lock().await;
            assert!(!inner.paired());
            assert!(inner.api.is_none());
            assert!(inner.config.is_none());
            assert!(inner.token().is_none());
        }

        assert!(state::load(dir.path()).unwrap().is_none());
    }

    fn summary(slug: &str, title: &str) -> RecipeSummary {
        RecipeSummary {
            slug: slug.into(),
            title: title.into(),
            summary: None,
            version: None,
            status: None,
            platforms: Vec::new(),
            installable: Some(true),
            launcher_supported: false,
            play_url: None,
        }
    }

    #[test]
    fn every_published_recipe_stays_in_the_catalogue_with_its_launcher_flag() {
        let games = prepare_games(vec![
            summary("half-life", "Half-Life"),
            summary("unreal-tournament-2004", "Unreal Tournament 2004"),
            summary("openra", "OpenRA"),
        ]);

        let slugs: Vec<&str> = games.iter().map(|game| game.slug.as_str()).collect();

        // The pilot is first because it is the one installable tile, but the
        // rest of the shared catalogue is not filtered out.
        assert_eq!(slugs, vec!["unreal-tournament-2004", "half-life", "openra"]);
        assert!(games[0].launcher_supported);
        assert!(games[1..].iter().all(|game| !game.launcher_supported));
    }

    #[test]
    fn the_server_flag_enables_the_generic_engine_for_a_new_recipe() {
        let mut advertised = summary("need-for-speed-underground-2", "NFSU2");
        advertised.launcher_supported = true;

        let games = prepare_games(vec![advertised]);

        // `installable` is the server's answer and is passed through; the
        // generic engine accepts a recipe the updated server marks supported.
        assert_eq!(games[0].installable, Some(true));
        assert!(games[0].launcher_supported);
    }

    #[test]
    fn a_browser_recipe_is_supported_by_its_play_url() {
        let mut browser = summary("need-for-speed-underground-2-browser", "NFSU2 (Browser)");
        browser.installable = Some(false);
        browser.play_url = Some("https://nfsu2.xyz/".into());

        let games = prepare_games(vec![browser]);

        assert!(games[0].launcher_supported);
        assert_eq!(games[0].installable, Some(false));
        assert_eq!(games[0].play_url.as_deref(), Some("https://nfsu2.xyz/"));
    }
}
