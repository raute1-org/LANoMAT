//! The command layer: everything the UI can do, as plain async methods over `voice-core`. It has
//! no Tauri types in it, so the connect flow, push-to-talk mapping and settings are unit-testable.
//! The Tauri commands in `lib.rs` are one-line wrappers.

use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};

use serde::Serialize;
use tokio::task::JoinHandle;
use voice_core::api::{
    ApiClient, DeviceAuthorization, FileTokenStore, PollState, PollStep, Room, StoredCredentials,
    TokenStore,
};
use voice_core::audio::cpal_io::{list_devices, CpalSink, CpalSource};
use voice_core::audio::synthetic::{ClickConfig, ClickSource};
use voice_core::audio::{AudioSink, AudioSource, NullSink};
use voice_core::control::{self, PttCommand};
use voice_core::session::{PttHandle, Session, SessionEvent, SessionOptions};
use voice_core::Error;

use crate::settings::{self, Settings};
use crate::view::{RoomRef, RoomView};

/// Microphone (absent when listen-only) and speaker of a session.
type AudioPair = (Option<Box<dyn AudioSource>>, Box<dyn AudioSink>);

/// Called with a snapshot whenever the room view changes.
pub type Emit = Arc<dyn Fn(&RoomView) + Send + Sync>;

/// An error as the UI shows it: a coarse kind to branch on, a German sentence for the person.
#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct UiError {
    pub kind: &'static str,
    pub message: String,
}

impl UiError {
    fn new(kind: &'static str, message: impl Into<String>) -> Self {
        Self {
            kind,
            message: message.into(),
        }
    }
}

/// German, actionable text for every core error; the person never sees English internals.
pub fn german(e: &Error) -> String {
    match e {
        Error::InvalidUrl(_) => {
            "Das ist keine gültige Serveradresse. Beispiel: https://lan.example.org".into()
        }
        Error::NotLoggedIn => "Dieses Gerät ist nicht verbunden. Verbinde es zuerst.".into(),
        Error::Unauthorized => {
            "Die Verbindung wurde getrennt oder ist abgelaufen. Verbinde das Gerät neu.".into()
        }
        Error::PairingDenied => "Die Verbindung wurde auf der Webseite abgelehnt.".into(),
        Error::PairingExpired => "Der Code ist abgelaufen. Starte die Verbindung neu.".into(),
        Error::InvalidGrant => "Der Code ist unbekannt oder schon benutzt. Starte neu.".into(),
        Error::Network(_) => {
            "Der Server ist nicht erreichbar. Prüfe die Adresse und das Netzwerk.".into()
        }
        Error::BadResponse(_) => {
            "Der Server hat unerwartet geantwortet. Prüfe, ob die Adresse zu LANoMAT gehört.".into()
        }
        Error::NotFound(_) => "Diesen Raum gibt es nicht oder du darfst ihn nicht betreten.".into(),
        Error::RoomArchived => "Dieser Raum ist archiviert.".into(),
        Error::Unavailable => {
            "Sprachchat ist auf dem Server gerade nicht verfügbar. Versuche es gleich noch einmal."
                .into()
        }
        Error::RateLimited => {
            "Zu viele Anfragen. Warte einen Moment und versuche es erneut.".into()
        }
        Error::Server { status, .. } => format!("Der Server meldet einen Fehler (HTTP {status})."),
        Error::Usage(m) => m.clone(),
        Error::Store(_) => "Die lokalen Zugangsdaten sind nicht lesbar. Verbinde neu.".into(),
        Error::Room(_) => "Die Verbindung zum Sprachraum ist fehlgeschlagen.".into(),
        Error::Audio(m) => format!("Audiogerät: {m}"),
        Error::Control(m) => format!("Steuer-Socket: {m}"),
        Error::Io { .. } => "Eine lokale Datei oder ein Gerät ist nicht zugänglich.".into(),
    }
}

fn kind_name(e: &Error) -> &'static str {
    use voice_core::ErrorKind::*;
    match e {
        Error::PairingDenied => "denied",
        Error::PairingExpired | Error::InvalidGrant => "expired",
        _ => match e.kind() {
            Usage => "usage",
            Auth => "auth",
            Network => "network",
            Server => "server",
            Local => "local",
        },
    }
}

