//! Push-to-talk control socket.
//!
//! A running `lanomat-voice join` listens on a unix socket in the XDG runtime directory (on
//! Windows: a named pipe `\\.\pipe\lanomat-voice-<user sid>` that only the current user may open);
//! the one-shot `lanomat-voice ptt down|up|toggle` connects, sends one line and prints the reply.
//! A compositor or window manager key bind runs that command, so global push-to-talk needs no
//! compositor-specific code (and works on Wayland, where applications cannot grab global keys).
//! The protocol below is shared; only the transport differs per platform.
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

/// `$LANOMAT_VOICE_SOCKET`, else `$XDG_RUNTIME_DIR/lanomat-voice.sock` (unix) or the per-user
/// pipe name (Windows).
pub fn default_socket_path() -> Result<PathBuf> {
    if let Some(p) = std::env::var_os("LANOMAT_VOICE_SOCKET").filter(|p| !p.is_empty()) {
        return Ok(PathBuf::from(p));
    }
    platform_default_path()
}

#[cfg(unix)]
fn platform_default_path() -> Result<PathBuf> {
    directories::BaseDirs::new()
        .and_then(|d| d.runtime_dir().map(|r| r.join("lanomat-voice.sock")))
        .ok_or_else(|| {
            Error::Control(
                "no runtime directory: XDG_RUNTIME_DIR is not set (set LANOMAT_VOICE_SOCKET to a path)".into(),
            )
        })
}

#[cfg(windows)]
fn platform_default_path() -> Result<PathBuf> {
    Ok(PathBuf::from(pipe_name(&windows::current_user_sid()?)))
}

