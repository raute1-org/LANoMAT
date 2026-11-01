//! One error type for the whole core, with a coarse category the CLI maps to exit codes.

use std::path::PathBuf;

/// Coarse classification used for exit codes and user-facing hints.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ErrorKind {
    /// Bad input from the user (URL, room name, command).
    Usage,
    /// Not logged in, token revoked or expired, pairing denied.
    Auth,
    /// The server or the network could not be reached or answered nonsense.
    Network,
    /// The server said no (404/410/503 and friends).
    Server,
    /// Local audio, files, sockets.
    Local,
}

#[derive(Debug, thiserror::Error)]
pub enum Error {
    #[error("invalid base URL: {0}")]
    InvalidUrl(String),
    #[error("not logged in: run `lanomat-voice login <base-url>` first")]
    NotLoggedIn,
    #[error("the access token was rejected (revoked or expired): log in again")]
    Unauthorized,
    #[error("pairing was denied on the approval page")]
    PairingDenied,
    #[error("the pairing code expired: start over with `login`")]
    PairingExpired,
    #[error("the device code is unknown or already used: start over with `login`")]
    InvalidGrant,
    #[error("could not reach the server: {0}")]
    Network(String),
    #[error("the server answered with an unexpected response: {0}")]
    BadResponse(String),
    #[error("not found: {0}")]
    NotFound(String),
    #[error("the room is archived")]
    RoomArchived,
    #[error("voice is not available on the server right now (not configured or SFU unreachable)")]
    Unavailable,
    #[error("rate limited by the server, try again in a moment")]
    RateLimited { retry_after_secs: Option<u64> },
    #[error("the server refused this action (HTTP 403)")]
    Forbidden(String),
    /// `409`: the object changed under the client (stale `lock_version`); reload and retry.
    #[error("conflict: {message}")]
    Conflict {
        code: Option<String>,
        message: String,
    },
    #[error("gone: {0}")]
    Gone(String),
    /// `422`: a business rule or validation error. `message` is the server's German text.
    #[error("rejected: {message}")]
    Validation {
        code: Option<String>,
        message: String,
        fields: std::collections::BTreeMap<String, Vec<String>>,
    },
    /// A file transfer failed in a way the caller should show (`code` is a stable machine code
    /// such as `ticket_expired`, `too_large`, `host_full`, `upload_lost`, `cancelled`).
    #[error("transfer failed ({code}): {message}")]
    Transfer { code: String, message: String },
    #[error("server error (HTTP {status}): {message}")]
    Server { status: u16, message: String },
    #[error("{0}")]
    Usage(String),
    #[error("credential store: {0}")]
    Store(String),
    #[error("could not connect to the LiveKit room: {0}")]
    Room(String),
    #[error("audio: {0}")]
    Audio(String),
    #[error("control socket: {0}")]
    Control(String),
    #[error("i/o error on {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
}

impl Error {
    pub fn kind(&self) -> ErrorKind {
        match self {
            Error::InvalidUrl(_) | Error::Usage(_) => ErrorKind::Usage,
            Error::NotLoggedIn
            | Error::Unauthorized
            | Error::PairingDenied
            | Error::PairingExpired
            | Error::InvalidGrant => ErrorKind::Auth,
            Error::Network(_)
            | Error::BadResponse(_)
            | Error::RateLimited { .. }
            | Error::Room(_) => ErrorKind::Network,
            Error::NotFound(_)
            | Error::RoomArchived
            | Error::Unavailable
            | Error::Forbidden(_)
            | Error::Conflict { .. }
            | Error::Gone(_)
            | Error::Validation { .. }
            | Error::Transfer { .. }
            | Error::Server { .. } => ErrorKind::Server,
            Error::Store(_) | Error::Audio(_) | Error::Control(_) | Error::Io { .. } => {
                ErrorKind::Local
            }
        }
    }

    /// Whether trying the same request again later can succeed (network trouble, throttling, a
    /// server that is down or starting). Rejections, auth problems and conflicts are not.
    pub fn retryable(&self) -> bool {
        match self {
            Error::Network(_) | Error::RateLimited { .. } | Error::Unavailable | Error::Room(_) => {
                true
            }
            Error::Server { status, .. } => *status >= 500,
            Error::Transfer { code, .. } => {
                matches!(code.as_str(), "network" | "upload_lost" | "link_expired")
            }
            _ => false,
        }
    }

    /// The stable machine code of the server's refusal, when it sent one.
    pub fn code(&self) -> Option<&str> {
        match self {
            Error::Conflict { code, .. } | Error::Validation { code, .. } => code.as_deref(),
            Error::Transfer { code, .. } => Some(code),
            _ => None,
        }
    }

    pub fn io(path: impl Into<PathBuf>, source: std::io::Error) -> Self {
        Error::Io {
            path: path.into(),
            source,
        }
    }
}

pub type Result<T> = std::result::Result<T, Error>;