impl From<Error> for UiError {
    fn from(e: Error) -> Self {
        UiError::new(kind_name(&e), german(&e))
    }
}

/// Result of [`Engine::status`].
#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct Status {
    /// `signed_out`, `connected`, `expired` (token revoked) or `unreachable` (token kept).
    pub state: &'static str,
    pub server_url: String,
    pub user_name: Option<String>,
    pub message: Option<String>,
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct ConnectStart {
    pub user_code: String,
    pub verification_url: String,
    pub verification_url_complete: Option<String>,
    pub expires_in: u64,
    pub device_name: String,
    /// Wait this long before the first `connect_poll`.
    pub next_in_ms: u64,
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
#[serde(tag = "status", rename_all = "snake_case")]
pub enum ConnectPoll {
    /// Not decided yet; poll again after `next_in_ms`.
    Pending {
        next_in_ms: u64,
    },
    Connected {
        user_name: String,
    },
    /// `kind` is `denied`, `expired`, `network` or `no_flow`.
    Failed {
        kind: &'static str,
        message: String,
    },
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct Devices {
    pub inputs: Vec<String>,
    pub outputs: Vec<String>,
    /// True when no real devices are used (headless test mode).
    pub synthetic: bool,
}

struct PendingLogin {
    client: ApiClient,
    auth: DeviceAuthorization,
    device_name: String,
    state: PollState,
}

/// What a push-to-talk command leads to. `muted` is the hard mute: it blocks transmitting but
/// never blocks letting go of the key.
pub fn resolve_ptt(current: bool, cmd: PttCommand, muted: bool) -> Result<bool, String> {
    let next = control::apply(current, cmd);
    if cmd != PttCommand::Status && next && muted {
        return Err("muted: lift the mute first".into());
    }
    Ok(next)
}

pub struct Engine {
    store: FileTokenStore,
    settings_path: PathBuf,
    settings: Mutex<Settings>,
    emit: Emit,
    synthetic: bool,
    view: Mutex<RoomView>,
    ptt: Mutex<Option<PttHandle>>,
    my_name: Mutex<String>,
    session: tokio::sync::Mutex<Option<Session>>,
    events_task: Mutex<Option<JoinHandle<()>>>,
    pending: tokio::sync::Mutex<Option<PendingLogin>>,
    generation: AtomicU64,
    hard_muted: AtomicBool,
    runtime: Mutex<Option<tokio::runtime::Handle>>,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl Engine {
    /// `synthetic` replaces sound devices with a test tone and a discarding sink (headless runs).
    pub fn new(
        store: FileTokenStore,
        settings_path: PathBuf,
        emit: Emit,
        synthetic: bool,
    ) -> Arc<Self> {
        let settings = settings::load(&settings_path);
        Arc::new(Self {
            store,
            settings_path,
            settings: Mutex::new(settings),
            emit,
            synthetic,
            view: Mutex::new(RoomView::idle()),
            ptt: Mutex::new(None),
            my_name: Mutex::new(String::new()),
            session: tokio::sync::Mutex::new(None),
            events_task: Mutex::new(None),
            pending: tokio::sync::Mutex::new(None),
            generation: AtomicU64::new(0),
            hard_muted: AtomicBool::new(false),
            runtime: Mutex::new(None),
        })
    }

    /// The runtime LiveKit's track handles spawn onto. Mute and unmute are plain sync calls that
    /// start a task internally; the shortcut handler and sync Tauri commands run on threads
    /// without a runtime, so those calls enter this one first (otherwise the SDK panics).
    pub fn set_runtime(&self, handle: tokio::runtime::Handle) {
        *lock(&self.runtime) = Some(handle);
    }

    fn in_runtime<R>(&self, f: impl FnOnce() -> R) -> R {
        let handle = lock(&self.runtime).clone();
        let _guard = handle.as_ref().map(|h| h.enter());
        f()
    }

    // ---- settings ----

    pub fn settings(&self) -> Settings {
        lock(&self.settings).clone()
    }

    /// Persists the settings (never the token) and keeps them in memory.
    pub fn save_settings(&self, new: Settings) -> Result<Settings, UiError> {
        let mut new = new.normalized();
        // The server URL belongs to the connect flow; a stale copy from the UI must not win.
        new.server_url = lock(&self.settings).server_url.clone();
        settings::save(&self.settings_path, &new).map_err(|e| {
            UiError::new(
                "local",
                format!("Die Einstellungen konnten nicht gespeichert werden ({e})."),
            )
        })?;
        *lock(&self.settings) = new.clone();
        Ok(new)
    }

    pub fn settings_path(&self) -> &std::path::Path {
        &self.settings_path
    }

    // ---- connect flow ----

    fn emit_view(&self) {
        let snapshot = lock(&self.view).clone();
        (self.emit)(&snapshot);
    }

    pub fn view(&self) -> RoomView {
        lock(&self.view).clone()
    }

    fn client(&self) -> Result<ApiClient, Error> {
        let creds = self.store.load()?.ok_or(Error::NotLoggedIn)?;
        Ok(ApiClient::new(&creds.base_url)?.with_token(creds.access_token))
    }

    /// Where we are at start-up: signed out, signed in, token dead, or server unreachable.
    pub async fn status(&self) -> Status {
        let server_url = {
            let s = lock(&self.settings).server_url.clone();
            match self.store.load() {
                Ok(Some(c)) => c.base_url,
                _ => s,
            }
        };
        let client = match self.client() {
            Ok(c) => c,
            Err(Error::NotLoggedIn) => {
                return Status {
                    state: "signed_out",
                    server_url,
                    user_name: None,
                    message: None,
                }
            }
            Err(e) => {
                return Status {
                    state: "signed_out",
                    server_url,
                    user_name: None,
                    message: Some(german(&e)),
                }
            }
        };
        match client.me().await {
            Ok(me) => {
                *lock(&self.my_name) = me.name.clone();
                Status {
                    state: "connected",
                    server_url,
                    user_name: Some(me.name),
                    message: None,
                }
            }
            Err(Error::Unauthorized) => Status {
                state: "expired",
                server_url,
                user_name: None,
                message: Some(german(&Error::Unauthorized)),
            },
            Err(e) => Status {
                state: "unreachable",
                server_url,
                user_name: None,
                message: Some(german(&e)),
            },
        }
    }

    /// Starts the device authorization flow against `server_url`.
    pub async fn connect_start(&self, server_url: &str) -> Result<ConnectStart, UiError> {
        let client = ApiClient::new(server_url)?;
        let device_name = voice_core::api::default_device_name("LANoMAT Voice");
        let auth = client.start_device_flow(&device_name).await?;
        let state = PollState::new(auth.interval, auth.expires_in);
        let start = ConnectStart {
            user_code: auth.user_code.clone(),
            verification_url: auth.verification_url.clone(),
            verification_url_complete: auth.verification_url_complete.clone(),
            expires_in: auth.expires_in,
            device_name: device_name.clone(),
            next_in_ms: state.first_wait().as_millis() as u64,
        };
        {
            let mut s = lock(&self.settings);
            s.server_url = client.base_url().to_owned();
            let _ = settings::save(&self.settings_path, &s);
        }
        *self.pending.lock().await = Some(PendingLogin {
            client,
            auth,
            device_name,
            state,
        });
        Ok(start)
    }

    /// One poll of the token endpoint, advanced through voice-core's state machine. The UI calls
    /// this again after `next_in_ms` while the answer is `pending`.
    pub async fn connect_poll(&self) -> ConnectPoll {
        let mut guard = self.pending.lock().await;
        let Some(p) = guard.as_mut() else {
            return ConnectPoll::Failed {
                kind: "no_flow",
                message: "Es läuft keine Verbindung. Starte sie neu.".into(),
            };
        };
        let event = p.client.poll_token_once(&p.auth.device_code).await;
        match p.state.on(event) {
            PollStep::Wait(d) => ConnectPoll::Pending {
                next_in_ms: d.as_millis() as u64,
            },
            PollStep::Fail(e) => {
                *guard = None;
                ConnectPoll::Failed {
                    kind: match kind_name(&e) {
                        "denied" => "denied",
                        "expired" => "expired",
                        _ => "network",
                    },
                    message: german(&e),
                }
            }
            PollStep::Done(token) => {
                let p = guard.take().expect("checked above");
                drop(guard);
                let creds = StoredCredentials {
                    base_url: p.client.base_url().to_owned(),
                    access_token: token.clone(),
                    device_name: p.device_name.clone(),
                };
                if let Err(e) = self.store.save(&creds) {
                    return ConnectPoll::Failed {
                        kind: "network",
                        message: german(&e),
                    };
                }
                let user_name = match p.client.clone().with_token(token).me().await {
                    Ok(me) => me.name,
                    Err(_) => String::new(),
                };
                *lock(&self.my_name) = user_name.clone();
                ConnectPoll::Connected { user_name }
            }
        }
    }

    /// Whether the app may open `url` in the browser: only the pairing page of the pending flow or
    /// the `/talk/connect` page of the server we are paired with (never an arbitrary URL).
    pub async fn url_allowed(&self, url: &str) -> bool {
        if let Some(p) = self.pending.lock().await.as_ref() {
            if url == p.auth.verification_url
                || Some(url) == p.auth.verification_url_complete.as_deref()
            {
                return true;
            }
        }
        let base = match self.store.load() {
            Ok(Some(c)) => c.base_url,
            _ => lock(&self.settings).server_url.clone(),
        };
        let base = base.trim_end_matches('/');
        !base.is_empty()
            && (url == format!("{base}/talk/connect")
                || url.starts_with(&format!("{base}/talk/connect?")))
            && (url.starts_with("https://") || url.starts_with("http://"))
    }

    /// Abandons a pending connect flow (the user went back).
    pub async fn connect_cancel(&self) {
        *self.pending.lock().await = None;
    }

    /// Forgets the token locally. Revoking it on the server happens on the website.
    pub async fn logout(self: &Arc<Self>) -> Result<(), UiError> {
        self.leave_room().await;
        self.store.clear()?;
        lock(&self.my_name).clear();
        Ok(())
    }

    // ---- rooms ----

    pub async fn list_rooms(&self) -> Result<Vec<Room>, UiError> {
        Ok(self.client()?.rooms().await?)
    }

    pub async fn join_room(self: &Arc<Self>, room_id: u64) -> Result<RoomView, UiError> {
        let mut slot = self.session.lock().await;
        self.teardown(&mut slot).await;

        let client = self.client()?;
        let rooms = client.rooms().await?;
        let room = rooms
            .iter()
            .find(|r| r.id == room_id)
            .ok_or_else(|| Error::NotFound(format!("room {room_id}")))?;
        let settings = self.settings();
        let muted = self.hard_muted.load(Ordering::SeqCst);
        *lock(&self.view) = RoomView::connecting(
            RoomRef {
                id: room.id,
                name: room.name.clone(),
            },
            muted,
            settings.open_mic,
        );
        self.emit_view();

        match self
            .connect_session(&client, room_id, &settings, muted)
            .await
        {
            Ok(session) => {
                *slot = Some(session);
                Ok(self.view())
            }
            Err(e) => {
                *lock(&self.view) = RoomView::idle();
                self.emit_view();
                Err(e.into())
            }
        }
    }

    async fn connect_session(
        self: &Arc<Self>,
        client: &ApiClient,
        room_id: u64,
        settings: &Settings,
        muted: bool,
    ) -> Result<Session, Error> {
        let grant = client.room_token(room_id).await?;
        let (source, sink) = self.audio_pair(settings, grant.muted)?;
        let options = SessionOptions {
            audio: grant.audio.clone(),
            start_talking: settings.open_mic && !muted,
            audio_processing: !settings.headset_mode,
        };
        let mut session = Session::connect(&grant, source, sink, options).await?;
        let mut events = session.take_events().expect("events are taken once");
        let handle = session.ptt_handle();
        {
            let mut view = lock(&self.view);
            view.listen_only = session.is_listen_only();
            view.set_talking(handle.is_talking());
        }
        *lock(&self.ptt) = Some(handle);

        let generation = self.generation.fetch_add(1, Ordering::SeqCst) + 1;
        let me = lock(&self.my_name).clone();
        let this = self.clone();
        let task = tokio::spawn(async move {
            while let Some(event) = events.recv().await {
                if this.generation.load(Ordering::SeqCst) != generation {
                    break;
                }
                let ended = matches!(event, SessionEvent::Disconnected(_));
                let changed = lock(&this.view).apply(&event, &me);
                if ended {
                    *lock(&this.ptt) = None;
                    lock(&this.view).set_talking(false);
                }
                if changed || ended {
                    this.emit_view();
                }
                if ended {
                    break;
                }
            }
        });
        *lock(&self.events_task) = Some(task);
        Ok(session)
    }

    fn audio_pair(&self, settings: &Settings, listen_only: bool) -> Result<AudioPair, Error> {
        if self.synthetic {
            // A near-continuous tone, so the SFU's speaker detection reacts in headless runs.
            let (tone, _log) = ClickSource::new(ClickConfig {
                warmup: 0,
                measured: 36_000,
                burst: std::time::Duration::from_millis(950),
                freq_hz: 440.0,
                ..ClickConfig::default()
            });
            let source: Option<Box<dyn AudioSource>> = if listen_only {
                None
            } else {
                Some(Box::new(tone))
            };
            return Ok((source, Box::new(NullSink)));
        }
        let source: Option<Box<dyn AudioSource>> = if listen_only {
            None
        } else {
            Some(Box::new(CpalSource::new(settings.input_device.clone())))
        };
        let sink: Box<dyn AudioSink> = Box::new(CpalSink::new(settings.output_device.clone())?);
        Ok((source, sink))
    }

    async fn teardown(&self, slot: &mut Option<Session>) {
        self.generation.fetch_add(1, Ordering::SeqCst);
        if let Some(task) = lock(&self.events_task).take() {
            task.abort();
        }
        *lock(&self.ptt) = None;
        if let Some(session) = slot.take() {
            session.close().await;
        }
    }

    pub async fn leave_room(self: &Arc<Self>) {
        let mut slot = self.session.lock().await;
        self.teardown(&mut slot).await;
        let was_idle = lock(&self.view).room.is_none();
        *lock(&self.view) = RoomView {
            muted: self.hard_muted.load(Ordering::SeqCst),
            ..RoomView::idle()
        };
        if !was_idle {
            self.emit_view();
        }
    }

    // ---- push-to-talk and mute ----

    /// Applies a push-to-talk command to the running session. This is also what the control
    /// socket (`lanomat-voice ptt down|up`) and the global shortcut call.
    pub fn ptt_command(&self, cmd: PttCommand) -> Result<bool, String> {
        let guard = lock(&self.ptt);
        let Some(handle) = guard.as_ref() else {
            return Err("not in a room".into());
        };
        let current = handle.is_talking();
        let next = resolve_ptt(current, cmd, self.hard_muted.load(Ordering::SeqCst))?;
        if cmd == PttCommand::Status {
            return Ok(current);
        }
        let result = self.in_runtime(|| handle.set_talking(next))?;
        drop(guard);
        let changed = {
            let mut view = lock(&self.view);
            let changed = view.talking != result;
            view.set_talking(result);
            changed
        };
        if changed {
            self.emit_view();
        }
        Ok(result)
    }

    /// Hold-to-talk from the UI (`true` = key down).
    pub fn set_talking(&self, talking: bool) -> Result<bool, UiError> {
        let cmd = if talking {
            PttCommand::Down
        } else {
            PttCommand::Up
        };
        self.ptt_command(cmd).map_err(|m| {
            UiError::new(
                "ptt",
                if m.starts_with("muted") {
                    "Du bist stummgeschaltet. Hebe die Stummschaltung auf."
                } else if m.starts_with("not in") {
                    "Du bist in keinem Raum."
                } else {
                    "Dein Mikrofon ist gesperrt (Moderator oder kein Mikrofon)."
                },
            )
        })
    }

    /// Hard mute: lifting it restores open mic, push-to-talk stays released.
    pub fn set_muted(&self, muted: bool) -> RoomView {
        self.hard_muted.store(muted, Ordering::SeqCst);
        let open_mic = lock(&self.view).open_mic;
        {
            let guard = lock(&self.ptt);
            if let Some(handle) = guard.as_ref() {
                let talking = !muted && open_mic;
                if let Ok(t) = self.in_runtime(|| handle.set_talking(talking)) {
                    lock(&self.view).set_talking(t);
                }
            }
        }
        lock(&self.view).muted = muted;
        self.emit_view();
        self.view()
    }

    pub fn is_muted(&self) -> bool {
        self.hard_muted.load(Ordering::SeqCst)
    }

    // ---- devices ----

    pub fn list_devices(&self) -> Result<Devices, UiError> {
        if self.synthetic {
            return Ok(Devices {
                inputs: vec!["Testton (synthetisch)".into()],
                outputs: vec!["Verwerfen (synthetisch)".into()],
                synthetic: true,
            });
        }
        let (inputs, outputs) = list_devices()?;
        Ok(Devices {
            inputs,
            outputs,
            synthetic: false,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{header, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn engine(dir: &std::path::Path) -> Arc<Engine> {
        Engine::new(
            FileTokenStore::new(dir.join("credentials.json")),
            dir.join("settings.json"),
            Arc::new(|_| {}),
            true,
        )
    }

    async fn device_mock(server: &MockServer) {
        Mock::given(method("POST"))
            .and(path("/api/voice-client/device"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "device_code": "dc-secret",
                "user_code": "ABCD-EFGH",
                "verification_url": format!("{}/talk/connect", server.uri()),
                "verification_url_complete": format!("{}/talk/connect?code=ABCD-EFGH", server.uri()),
                "expires_in": 600,
                "interval": 5
            })))
            .mount(server)
            .await;
    }

    fn token_error(code: &str) -> ResponseTemplate {
        ResponseTemplate::new(400).set_body_json(serde_json::json!({ "error": code }))
    }

    #[tokio::test]
    async fn connect_flow_pending_then_approved_stores_the_token_outside_settings() {
        let server = MockServer::start().await;
        device_mock(&server).await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/token"))
            .respond_with(token_error("authorization_pending"))
            .up_to_n_times(1)
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/token"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "access_token": "lvc_test_token_value", "token_type": "Bearer", "expires_in": 100
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/me"))
            .and(header("authorization", "Bearer lvc_test_token_value"))
            .respond_with(ResponseTemplate::new(200).set_body_json(
                serde_json::json!({"id": 7, "name": "Anna", "role": "participant", "identity": "u7"}),
            ))
            .mount(&server)
            .await;

        let dir = tempfile::tempdir().unwrap();
        let engine = engine(dir.path());
        assert_eq!(engine.status().await.state, "signed_out");

        let start = engine.connect_start(&server.uri()).await.unwrap();
        assert_eq!(start.user_code, "ABCD-EFGH");
        assert_eq!(start.next_in_ms, 5000);
        assert!(start.verification_url.ends_with("/talk/connect"));

        assert_eq!(
            engine.connect_poll().await,
            ConnectPoll::Pending { next_in_ms: 5000 }
        );
        assert_eq!(
            engine.connect_poll().await,
            ConnectPoll::Connected {
                user_name: "Anna".into()
            }
        );
        // The flow is over; polling again is a clean "no flow".
        assert!(matches!(
            engine.connect_poll().await,
            ConnectPoll::Failed {
                kind: "no_flow",
                ..
            }
        ));

        let creds = std::fs::read_to_string(dir.path().join("credentials.json")).unwrap();
        assert!(creds.contains("lvc_test_token_value"));
        let settings = std::fs::read_to_string(dir.path().join("settings.json")).unwrap();
        assert!(!settings.contains("lvc_"), "{settings}");
        assert!(
            settings.contains(&server.uri()),
            "the server URL is remembered"
        );
        assert!(!settings.contains("dc-secret"));

        assert_eq!(engine.status().await.state, "connected");
        engine.logout().await.unwrap();
        assert_eq!(engine.status().await.state, "signed_out");
    }

    #[tokio::test]
    async fn denied_and_expired_end_the_flow_with_a_kind_and_german_text() {
        for (code, kind) in [("access_denied", "denied"), ("expired_token", "expired")] {
            let server = MockServer::start().await;
            device_mock(&server).await;
            Mock::given(method("POST"))
                .and(path("/api/voice-client/token"))
                .respond_with(token_error(code))
                .mount(&server)
                .await;
            let dir = tempfile::tempdir().unwrap();
            let engine = engine(dir.path());
            engine.connect_start(&server.uri()).await.unwrap();
            match engine.connect_poll().await {
                ConnectPoll::Failed { kind: k, message } => {
                    assert_eq!(k, kind);
                    assert!(message.contains("Code") || message.contains("abgelehnt"));
                }
                other => panic!("{other:?}"),
            }
            assert!(!dir.path().join("credentials.json").exists());
        }
    }

    #[tokio::test]
    async fn unreachable_server_and_bad_urls_are_reported_not_panicked() {
        let dir = tempfile::tempdir().unwrap();
        let engine = engine(dir.path());
        let bad = engine.connect_start("not a url").await.unwrap_err();
        assert_eq!(bad.kind, "usage");
        let down = engine
            .connect_start("http://127.0.0.1:1")
            .await
            .unwrap_err();
        assert_eq!(down.kind, "network");
        assert!(down.message.contains("nicht erreichbar"));
    }

    #[tokio::test]
    async fn revoked_token_is_reported_as_expired_and_rooms_need_a_login() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/me"))
            .respond_with(ResponseTemplate::new(401))
            .mount(&server)
            .await;
        let dir = tempfile::tempdir().unwrap();
        let engine = engine(dir.path());
        assert_eq!(engine.list_rooms().await.unwrap_err().kind, "auth");
        FileTokenStore::new(dir.path().join("credentials.json"))
            .save(&StoredCredentials {
                base_url: server.uri(),
                access_token: "lvc_dead".into(),
                device_name: "t".into(),
            })
            .unwrap();
        assert_eq!(engine.status().await.state, "expired");
    }

    #[tokio::test]
    async fn only_the_pairing_pages_may_be_opened_in_the_browser() {
        let dir = tempfile::tempdir().unwrap();
        settings::save(
            &dir.path().join("settings.json"),
            &Settings {
                server_url: "https://lan.example.test/".into(),
                ..Settings::default()
            },
        )
        .unwrap();
        let engine = engine(dir.path());
        assert!(
            engine
                .url_allowed("https://lan.example.test/talk/connect")
                .await
        );
        assert!(
            engine
                .url_allowed("https://lan.example.test/talk/connect?code=ABCD-EFGH")
                .await
        );
        assert!(
            !engine
                .url_allowed("https://evil.example/talk/connect")
                .await
        );
        assert!(!engine.url_allowed("file:///etc/passwd").await);
        assert!(!engine.url_allowed("https://lan.example.test/admin").await);
    }

    #[test]
    fn ptt_mapping_is_idempotent_and_respects_the_hard_mute() {
        assert_eq!(resolve_ptt(false, PttCommand::Down, false), Ok(true));
        assert_eq!(resolve_ptt(true, PttCommand::Down, false), Ok(true));
        assert_eq!(resolve_ptt(true, PttCommand::Up, false), Ok(false));
        assert_eq!(resolve_ptt(false, PttCommand::Toggle, false), Ok(true));
        assert_eq!(resolve_ptt(true, PttCommand::Status, false), Ok(true));
        // Muted: transmitting is refused, releasing and status are not.
        assert!(resolve_ptt(false, PttCommand::Down, true).is_err());
        assert!(resolve_ptt(false, PttCommand::Toggle, true).is_err());
        assert_eq!(resolve_ptt(true, PttCommand::Up, true), Ok(false));
        assert_eq!(resolve_ptt(false, PttCommand::Status, true), Ok(false));
    }

    #[tokio::test]
    async fn ptt_without_a_room_is_a_clear_error_and_settings_round_trip() {
        let dir = tempfile::tempdir().unwrap();
        let engine = engine(dir.path());
        assert_eq!(
            engine.ptt_command(PttCommand::Down),
            Err("not in a room".into())
        );
        assert_eq!(engine.set_talking(true).unwrap_err().kind, "ptt");
        let saved = engine
            .save_settings(Settings {
                open_mic: true,
                ptt_shortcut: " F13 ".into(),
                ..Settings::default()
            })
            .unwrap();
        assert_eq!(saved.ptt_shortcut, "F13");
        assert_eq!(
            saved.server_url, "",
            "the UI cannot overwrite the server URL"
        );
        let reloaded = Engine::new(
            FileTokenStore::new(dir.path().join("credentials.json")),
            dir.path().join("settings.json"),
            Arc::new(|_| {}),
            true,
        );
        assert!(reloaded.settings().open_mic);
    }

    #[test]
    fn synthetic_device_list_is_available_headless() {
        let dir = tempfile::tempdir().unwrap();
        let d = engine(dir.path()).list_devices().unwrap();
        assert!(d.synthetic && !d.inputs.is_empty() && !d.outputs.is_empty());
    }
}
