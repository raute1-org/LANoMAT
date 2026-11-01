//! Push-to-talk control socket.
//!
//! A running `lanomat-voice join` listens on a unix socket in the XDG runtime directory; the
//! one-shot `lanomat-voice ptt down|up|toggle` connects, sends one line and prints the reply.
//! A compositor or window manager key bind runs that command, so global push-to-talk needs no
//! compositor-specific code (and works on Wayland, where applications cannot grab global keys).
//!
//! Protocol: one request line (`down`, `up`, `toggle`, `status`), one reply line
//! (`ok talking`, `ok muted`, or `err <message>`). Everything is ASCII.

use std::path::{Path, PathBuf};
use std::sync::Arc;

use crate::error::{Error, Result};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PttCommand {
    Down,
    Up,
    Toggle,
    Status,
}

impl PttCommand {
    pub fn as_str(self) -> &'static str {
        match self {
            PttCommand::Down => "down",
            PttCommand::Up => "up",
            PttCommand::Toggle => "toggle",
            PttCommand::Status => "status",
        }
    }
}

/// Parses one request line; tolerant of case and surrounding whitespace.
pub fn parse_command(line: &str) -> Result<PttCommand> {
    match line.trim().to_ascii_lowercase().as_str() {
        "down" => Ok(PttCommand::Down),
        "up" => Ok(PttCommand::Up),
        "toggle" => Ok(PttCommand::Toggle),
        "status" => Ok(PttCommand::Status),
        other => Err(Error::Usage(format!(
            "unknown command `{other}` (expected down, up, toggle or status)"
        ))),
    }
}

/// The transmit state a command leads to; pure so `down`/`up` stay idempotent (X11 key repeat
/// sends many presses, a missed release must not leave the microphone open forever).
pub fn apply(current_talking: bool, cmd: PttCommand) -> bool {
    match cmd {
        PttCommand::Down => true,
        PttCommand::Up => false,
        PttCommand::Toggle => !current_talking,
        PttCommand::Status => current_talking,
    }
}

pub fn format_reply(result: &std::result::Result<bool, String>) -> String {
    match result {
        Ok(true) => "ok talking".into(),
        Ok(false) => "ok muted".into(),
        Err(message) => format!("err {}", message.replace('\n', " ")),
    }
}

/// Parses a reply line into the talking state or the server's error message.
pub fn parse_reply(line: &str) -> Result<bool> {
    let line = line.trim();
    match line {
        "ok talking" => Ok(true),
        "ok muted" => Ok(false),
        other => match other.strip_prefix("err ") {
            Some(message) => Err(Error::Control(message.to_owned())),
            None => Err(Error::Control(format!("unexpected reply `{other}`"))),
        },
    }
}

/// `$LANOMAT_VOICE_SOCKET`, else `$XDG_RUNTIME_DIR/lanomat-voice.sock`.
pub fn default_socket_path() -> Result<PathBuf> {
    if let Some(p) = std::env::var_os("LANOMAT_VOICE_SOCKET").filter(|p| !p.is_empty()) {
        return Ok(PathBuf::from(p));
    }
    directories::BaseDirs::new()
        .and_then(|d| d.runtime_dir().map(|r| r.join("lanomat-voice.sock")))
        .ok_or_else(|| {
            Error::Control(
                "no runtime directory: XDG_RUNTIME_DIR is not set (set LANOMAT_VOICE_SOCKET to a path)".into(),
            )
        })
}

#[cfg(unix)]
pub use unix::{send_command, ControlServer, Handler};

#[cfg(unix)]
mod unix {
    use super::*;
    use std::os::unix::fs::PermissionsExt;
    use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
    use tokio::net::{UnixListener, UnixStream};

    /// Handler: applies a command and returns the resulting talking state or an error message.
    pub type Handler = Arc<dyn Fn(PttCommand) -> std::result::Result<bool, String> + Send + Sync>;

    pub struct ControlServer {
        listener: UnixListener,
        path: PathBuf,
    }

