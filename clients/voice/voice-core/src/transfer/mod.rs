//! File transfers: streaming downloads with HTTP Range resume, tus 1.0.0 uploads with a
//! fingerprint file for resuming after a restart, and a manager that runs them with progress
//! events, pause, resume and cancel.
//!
//! Two rules for everything in here: the signed download URL and the upload ticket are
//! credentials (never logged, never stored, never in an error message), and the bearer token goes
//! to the LANoMAT API only, never to the file host.

pub mod download;
pub mod manager;
pub mod store;
pub mod tus;

#[cfg(test)]
mod testutil;

use std::time::Duration;

use tokio::sync::watch;

use crate::error::Error;

/// Why a transfer function returned without a result.
#[derive(Debug)]
pub enum Stop {
    Paused,
    Cancelled,
    Failed(Error),
}

impl From<Error> for Stop {
    fn from(e: Error) -> Self {
        Stop::Failed(e)
    }
}

pub(crate) fn transfer_err(code: &str, message: impl Into<String>) -> Stop {
    Stop::Failed(Error::Transfer {
        code: code.to_owned(),
        message: message.into(),
    })
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RunState {
    Running,
    Paused,
    Cancelled,
}

pub type Ctrl = watch::Receiver<RunState>;

/// Completes as soon as the transfer is paused or cancelled (or its owner is gone).
pub(crate) async fn interrupted(ctrl: &mut Ctrl) -> Stop {
    loop {
        let state = *ctrl.borrow_and_update();
        match state {
            RunState::Running => {
                if ctrl.changed().await.is_err() {
                    return Stop::Cancelled;
                }
            }
            RunState::Paused => return Stop::Paused,
            RunState::Cancelled => return Stop::Cancelled,
        }
    }
}

pub(crate) async fn sleep_or_stop(ctrl: &mut Ctrl, d: Duration) -> Result<(), Stop> {
    tokio::select! {
        stop = interrupted(ctrl) => Err(stop),
        _ = tokio::time::sleep(d) => Ok(()),
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Phase {
    Preparing,
    Active,
    Reconnecting,
}

/// Receives what a transfer is doing. Called often; implementations throttle themselves.
pub trait Reporter: Send + Sync {
    fn phase(&self, phase: Phase);
    /// `total` is 0 when unknown.
    fn progress(&self, done: u64, total: u64);
}

/// The retry delays of the web app's uploader (`useResumableUploads.ts`).
pub fn default_retry_delays() -> Vec<Duration> {
    [0u64, 1, 3, 5, 10, 20, 30]
        .iter()
        .map(|s| Duration::from_secs(*s))
        .collect()
}

/// A file name that is safe to create in a user's download folder: one path component, no
/// control or reserved characters, no Windows device names, not empty, bounded in length.
pub fn sanitize_filename(raw: &str) -> String {
    let last = raw.rsplit(['/', '\\']).next().unwrap_or("");
    let cleaned: String = last
        .chars()
        .filter(|c| !c.is_control())
        .map(|c| {
            if matches!(c, '<' | '>' | ':' | '"' | '|' | '?' | '*') {
                '_'
            } else {
                c
            }
        })
        .collect();
    let mut name = cleaned
        .trim_matches(|c: char| c == '.' || c.is_whitespace())
        .to_owned();
    if name.is_empty() {
        name = "download".into();
    }
    if name.len() > 200 {
        let mut cut = 200;
        while !name.is_char_boundary(cut) {
            cut -= 1;
        }
        name.truncate(cut);
    }
    let stem = name.split('.').next().unwrap_or("").to_ascii_uppercase();
    let reserved = matches!(stem.as_str(), "CON" | "PRN" | "AUX" | "NUL")
        || ((stem.starts_with("COM") || stem.starts_with("LPT"))
            && stem.len() == 4
            && stem.as_bytes()[3].is_ascii_digit());
    if reserved {
        name.insert(0, '_');
    }
    name
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn file_names_cannot_escape_the_target_directory() {
        assert_eq!(sanitize_filename("maps.zip"), "maps.zip");
        assert_eq!(sanitize_filename("../../etc/passwd"), "passwd");
        assert_eq!(sanitize_filename("..\\..\\boot.ini"), "boot.ini");
        assert_eq!(sanitize_filename("/abs/path/x.bin"), "x.bin");
        assert_eq!(sanitize_filename(".."), "download");
        assert_eq!(sanitize_filename(""), "download");
        assert_eq!(sanitize_filename("  . "), "download");
        assert_eq!(sanitize_filename("a\0b\nc.txt"), "abc.txt");
        assert_eq!(sanitize_filename("what?*.txt"), "what__.txt");
        assert_eq!(sanitize_filename("CON.txt"), "_CON.txt");
        assert_eq!(sanitize_filename("com1"), "_com1");
        assert_eq!(sanitize_filename("com10.txt"), "com10.txt");
        let long = "ä".repeat(300);
        assert!(sanitize_filename(&long).len() <= 200);
        assert!(sanitize_filename(&long).chars().all(|c| c == 'ä'));
    }

    #[tokio::test]
    async fn interrupted_reports_pause_cancel_and_a_dropped_owner() {
        let (tx, mut rx) = watch::channel(RunState::Running);
        let t = tokio::spawn(async move { interrupted(&mut rx).await });
        tokio::time::sleep(Duration::from_millis(20)).await;
        tx.send(RunState::Paused).unwrap();
        assert!(matches!(t.await.unwrap(), Stop::Paused));

        let (tx, mut rx) = watch::channel(RunState::Running);
        drop(tx);
        assert!(matches!(interrupted(&mut rx).await, Stop::Cancelled));
    }
}
