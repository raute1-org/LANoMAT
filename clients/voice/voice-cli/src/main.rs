//! `lanomat-voice`: command line client for LANoMAT web voice.
//!
//! Exit codes: 0 success, 1 local error (audio, files, socket), 2 usage error, 3 authentication
//! (not logged in, token rejected, pairing denied or expired), 4 network or LiveKit connection,
//! 5 the server refused (room not found, archived, voice unavailable).

use std::process::ExitCode;
use std::sync::Arc;

use clap::{Parser, Subcommand, ValueEnum};
use voice_core::api::{ApiClient, FileTokenStore, Room, StoredCredentials, TokenStore};
use voice_core::audio::cpal_io::{list_devices, CpalSink, CpalSource};
use voice_core::audio::synthetic::{ClickConfig, ClickSource};
use voice_core::audio::NullSink;
use voice_core::control::{self, PttCommand};
use voice_core::devtokens::{self, DevKeys};
use voice_core::latency_test::run_latency_test;
use voice_core::session::{Session, SessionEvent, SessionOptions};
use voice_core::{Error, ErrorKind, Result};

#[derive(Parser)]
#[command(
    name = "lanomat-voice",
    version,
    about = "Native voice client for LANoMAT web voice"
)]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Pair this device with your LANoMAT account (device code flow) and store the token.
    Login {
        /// Origin of your LANoMAT, e.g. https://<LAN_DOMAIN>
        base_url: String,
        /// Name shown on the approval page (default: this machine's host name).
        #[arg(long)]
        device_name: Option<String>,
    },
    /// Forget the stored token on this machine (revoke it under "Verbundene Geraete" on the website).
    Logout,
    /// List the voice rooms you may join.
    Rooms,
    /// Join a room by id or name; push-to-talk is driven by `lanomat-voice ptt`.
    Join {
        /// Room id (from `rooms`) or a case-insensitive name.
        room: String,
        /// Transmit continuously instead of waiting for push-to-talk.
        #[arg(long)]
        open_mic: bool,
        /// Substring of the input (microphone) device name; default device if omitted.
        #[arg(long)]
        input: Option<String>,
        /// Substring of the output (speaker) device name; default device if omitted.
        #[arg(long)]
        output: Option<String>,
        /// Do not create the push-to-talk control socket.
        #[arg(long)]
        no_socket: bool,
        /// Diagnostic: no sound devices. The microphone is a synthetic click train and received
        /// audio is discarded. For headless machines and for testing push-to-talk.
        #[arg(long)]
        synthetic_audio: bool,
    },
    /// Drive push-to-talk of a running `join` session through its control socket.
    Ptt { action: PttAction },
    /// List audio devices.
    Devices,
    /// Two-session click latency test against a LiveKit room (needs dev keys, see the README).
    LatencyTest {
        /// LiveKit room name to use for the probe.
        room: String,
        /// Measured bursts (one per second).
        #[arg(long, default_value_t = 20)]
        bursts: u32,
        /// Warm-up bursts that are sent but not counted.
        #[arg(long, default_value_t = 3)]
        warmup: u32,
    },
}

#[derive(Clone, Copy, ValueEnum)]
enum PttAction {
    Down,
    Up,
    Toggle,
    Status,
}

impl From<PttAction> for PttCommand {
    fn from(a: PttAction) -> Self {
        match a {
            PttAction::Down => PttCommand::Down,
            PttAction::Up => PttCommand::Up,
            PttAction::Toggle => PttCommand::Toggle,
            PttAction::Status => PttCommand::Status,
        }
    }
}

fn exit_code(kind: ErrorKind) -> u8 {
    match kind {
        ErrorKind::Local => 1,
        ErrorKind::Usage => 2,
        ErrorKind::Auth => 3,
        ErrorKind::Network => 4,
        ErrorKind::Server => 5,
    }
}

#[tokio::main]
async fn main() -> ExitCode {
    voice_core::install_crypto_provider();
    init_logging();
    let cli = Cli::parse();
    match run(cli.command).await {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("error: {e}");
            if matches!(e, Error::Unauthorized) {
                eprintln!("hint: the token was revoked or expired, run `lanomat-voice login <base-url>` again");
            }
            ExitCode::from(exit_code(e.kind()))
        }
    }
}