    impl ControlServer {
        /// Binds the socket (mode 0600). A stale socket file from a crashed run is replaced; a
        /// socket that still answers means another session is running and is an error.
        pub async fn bind(path: &Path) -> Result<Self> {
            if path.exists() {
                match UnixStream::connect(path).await {
                    Ok(_) => {
                        return Err(Error::Control(format!(
                            "another `join` session already owns {}",
                            path.display()
                        )))
                    }
                    Err(_) => std::fs::remove_file(path).map_err(|e| Error::io(path, e))?,
                }
            }
            if let Some(dir) = path.parent().filter(|d| !d.as_os_str().is_empty()) {
                std::fs::create_dir_all(dir).map_err(|e| Error::io(dir, e))?;
            }
            let listener = UnixListener::bind(path).map_err(|e| Error::io(path, e))?;
            std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o600))
                .map_err(|e| Error::io(path, e))?;
            Ok(Self {
                listener,
                path: path.to_owned(),
            })
        }

        pub fn path(&self) -> &Path {
            &self.path
        }

        /// Serves connections until the future is dropped (cancel it to stop). One bad client
        /// never stops the loop.
        pub async fn serve(&self, handler: Handler) {
            loop {
                let Ok((stream, _)) = self.listener.accept().await else {
                    continue;
                };
                let handler = handler.clone();
                tokio::spawn(async move {
                    let (read, mut write) = stream.into_split();
                    let mut line = String::new();
                    // Bounded read: a request is a few bytes.
                    let mut reader = BufReader::new(read).take(256);
                    if reader.read_line(&mut line).await.is_err() {
                        return;
                    }
                    let result = match parse_command(&line) {
                        Ok(cmd) => handler(cmd),
                        Err(e) => Err(e.to_string()),
                    };
                    let reply = format!("{}\n", format_reply(&result));
                    let _ = write.write_all(reply.as_bytes()).await;
                });
            }
        }
    }

    impl Drop for ControlServer {
        fn drop(&mut self) {
            let _ = std::fs::remove_file(&self.path);
        }
    }

    /// Sends one command to a running session and returns its talking state.
    pub async fn send_command(path: &Path, cmd: PttCommand) -> Result<bool> {
        let stream = UnixStream::connect(path).await.map_err(|e| {
            Error::Control(format!(
                "no running `lanomat-voice join` session at {} ({e})",
                path.display()
            ))
        })?;
        let (read, mut write) = stream.into_split();
        write
            .write_all(format!("{}\n", cmd.as_str()).as_bytes())
            .await
            .map_err(|e| Error::io(path, e))?;
        let mut line = String::new();
        BufReader::new(read)
            .read_line(&mut line)
            .await
            .map_err(|e| Error::io(path, e))?;
        parse_reply(&line)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_commands_leniently() {
        assert_eq!(parse_command("down\n").unwrap(), PttCommand::Down);
        assert_eq!(parse_command("  UP ").unwrap(), PttCommand::Up);
        assert_eq!(parse_command("Toggle").unwrap(), PttCommand::Toggle);
        assert_eq!(parse_command("status").unwrap(), PttCommand::Status);
        assert!(parse_command("").is_err());
        assert!(parse_command("mute").is_err());
    }

    #[test]
    fn state_transitions_are_idempotent() {
        assert!(apply(false, PttCommand::Down));
        assert!(apply(true, PttCommand::Down));
        assert!(!apply(true, PttCommand::Up));
        assert!(!apply(false, PttCommand::Up));
        assert!(apply(false, PttCommand::Toggle));
        assert!(!apply(true, PttCommand::Toggle));
        assert!(apply(true, PttCommand::Status));
        assert!(!apply(false, PttCommand::Status));
    }

    #[test]
    fn reply_round_trip() {
        assert_eq!(format_reply(&Ok(true)), "ok talking");
        assert_eq!(format_reply(&Ok(false)), "ok muted");
        assert_eq!(format_reply(&Err("a\nb".into())), "err a b");
        assert!(parse_reply("ok talking\n").unwrap());
        assert!(!parse_reply("ok muted").unwrap());
        assert!(matches!(parse_reply("err nope"), Err(Error::Control(m)) if m == "nope"));
        assert!(parse_reply("garbage").is_err());
    }

    #[cfg(unix)]
    mod socket {
        use super::*;
        use std::sync::atomic::{AtomicBool, Ordering};

        fn handler(state: Arc<AtomicBool>) -> unix::Handler {
            Arc::new(move |cmd| {
                let next = apply(state.load(Ordering::SeqCst), cmd);
                state.store(next, Ordering::SeqCst);
                Ok(next)
            })
        }

        #[tokio::test]
        async fn client_and_server_talk() {
            let dir = tempfile::tempdir().unwrap();
            let path = dir.path().join("ptt.sock");
            let server = ControlServer::bind(&path).await.unwrap();
            let state = Arc::new(AtomicBool::new(false));
            let serve = tokio::spawn({
                let h = handler(state.clone());
                async move { server.serve(h).await }
            });
            assert!(send_command(&path, PttCommand::Down).await.unwrap());
            assert!(state.load(Ordering::SeqCst));
            assert!(send_command(&path, PttCommand::Down).await.unwrap());
            assert!(!send_command(&path, PttCommand::Up).await.unwrap());
            assert!(send_command(&path, PttCommand::Toggle).await.unwrap());
            assert!(send_command(&path, PttCommand::Status).await.unwrap());
            serve.abort();
        }

        #[tokio::test]
        async fn socket_is_private_and_removed_on_drop() {
            use std::os::unix::fs::PermissionsExt;
            let dir = tempfile::tempdir().unwrap();
            let path = dir.path().join("ptt.sock");
            let server = ControlServer::bind(&path).await.unwrap();
            let mode = std::fs::metadata(&path).unwrap().permissions().mode() & 0o777;
            assert_eq!(mode, 0o600);
            drop(server);
            assert!(!path.exists());
        }

        #[tokio::test]
        async fn stale_socket_is_replaced_but_a_live_one_is_refused() {
            let dir = tempfile::tempdir().unwrap();
            let path = dir.path().join("ptt.sock");
            // A leftover file nobody listens on.
            std::fs::write(&path, b"").unwrap();
            let first = ControlServer::bind(&path).await.unwrap();
            let serve = tokio::spawn({
                let h = handler(Arc::new(AtomicBool::new(false)));
                async move {
                    first.serve(h).await;
                }
            });
            let second = ControlServer::bind(&path).await;
            assert!(matches!(second, Err(Error::Control(_))));
            serve.abort();
        }

        #[tokio::test]
        async fn handler_errors_reach_the_client_and_bad_input_is_rejected() {
            let dir = tempfile::tempdir().unwrap();
            let path = dir.path().join("ptt.sock");
            let server = ControlServer::bind(&path).await.unwrap();
            let serve = tokio::spawn(async move {
                server
                    .serve(Arc::new(|_| Err("muted by a moderator".to_string())))
                    .await;
            });
            let err = send_command(&path, PttCommand::Down).await.unwrap_err();
            assert!(err.to_string().contains("muted by a moderator"));

            // Raw garbage gets an err line, not a hang.
            use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
            let mut s = tokio::net::UnixStream::connect(&path).await.unwrap();
            s.write_all(b"banana\n").await.unwrap();
            let mut line = String::new();
            BufReader::new(&mut s).read_line(&mut line).await.unwrap();
            assert!(line.starts_with("err "), "{line}");
            serve.abort();
        }

        #[tokio::test]
        async fn no_session_running_is_a_clear_error() {
            let dir = tempfile::tempdir().unwrap();
            let err = send_command(&dir.path().join("none.sock"), PttCommand::Up)
                .await
                .unwrap_err();
            assert!(err.to_string().contains("no running"));
        }
    }
}