/// `\\.\pipe\lanomat-voice-<sid>`: one pipe per Windows user, so two accounts logged in at once
/// (fast user switching) each get their own push-to-talk channel. Anything in the id that is not
/// valid in a pipe name is replaced.
#[cfg(any(windows, test))]
fn pipe_name(user_id: &str) -> String {
    let id: String = user_id
        .chars()
        .map(|c| {
            if c.is_ascii_alphanumeric() || c == '-' {
                c
            } else {
                '_'
            }
        })
        .collect();
    format!(r"\\.\pipe\lanomat-voice-{id}")
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

#[cfg(windows)]
pub use windows::{send_command, ControlServer, Handler};

#[cfg(windows)]
mod windows {
    use super::*;
    use std::ffi::{c_void, OsStr};
    use std::sync::Mutex;
    use std::time::Duration;
    use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
    use tokio::net::windows::named_pipe::{ClientOptions, NamedPipeServer, ServerOptions};
    use windows_sys::Win32::Foundation::{CloseHandle, LocalFree, HANDLE};
    use windows_sys::Win32::Security::Authorization::{
        ConvertSidToStringSidW, ConvertStringSecurityDescriptorToSecurityDescriptorW,
        SDDL_REVISION_1,
    };
    use windows_sys::Win32::Security::{
        GetTokenInformation, TokenUser, SECURITY_ATTRIBUTES, TOKEN_QUERY, TOKEN_USER,
    };
    use windows_sys::Win32::System::Threading::{GetCurrentProcess, OpenProcessToken};

    const ERROR_ACCESS_DENIED: i32 = 5;
    const ERROR_PIPE_BUSY: i32 = 231;

    /// Handler: applies a command and returns the resulting talking state or an error message.
    pub type Handler = Arc<dyn Fn(PttCommand) -> std::result::Result<bool, String> + Send + Sync>;

    /// The string form of the current user's SID (`S-1-5-21-...`). Not a secret.
    pub(super) fn current_user_sid() -> Result<String> {
        let fail = |what: &str| {
            Error::Control(format!(
                "could not read the current user ({what}): {}",
                std::io::Error::last_os_error()
            ))
        };
        // SAFETY: plain Win32 calls on a token handle we open and close here. The TOKEN_USER
        // buffer is `u64`-backed so it is aligned for the struct it holds, and it outlives every
        // pointer taken from it. The string returned by ConvertSidToStringSidW is freed with
        // LocalFree after it has been copied.
        unsafe {
            let mut token: HANDLE = std::ptr::null_mut();
            if OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &mut token) == 0 {
                return Err(fail("OpenProcessToken"));
            }
            let mut needed = 0u32;
            GetTokenInformation(token, TokenUser, std::ptr::null_mut(), 0, &mut needed);
            let mut buf = vec![0u64; (needed as usize).div_ceil(8).max(1)];
            let ok = GetTokenInformation(
                token,
                TokenUser,
                buf.as_mut_ptr().cast::<c_void>(),
                needed,
                &mut needed,
            );
            if ok == 0 {
                let e = fail("GetTokenInformation");
                CloseHandle(token);
                return Err(e);
            }
            let user = &*buf.as_ptr().cast::<TOKEN_USER>();
            let mut wide: *mut u16 = std::ptr::null_mut();
            let converted = ConvertSidToStringSidW(user.User.Sid, &mut wide);
            CloseHandle(token);
            if converted == 0 || wide.is_null() {
                return Err(fail("ConvertSidToStringSidW"));
            }
            let mut len = 0usize;
            while *wide.add(len) != 0 {
                len += 1;
            }
            let sid = String::from_utf16_lossy(std::slice::from_raw_parts(wide, len));
            LocalFree(wide.cast::<c_void>());
            Ok(sid)
        }
    }

    /// Protected DACL: full access for the user and for LocalSystem, nobody else (not even
    /// Administrators, "Everyone" or the anonymous user, which the default pipe DACL lets read).
    pub(super) fn sddl_for(sid: &str) -> String {
        format!("D:P(A;;GA;;;{sid})(A;;GA;;;SY)")
    }

    /// Creates one pipe instance with the private DACL and without remote clients. `first`
    /// makes the call fail when any instance of that name already exists: that is how a second
    /// `join` is refused. Synchronous on purpose, so no raw pointer lives across an `await`.
    fn create_instance(name: &OsStr, sddl: &str, first: bool) -> std::io::Result<NamedPipeServer> {
        let wide: Vec<u16> = sddl.encode_utf16().chain(std::iter::once(0)).collect();
        let mut descriptor: *mut c_void = std::ptr::null_mut();
        // SAFETY: `wide` is NUL-terminated and outlives the call; on success the descriptor is
        // LocalAlloc'd and freed below once the pipe has been created (the kernel copies it).
        let converted = unsafe {
            ConvertStringSecurityDescriptorToSecurityDescriptorW(
                wide.as_ptr(),
                SDDL_REVISION_1,
                &mut descriptor,
                std::ptr::null_mut(),
            )
        };
        if converted == 0 {
            return Err(std::io::Error::last_os_error());
        }
        let mut attributes = SECURITY_ATTRIBUTES {
            nLength: std::mem::size_of::<SECURITY_ATTRIBUTES>() as u32,
            lpSecurityDescriptor: descriptor,
            bInheritHandle: 0,
        };
        let raw = (&mut attributes as *mut SECURITY_ATTRIBUTES).cast::<c_void>();
        // SAFETY: `attributes` points at a valid descriptor and lives until after the call.
        let pipe = unsafe {
            ServerOptions::new()
                .first_pipe_instance(first)
                .reject_remote_clients(true)
                .create_with_security_attributes_raw(name, raw)
        };
        // SAFETY: allocated by the conversion call above and not used afterwards.
        unsafe { LocalFree(descriptor) };
        pipe
    }

    pub struct ControlServer {
        path: PathBuf,
        sddl: String,
        /// The instance created by `bind`; `serve` takes it over.
        pending: Mutex<Option<NamedPipeServer>>,
    }

    impl ControlServer {
        /// Creates the pipe. A named pipe disappears with its owner, so there is no stale
        /// endpoint to clean up; an existing instance means another session is running.
        pub async fn bind(path: &Path) -> Result<Self> {
            let sddl = sddl_for(&current_user_sid()?);
            match create_instance(path.as_os_str(), &sddl, true) {
                Ok(pipe) => Ok(Self {
                    path: path.to_owned(),
                    sddl,
                    pending: Mutex::new(Some(pipe)),
                }),
                Err(e) if e.raw_os_error() == Some(ERROR_ACCESS_DENIED) => Err(Error::Control(
                    format!("another `join` session already owns {}", path.display()),
                )),
                Err(e) => Err(Error::io(path, e)),
            }
        }

        pub fn path(&self) -> &Path {
            &self.path
        }

        /// Serves connections until the future is dropped (cancel it to stop, which also closes
        /// the pipe). One bad client never stops the loop.
        pub async fn serve(&self, handler: Handler) {
            let mut next = self.pending.lock().expect("pipe lock").take();
            loop {
                let server = match next.take() {
                    Some(s) => s,
                    None => match create_instance(self.path.as_os_str(), &self.sddl, false) {
                        Ok(s) => s,
                        Err(_) => {
                            tokio::time::sleep(Duration::from_millis(100)).await;
                            continue;
                        }
                    },
                };
                if server.connect().await.is_err() {
                    continue;
                }
                // A listening instance must exist again before this client is served, or the
                // next caller sees "all pipe instances are busy".
                next = create_instance(self.path.as_os_str(), &self.sddl, false).ok();
                let handler = handler.clone();
                tokio::spawn(async move {
                    let (read, mut write) = tokio::io::split(server);
                    let mut line = String::new();
                    // Bounded read: a request is a few bytes.
                    let mut reader = BufReader::new(read).take(256);
                    let read_line = reader.read_line(&mut line);
                    if !matches!(
                        tokio::time::timeout(Duration::from_secs(5), read_line).await,
                        Ok(Ok(_))
                    ) {
                        return;
                    }
                    let result = match parse_command(&line) {
                        Ok(cmd) => handler(cmd),
                        Err(e) => Err(e.to_string()),
                    };
                    let reply = format!("{}\n", format_reply(&result));
                    if write.write_all(reply.as_bytes()).await.is_err()
                        || write.flush().await.is_err()
                    {
                        return;
                    }
                    // Closing the pipe right away could drop the reply before the client has
                    // read it; wait for the client to hang up first (bounded).
                    let mut rest = Vec::new();
                    let _ =
                        tokio::time::timeout(Duration::from_secs(2), reader.read_to_end(&mut rest))
                            .await;
                });
            }
        }
    }

    /// Sends one command to a running session and returns its talking state.
    pub async fn send_command(path: &Path, cmd: PttCommand) -> Result<bool> {
        let mut busy_retries = 0;
        let client = loop {
            match ClientOptions::new().open(path.as_os_str()) {
                Ok(c) => break c,
                // Every instance is serving someone for a moment: try again shortly.
                Err(e) if e.raw_os_error() == Some(ERROR_PIPE_BUSY) && busy_retries < 40 => {
                    busy_retries += 1;
                    tokio::time::sleep(Duration::from_millis(50)).await;
                }
                Err(e) => {
                    return Err(Error::Control(format!(
                        "no running `lanomat-voice join` session at {} ({e})",
                        path.display()
                    )))
                }
            }
        };
        let (read, mut write) = tokio::io::split(client);
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

    #[test]
    fn pipe_names_are_per_user_and_safe() {
        assert_eq!(
            pipe_name("S-1-5-21-1-2-3-1001"),
            r"\\.\pipe\lanomat-voice-S-1-5-21-1-2-3-1001"
        );
        assert_eq!(pipe_name("a b\\c"), r"\\.\pipe\lanomat-voice-a_b_c");
    }

    #[cfg(windows)]
    mod pipe {
        use super::*;
        use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

        fn handler(state: Arc<AtomicBool>) -> windows::Handler {
            Arc::new(move |cmd| {
                let next = apply(state.load(Ordering::SeqCst), cmd);
                state.store(next, Ordering::SeqCst);
                Ok(next)
            })
        }

        /// A pipe name unique to this test run, so parallel tests never share an endpoint.
        fn unique_path() -> PathBuf {
            static N: AtomicUsize = AtomicUsize::new(0);
            PathBuf::from(format!(
                r"\\.\pipe\lanomat-voice-test-{}-{}",
                std::process::id(),
                N.fetch_add(1, Ordering::SeqCst)
            ))
        }

        #[test]
        fn the_sid_is_found_and_the_dacl_names_only_that_user_and_the_system() {
            let sid = windows::current_user_sid().unwrap();
            assert!(sid.starts_with("S-1-5-"), "{sid}");
            let sddl = windows::sddl_for(&sid);
            assert_eq!(sddl, format!("D:P(A;;GA;;;{sid})(A;;GA;;;SY)"));
            assert!(default_socket_path()
                .unwrap()
                .to_string_lossy()
                .contains(&sid));
        }

        #[tokio::test]
        async fn client_and_server_talk() {
            let path = unique_path();
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
        async fn a_live_session_is_refused_and_the_name_is_free_again_afterwards() {
            let path = unique_path();
            let first = ControlServer::bind(&path).await.unwrap();
            // Refused even before the first server starts accepting.
            assert!(matches!(
                ControlServer::bind(&path).await,
                Err(Error::Control(_))
            ));
            let serve = tokio::spawn({
                let h = handler(Arc::new(AtomicBool::new(false)));
                async move { first.serve(h).await }
            });
            assert!(send_command(&path, PttCommand::Status).await.is_ok());
            assert!(matches!(
                ControlServer::bind(&path).await,
                Err(Error::Control(_))
            ));
            // A pipe has no stale file: once the owner is gone the name can be taken again.
            serve.abort();
            let _ = serve.await;
            // Handler tasks still holding an instance finish within moments, so allow a short wait.
            let mut rebound = false;
            for _ in 0..60 {
                if ControlServer::bind(&path).await.is_ok() {
                    rebound = true;
                    break;
                }
                tokio::time::sleep(std::time::Duration::from_millis(50)).await;
            }
            assert!(rebound, "the pipe name was never released");
        }

        #[tokio::test]
        async fn handler_errors_reach_the_client_and_bad_input_is_rejected() {
            let path = unique_path();
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
            let mut c = tokio::net::windows::named_pipe::ClientOptions::new()
                .open(&path)
                .unwrap();
            c.write_all(b"banana\n").await.unwrap();
            let mut line = String::new();
            BufReader::new(&mut c).read_line(&mut line).await.unwrap();
            assert!(line.starts_with("err "), "{line}");
            serve.abort();
        }

        #[tokio::test]
        async fn many_quick_commands_in_a_row_all_get_answers() {
            let path = unique_path();
            let server = ControlServer::bind(&path).await.unwrap();
            let serve = tokio::spawn({
                let h = handler(Arc::new(AtomicBool::new(false)));
                async move { server.serve(h).await }
            });
            for _ in 0..50 {
                assert!(send_command(&path, PttCommand::Down).await.unwrap());
                assert!(!send_command(&path, PttCommand::Up).await.unwrap());
            }
            serve.abort();
        }

        #[tokio::test]
        async fn no_session_running_is_a_clear_error() {
            let err = send_command(&unique_path(), PttCommand::Up)
                .await
                .unwrap_err();
            assert!(err.to_string().contains("no running"));
        }
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