fn init_logging() {
    // Quiet by default; `RUST_LOG=voice_core=debug,livekit=info` for diagnostics.
    if let Ok(filter) = std::env::var("RUST_LOG") {
        let _ = tracing_subscriber::fmt()
            .with_env_filter(filter)
            .with_writer(std::io::stderr)
            .try_init();
    }
}

async fn run(command: Command) -> Result<()> {
    match command {
        Command::Login {
            base_url,
            device_name,
        } => login(&base_url, device_name).await,
        Command::Logout => logout(),
        Command::Rooms => rooms().await,
        Command::Join {
            room,
            open_mic,
            input,
            output,
            no_socket,
            synthetic_audio,
        } => join(&room, open_mic, input, output, no_socket, synthetic_audio).await,
        Command::Ptt { action } => ptt(action.into()).await,
        Command::Devices => devices(),
        Command::LatencyTest {
            room,
            bursts,
            warmup,
        } => latency_test(&room, bursts, warmup).await,
    }
}

fn store() -> Result<FileTokenStore> {
    FileTokenStore::default_location()
}

fn authenticated_client() -> Result<ApiClient> {
    let creds = store()?.load()?.ok_or(Error::NotLoggedIn)?;
    Ok(ApiClient::new(&creds.base_url)?.with_token(creds.access_token))
}

async fn login(base_url: &str, device_name: Option<String>) -> Result<()> {
    let client = ApiClient::new(base_url)?;
    let name = device_name.unwrap_or_else(|| voice_core::api::default_device_name("lanomat-voice"));
    let auth = client.start_device_flow(&name).await?;

    println!(
        "To connect this device, open:\n\n    {}\n",
        auth.verification_url
    );
    println!("and enter the code:\n\n    {}\n", auth.user_code);
    if let Some(complete) = &auth.verification_url_complete {
        println!("(or open {complete} directly, the code is prefilled)\n");
    }
    println!(
        "Compare the device name \"{name}\" on the page, then approve. Waiting (the code is valid for {} minutes)...",
        auth.expires_in / 60
    );

    let token = client.wait_for_token(&auth, tokio::time::sleep).await?;
    let store = store()?;
    store.save(&StoredCredentials {
        base_url: client.base_url().to_owned(),
        access_token: token,
        device_name: name,
    })?;
    // Prove the token works and greet the user; a failure here does not undo the pairing.
    match authenticated_client()?.me().await {
        Ok(me) => println!("Connected as {} ({}).", me.name, me.identity),
        Err(e) => println!("Paired, but could not verify the token yet: {e}"),
    }
    println!("Token stored in {}.", store.path().display());
    Ok(())
}

fn logout() -> Result<()> {
    let store = store()?;
    let had = store.load()?.is_some();
    store.clear()?;
    if had {
        println!("Removed the local token. To revoke it on the server, use \"Trennen\" under \"Verbundene Geraete\" on the website.");
    } else {
        println!("Not logged in.");
    }
    Ok(())
}

async fn rooms() -> Result<()> {
    let rooms = authenticated_client()?.rooms().await?;
    if rooms.is_empty() {
        println!("No rooms available.");
    }
    for r in rooms {
        println!("{:>5}  {:<8} {}", r.id, r.kind, r.name);
    }
    Ok(())
}

/// Finds a room by numeric id or case-insensitive name; ambiguity is an error, not a guess.
fn resolve_room<'a>(rooms: &'a [Room], wanted: &str) -> Result<&'a Room> {
    if let Ok(id) = wanted.parse::<u64>() {
        if let Some(r) = rooms.iter().find(|r| r.id == id) {
            return Ok(r);
        }
    }
    let matches: Vec<&Room> = rooms
        .iter()
        .filter(|r| r.name.eq_ignore_ascii_case(wanted))
        .collect();
    match matches.as_slice() {
        [one] => Ok(one),
        [] => Err(Error::NotFound(format!(
            "no room `{wanted}` among your rooms (see `lanomat-voice rooms`)"
        ))),
        many => Err(Error::Usage(format!(
            "`{wanted}` is ambiguous, use an id: {}",
            many.iter()
                .map(|r| r.id.to_string())
                .collect::<Vec<_>>()
                .join(", ")
        ))),
    }
}

