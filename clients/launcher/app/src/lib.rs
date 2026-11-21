//! LANoMAT Launcher desktop shell. The Rust side owns auth, the install plan,
//! downloads and extraction; the static UI renders state and progress events.

use std::path::PathBuf;
use std::sync::Arc;

use launcher_core::api::ApiClient;
use launcher_core::auth::{device_name, DeviceLogin, LoginError, LoginPoll};
use launcher_core::download::CancelToken;
use launcher_core::install::{InstallEngine, InstallOptions, InstallPhase, InstallProgress};
use launcher_core::state::{self, LauncherConfig};
use launcher_core::types::{DeviceStart, MeUser, RecipePlan, RecipeSummary, ServerInfo};
use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Emitter, Manager, State};
use tokio::sync::Mutex;

pub const EVENT_INSTALL: &str = "install-progress";

#[derive(Default)]
struct Inner {
    api: Option<ApiClient>,
    login: Option<DeviceLogin>,
    config: Option<LauncherConfig>,
    pending_url: Option<String>,
    cancel: CancelToken,
    installing: bool,
}

pub struct AppState {
    dir: PathBuf,
    inner: Mutex<Inner>,
}

impl AppState {
    fn new(dir: PathBuf) -> Self {
        let config = state::load(&dir).ok().flatten();
        let api = config
            .as_ref()
            .and_then(|config| ApiClient::new(&config.url).ok().map(|api| api.with_token(&config.token)));
        let pending_url = config.as_ref().map(|config| config.url.clone());

        Self {
            dir,
            inner: Mutex::new(Inner {
                api,
                login: None,
                config,
                pending_url,
                cancel: CancelToken::new(),
                installing: false,
            }),
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
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct InstallRecord {
    pub slug: String,
    pub target_dir: String,
    pub version: Option<String>,
    pub verified: Vec<String>,
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
}

impl From<DeviceStart> for DeviceView {
    fn from(device: DeviceStart) -> Self {
        Self {
            user_code: device.user_code,
            verification_uri: device.verification_uri,
            verification_uri_complete: device.verification_uri_complete,
            expires_in: device.expires_in,
            interval: device.interval,
        }
    }
}

fn installs_path(dir: &std::path::Path) -> PathBuf {
    dir.join("installs.json")
}

fn load_installs(dir: &std::path::Path) -> Vec<InstallRecord> {
    std::fs::read_to_string(installs_path(dir))
        .ok()
        .and_then(|raw| serde_json::from_str(&raw).ok())
        .unwrap_or_default()
}

fn save_install(dir: &std::path::Path, record: &InstallRecord) -> std::io::Result<()> {
    std::fs::create_dir_all(dir)?;
    let mut records = load_installs(dir);
    records.retain(|entry| entry.slug != record.slug);
    records.push(record.clone());
    let raw = serde_json::to_vec_pretty(&records).map_err(std::io::Error::other)?;
    std::fs::write(installs_path(dir), raw)
}

fn api_or_error(inner: &Inner) -> Result<ApiClient, String> {
    inner.api.clone().ok_or_else(|| "Nicht verbunden — zuerst mit LANoMAT verbinden.".to_string())
}

#[tauri::command]
async fn get_status(state: State<'_, AppState>) -> Result<StatusView, String> {
    let inner = state.inner.lock().await;
    let config = inner.config.as_ref();

    Ok(StatusView {
        paired: inner.api.is_some() && config.is_some(),
        url: inner.pending_url.clone().or_else(|| config.map(|config| config.url.clone())),
        user: config.and_then(|config| config.user.clone()),
        server: ServerInfo {
            name: config.and_then(|config| config.server_name.clone()).unwrap_or_default(),
            url: config.map(|config| config.url.clone()).unwrap_or_default(),
            version: config.and_then(|config| config.server_version.clone()).unwrap_or_default(),
        },
        installs: load_installs(&state.dir),
    })
}

#[tauri::command]
async fn connect_start(state: State<'_, AppState>, server_url: String) -> Result<DeviceView, String> {
    let api = ApiClient::new(&server_url).map_err(|error| error.to_string())?;
    let host = std::env::var("HOSTNAME")
        .or_else(|_| std::env::var("COMPUTERNAME"))
        .unwrap_or_else(|_| "PC".into());
    let login = DeviceLogin::start(&api, &device_name(&host), std::time::Instant::now())
        .await
        .map_err(|error| match error {
            LoginError::Api(error) => error.to_string(),
            other => other.to_string(),
        })?;
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
async fn connect_poll(state: State<'_, AppState>) -> Result<ConnectPollView, String> {
    let (login, api) = {
        let mut inner = state.inner.lock().await;
        let login = inner.login.clone().ok_or_else(|| "Keine laufende Anmeldung.".to_string())?;
        let url = inner
            .pending_url
            .clone()
            .ok_or_else(|| "Keine Serveradresse.".to_string())?;
        let api = ApiClient::new(&url).map_err(|error| error.to_string())?;
        (login, api)
    };

    let mut login = login;
    match login.poll(&api, std::time::Instant::now()).await {
        Ok(LoginPoll::Pending) => Ok(ConnectPollView::Pending {
            remaining: login.expires_in(std::time::Instant::now()).as_secs(),
        }),
        Ok(LoginPoll::SlowDown { interval }) => Ok(ConnectPollView::SlowDown {
            interval: interval.as_secs(),
        }),
        Ok(LoginPoll::Done { token, expires_at, .. }) => {
            let api = api.with_token(&token);
            let me = api.me().await.map_err(|error| error.to_string())?;
            let mut config = LauncherConfig::new(
                api.base_url().trim_end_matches('/').to_string(),
                token,
                expires_at,
            );
            config.user = Some(me.user.clone());
            config.server_name = Some(me.server.name.clone());
            config.server_version = Some(me.server.version.clone());
            state::save(&state.dir, &config).map_err(|error| error.to_string())?;

            let mut inner = state.inner.lock().await;
            inner.api = Some(api);
            inner.login = None;
            inner.pending_url = Some(config.url.clone());
            inner.config = Some(config);

            Ok(ConnectPollView::Connected {
                user: me.user,
                server: me.server,
            })
        }
        Err(error) => {
            let mut inner = state.inner.lock().await;
            inner.login = None;
            Ok(ConnectPollView::Failed {
                message: error.to_string(),
            })
        }
    }
}

#[tauri::command]
async fn logout(state: State<'_, AppState>) -> Result<(), String> {
    let mut inner = state.inner.lock().await;
    state::delete(&state.dir).map_err(|error| error.to_string())?;
    inner.api = None;
    inner.config = None;
    inner.login = None;
    inner.pending_url = None;
    Ok(())
}

#[tauri::command]
async fn list_games(state: State<'_, AppState>) -> Result<Vec<RecipeSummary>, String> {
    let api = {
        let inner = state.inner.lock().await;
        api_or_error(&inner)?
    };

    let mut recipes = api.recipes().await.map_err(|error| error.to_string())?;
    // The live build names only the UT2004 pilot. Other published recipes stay
    // in the stack but are not surfaced until they have a verified installer.
    recipes.retain(|recipe| recipe.slug == "unreal-tournament-2004");
    Ok(recipes)
}

#[tauri::command]
async fn get_plan(state: State<'_, AppState>, slug: String) -> Result<RecipePlan, String> {
    let api = {
        let inner = state.inner.lock().await;
        api_or_error(&inner)?
    };

    api.plan(&slug, launcher_core::types::Os::current())
        .await
        .map_err(|error| error.to_string())
}

#[tauri::command]
async fn install(
    app: AppHandle,
    state: State<'_, AppState>,
    slug: String,
) -> Result<InstallRecord, String> {
    let (api, cancel, token) = {
        let mut inner = state.inner.lock().await;
        if inner.installing {
            return Err("Eine Installation läuft bereits.".into());
        }
        let api = api_or_error(&inner)?;
        let token = inner.config.as_ref().map(|config| config.token.clone());
        inner.cancel = CancelToken::new();
        inner.installing = true;
        (api, inner.cancel.clone(), token)
    };

    let result = async {
        let plan = api
            .plan(&slug, launcher_core::types::Os::current())
            .await
            .map_err(|error| error.to_string())?;

        let app_for_progress = app.clone();
        let on_progress = Arc::new(move |progress: InstallProgress| {
            let _ = app_for_progress.emit(EVENT_INSTALL, progress);
        });

        let outcome = InstallEngine::install(InstallOptions {
            plan,
            bearer: token,
            target_dir: None,
            cancel,
            on_progress,
        })
        .await
        .map_err(|error| error.to_string())?;

        let record = InstallRecord {
            slug: slug.clone(),
            target_dir: outcome.target_dir,
            version: None,
            verified: outcome.verified,
        };
        save_install(&state.dir, &record).map_err(|error| error.to_string())?;

        Ok::<InstallRecord, String>(record)
    }
    .await;

    {
        let mut inner = state.inner.lock().await;
        inner.installing = false;
    }

    if result.is_err() {
        let _ = app.emit(
            EVENT_INSTALL,
            InstallProgress::phase(InstallPhase::Error, result.as_ref().err().cloned().unwrap_or_default()),
        );
    }

    result
}

#[tauri::command]
async fn cancel_install(state: State<'_, AppState>) -> Result<(), String> {
    let inner = state.inner.lock().await;
    inner.cancel.cancel();
    Ok(())
}

#[tauri::command]
async fn launch(state: State<'_, AppState>, slug: String) -> Result<String, String> {
    let record = load_installs(&state.dir)
        .into_iter()
        .find(|record| record.slug == slug)
        .ok_or_else(|| "Das Spiel ist noch nicht installiert.".to_string())?;
    let exe = InstallEngine::launch(std::path::Path::new(&record.target_dir)).map_err(|error| error.to_string())?;

    Ok(exe.to_string_lossy().to_string())
}

#[tauri::command]
async fn open_url(url: String) -> Result<(), String> {
    if !url.starts_with("https://") && !url.starts_with("http://") {
        return Err("Nur http(s)-Links werden geöffnet.".into());
    }

    open::that(url).map_err(|error| error.to_string())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let dir = launcher_core::state::config_dir();

    tauri::Builder::default()
        .plugin(tauri_plugin_deep_link::init())
        .manage(AppState::new(dir))
        .invoke_handler(tauri::generate_handler![
            get_status,
            connect_start,
            connect_poll,
            logout,
            list_games,
            get_plan,
            install,
            cancel_install,
            launch,
            open_url
        ])
        .setup(|app| {
            let _ = app.handle().clone();
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running LANoMAT Launcher");
}