async fn join(
    room: &str,
    open_mic: bool,
    input: Option<String>,
    output: Option<String>,
    no_socket: bool,
    synthetic_audio: bool,
) -> Result<()> {
    let client = authenticated_client()?;
    let rooms = client.rooms().await?;
    let room = resolve_room(&rooms, room)?.clone();
    let grant = client.room_token(room.id).await?;

    // Bind the socket before connecting so a second `join` fails fast and early.
    let server = if no_socket {
        None
    } else {
        Some(control::ControlServer::bind(&control::default_socket_path()?).await?)
    };

    let source: Option<Box<dyn voice_core::audio::AudioSource>> = if grant.muted {
        None
    } else if synthetic_audio {
        let (clicks, _log) = ClickSource::new(ClickConfig {
            warmup: 0,
            measured: 3600,
            ..ClickConfig::default()
        });
        Some(Box::new(clicks))
    } else {
        Some(Box::new(CpalSource::new(input)))
    };
    let sink: Box<dyn voice_core::audio::AudioSink> = if synthetic_audio {
        Box::new(NullSink)
    } else {
        Box::new(CpalSink::new(output)?)
    };
    let options = SessionOptions {
        audio: grant.audio.clone(),
        start_talking: open_mic,
        audio_processing: true,
    };
    let mut session = Session::connect(&grant, source, sink, options).await?;
    println!("Joined \"{}\" as {}.", room.name, session.identity());
    if session.is_listen_only() {
        println!("You are muted by a moderator (or have no microphone): listening only.");
    } else if open_mic {
        println!("Open mic: transmitting.");
    } else if let Some(s) = &server {
        println!(
            "Muted. Hold your push-to-talk key (bound to `lanomat-voice ptt down|up`, socket {}).",
            s.path().display()
        );
    }

    let handle = session.ptt_handle();
    let handler: control::Handler = Arc::new(move |cmd| {
        let next = control::apply(handle.is_talking(), cmd);
        if cmd == PttCommand::Status {
            Ok(next)
        } else {
            handle.set_talking(next)
        }
    });
    let mut events = session.take_events().expect("events are taken once");

    let serve = async {
        match &server {
            Some(s) => s.serve(handler).await,
            None => std::future::pending().await,
        }
    };
    tokio::pin!(serve);

    // SIGTERM (systemd, `docker stop`, a window manager's exec-once teardown) must end the session as
    // cleanly as Ctrl-C does: leave the room and remove the control socket.
    let mut terminate = Terminate::install().map_err(|e| Error::io("termination handler", e))?;

    let outcome = loop {
        tokio::select! {
            _ = tokio::signal::ctrl_c() => break Ok(()),
            _ = terminate.recv() => break Ok(()),
            _ = &mut serve => break Ok(()),
            event = events.recv() => match event {
                Some(SessionEvent::Disconnected(reason)) => {
                    break Err(Error::Room(format!("disconnected from the room ({reason})")));
                }
                Some(e) => print_event(&e),
                None => break Ok(()),
            },
        }
    };
    session.close().await;
    outcome
}

fn print_event(e: &SessionEvent) {
    match e {
        SessionEvent::Connected { .. } => {}
        SessionEvent::ParticipantJoined(who) => println!("+ {who} joined"),
        SessionEvent::ParticipantLeft(who) => println!("- {who} left"),
        SessionEvent::ParticipantNamed { identity, name } => println!("  {identity} is {name}"),
        SessionEvent::ParticipantMuted { identity, muted } => {
            println!("  {identity} {}", if *muted { "muted" } else { "unmuted" });
        }
        SessionEvent::ActiveSpeakers(who) => println!("  speaking: {}", who.join(", ")),
        SessionEvent::RemoteAudio(who) => println!("  hearing {who}"),
        SessionEvent::Reconnecting => println!("connection lost, reconnecting..."),
        SessionEvent::Reconnected => println!("reconnected"),
        SessionEvent::Disconnected(_) => {}
    }
}

/// The platform's "please stop" request besides Ctrl-C: SIGTERM on unix; on Windows Ctrl-Break and
/// the console being closed.
#[cfg(unix)]
struct Terminate(tokio::signal::unix::Signal);

#[cfg(unix)]
impl Terminate {
    fn install() -> std::io::Result<Self> {
        tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate()).map(Self)
    }

    async fn recv(&mut self) {
        self.0.recv().await;
    }
}

#[cfg(windows)]
struct Terminate {
    brk: tokio::signal::windows::CtrlBreak,
    close: tokio::signal::windows::CtrlClose,
}

#[cfg(windows)]
impl Terminate {
    fn install() -> std::io::Result<Self> {
        Ok(Self {
            brk: tokio::signal::windows::ctrl_break()?,
            close: tokio::signal::windows::ctrl_close()?,
        })
    }

    async fn recv(&mut self) {
        tokio::select! {
            _ = self.brk.recv() => {}
            _ = self.close.recv() => {}
        }
    }
}

async fn ptt(cmd: PttCommand) -> Result<()> {
    let talking = control::send_command(&control::default_socket_path()?, cmd).await?;
    println!("{}", if talking { "talking" } else { "muted" });
    Ok(())
}

fn devices() -> Result<()> {
    let (inputs, outputs) = list_devices()?;
    println!("Input devices:");
    for d in inputs {
        println!("  {d}");
    }
    println!("Output devices:");
    for d in outputs {
        println!("  {d}");
    }
    Ok(())
}

async fn latency_test(room: &str, bursts: u32, warmup: u32) -> Result<()> {
    // The LANoMAT v1 API issues exactly one identity per user and LiveKit drops the older of two
    // connections with the same identity, so the two-session test cannot use it. It needs a
    // LiveKit key pair for the target server (a dev server, or your own deployment).
    let keys = DevKeys::from_env().map_err(|e| {
        Error::Usage(format!(
            "{e}. latency-test mints its own tokens for two probe identities; set {}, {} and {} \
             (e.g. for `livekit-server --dev`). The LANoMAT bearer API cannot provide a second identity.",
            devtokens::ENV_URL,
            devtokens::ENV_API_KEY,
            devtokens::ENV_API_SECRET
        ))
    })?;
    let audio = voice_core::api::AudioSettings::default();
    let tx = keys.grant(room, "probe-tx", audio.clone())?;
    let rx = keys.grant(room, "probe-rx", audio)?;
    let click = ClickConfig {
        warmup,
        measured: bursts,
        ..ClickConfig::default()
    };
    println!(
        "Latency test in room \"{room}\": {warmup} warm-up + {bursts} measured 20 ms click bursts, one per second (about {} s)...",
        (warmup + bursts) + 5
    );
    let report = run_latency_test(&tx, &rx, click).await?;
    match &report.stats {
        Some(stats) => {
            println!("{}", stats.report());
            println!("lost     {} of {} bursts", report.lost(), report.sent);
            println!("\nOne-way delay of the audio path (encode, network, SFU, jitter buffer, decode); no OS device buffers.");
            Ok(())
        }
        None => Err(Error::Room(format!(
            "none of the {} measured bursts arrived: the second session received silence",
            report.sent
        ))),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn room(id: u64, name: &str) -> Room {
        Room {
            id,
            name: name.into(),
            description: None,
            kind: "general".into(),
        }
    }

    #[test]
    fn resolves_by_id_then_by_name() {
        let rooms = vec![room(1, "Lobby"), room(2, "Team Rot"), room(3, "7")];
        assert_eq!(resolve_room(&rooms, "2").unwrap().name, "Team Rot");
        assert_eq!(resolve_room(&rooms, "lobby").unwrap().id, 1);
        // A numeric name still resolves when no id matches.
        assert_eq!(resolve_room(&rooms, "7").unwrap().id, 3);
    }

    #[test]
    fn unknown_and_ambiguous_names_are_errors() {
        let rooms = vec![room(1, "Lobby"), room(2, "lobby")];
        assert!(matches!(
            resolve_room(&rooms, "nope"),
            Err(Error::NotFound(_))
        ));
        assert!(matches!(
            resolve_room(&rooms, "LOBBY"),
            Err(Error::Usage(_))
        ));
    }

    #[test]
    fn exit_codes_are_distinct_per_kind() {
        let codes = [
            exit_code(ErrorKind::Local),
            exit_code(ErrorKind::Usage),
            exit_code(ErrorKind::Auth),
            exit_code(ErrorKind::Network),
            exit_code(ErrorKind::Server),
        ];
        let mut sorted = codes.to_vec();
        sorted.sort_unstable();
        sorted.dedup();
        assert_eq!(sorted.len(), 5);
    }
}
