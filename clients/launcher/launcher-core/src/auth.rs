//! Device-flow login state machine, independent of any UI.
//!
//! Start with [`DeviceLogin::start`], paint `device.user_code` and
//! `device.verification_uri`, then call [`DeviceLogin::poll`] every
//! `interval` seconds. The state machine never sleeps, so the Tauri shell
//! (or a test) decides when the next poll happens.
//!
//! Time is injected: every method takes the caller's [`Instant`], and
//! [`DeviceLogin::start_at`] additionally takes the Unix epoch of that instant.
//! That makes token expiry deterministic in tests without a clock crate and
//! keeps `sleep` out of the core.

use std::fmt;
use std::time::{Duration, Instant};

use crate::api::{ApiClient, ApiError, PollEvent};
use crate::types::DeviceStart;

#[derive(Debug, thiserror::Error)]
pub enum LoginError {
    #[error(transparent)]
    Api(#[from] ApiError),
    #[error("the login code expired")]
    Expired,
    #[error("the login was denied: {0}")]
    Denied(String),
    #[error("the server rejected the login: {0}")]
    Invalid(String),
}

#[derive(Debug, Clone)]
pub struct DeviceLogin {
    pub device: DeviceStart,
    interval: Duration,
    deadline: Instant,
    /// The caller's [`Instant`] at `start`.
    started_at: Instant,
    /// The Unix epoch (whole seconds) at `started_at`, used only to turn a
    /// relative `expires_in` into an absolute, storable timestamp.
    epoch_seconds: u64,
}

#[derive(Clone)]
pub enum LoginPoll {
    Pending,
    SlowDown {
        interval: Duration,
    },
    Done {
        token: String,
        expires_at: String,
        expires_in: u64,
    },
}

impl fmt::Debug for LoginPoll {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Pending => f.write_str("Pending"),
            Self::SlowDown { interval } => f
                .debug_struct("SlowDown")
                .field("interval", interval)
                .finish(),
            Self::Done {
                expires_at,
                expires_in,
                ..
            } => f
                .debug_struct("Done")
                // The bearer token is a credential: never print it.
                .field("token", &"<redacted>")
                .field("expires_at", expires_at)
                .field("expires_in", expires_in)
                .finish(),
        }
    }
}

pub fn device_name(hostname: &str) -> String {
    let host = if hostname.trim().is_empty() {
        "PC"
    } else {
        hostname.trim()
    };

    format!("LANoMAT Launcher on {host}")
}

/// Unix time in whole seconds. A clock before 1970 is reported as 0.
pub fn unix_now() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_secs())
        .unwrap_or(0)
}

impl DeviceLogin {
    pub async fn start(
        api: &ApiClient,
        device_name: &str,
        now: Instant,
    ) -> Result<Self, LoginError> {
        Self::start_at(api, device_name, now, unix_now()).await
    }

    /// Same as [`Self::start`], but with an explicit Unix epoch for `now`.
    pub async fn start_at(
        api: &ApiClient,
        device_name: &str,
        now: Instant,
        epoch_seconds: u64,
    ) -> Result<Self, LoginError> {
        let device = api.start_device(device_name).await?;
        let interval = Duration::from_secs(device.interval.max(1));

        Ok(Self {
            deadline: now + Duration::from_secs(device.expires_in.max(interval.as_secs())),
            device,
            interval,
            started_at: now,
            epoch_seconds,
        })
    }

    pub fn interval(&self) -> Duration {
        self.interval
    }

    pub fn expires_in(&self, now: Instant) -> Duration {
        self.deadline.saturating_duration_since(now)
    }

    pub fn is_expired(&self, now: Instant) -> bool {
        now >= self.deadline
    }

    /// The Unix time of `now`, derived from the injected start epoch.
    fn epoch_at(&self, now: Instant) -> u64 {
        self.epoch_seconds
            .saturating_add(now.saturating_duration_since(self.started_at).as_secs())
    }

    /// One poll. On a token, returns the absolute expiry so the caller can
    /// store it next to the token.
    pub async fn poll(&mut self, api: &ApiClient, now: Instant) -> Result<LoginPoll, LoginError> {
        if self.is_expired(now) {
            return Err(LoginError::Expired);
        }

        match api.poll_token(&self.device.device_code).await? {
            PollEvent::Pending => Ok(LoginPoll::Pending),
            PollEvent::SlowDown => {
                // RFC 8628 §3.5: add five seconds and keep polling.
                self.interval += Duration::from_secs(5);
                Ok(LoginPoll::SlowDown {
                    interval: self.interval,
                })
            }
            PollEvent::Token(token) => {
                let expires_at = token_expiry(self.epoch_at(now), token.expires_in);
                Ok(LoginPoll::Done {
                    token: token.access_token,
                    expires_at,
                    expires_in: token.expires_in,
                })
            }
            PollEvent::Denied(reason) => Err(LoginError::Denied(reason)),
            PollEvent::Expired => Err(LoginError::Expired),
            PollEvent::Invalid(reason) => Err(LoginError::Invalid(reason)),
        }
    }
}

/// The absolute expiry of a token obtained at `epoch_seconds`, as RFC 3339 UTC
/// without pulling in `chrono`: the agent stores the same shape and the server
/// does not parse it back.
pub fn token_expiry(epoch_seconds: u64, expires_in: u64) -> String {
    unix_to_rfc3339(epoch_seconds.saturating_add(expires_in))
}

pub fn unix_to_rfc3339(seconds: u64) -> String {
    let days = (seconds / 86_400) as i64;
    let rest = seconds % 86_400;
    let (hour, minute, second) = (rest / 3_600, (rest % 3_600) / 60, rest % 60);
    let (year, month, day) = civil_from_days(days);

    format!("{year:04}-{month:02}-{day:02}T{hour:02}:{minute:02}:{second:02}Z")
}

/// Parses the UTC subset this crate writes (`YYYY-MM-DDTHH:MM:SSZ`), plus an
/// ignored fraction and an explicit `+00:00`. Anything else — including a
/// non-zero offset — is `None`, so a stored expiry can only ever be read as
/// UTC.
pub fn parse_rfc3339(timestamp: &str) -> Option<u64> {
    let text = timestamp.trim();
    let bytes = text.as_bytes();

    if bytes.len() < 19 {
        return None;
    }

    if bytes[4] != b'-'
        || bytes[7] != b'-'
        || bytes[10] != b'T'
        || bytes[13] != b':'
        || bytes[16] != b':'
    {
        return None;
    }

    let year = digits(bytes, 0, 4)? as i64;
    let month = digits(bytes, 5, 2)? as u32;
    let day = digits(bytes, 8, 2)? as u32;
    let hour = digits(bytes, 11, 2)?;
    let minute = digits(bytes, 14, 2)?;
    let second = digits(bytes, 17, 2)?;

    if !(1..=12).contains(&month)
        || !(1..=31).contains(&day)
        || hour > 23
        || minute > 59
        || second > 59
    {
        return None;
    }

    let suffix = text[19..]
        .trim_start_matches(|character: char| character == '.' || character.is_ascii_digit());
    if !matches!(suffix, "" | "Z" | "z" | "+00:00" | "-00:00") {
        return None;
    }

    let days = days_from_civil(year, month, day);
    if days < 0 {
        return None;
    }

    Some(days as u64 * 86_400 + hour * 3_600 + minute * 60 + second)
}

fn digits(bytes: &[u8], start: usize, len: usize) -> Option<u64> {
    let slice = bytes.get(start..start + len)?;
    let mut value = 0u64;

    for byte in slice {
        if !byte.is_ascii_digit() {
            return None;
        }
        value = value * 10 + u64::from(byte - b'0');
    }

    Some(value)
}

/// Howard Hinnant's civil_from_days, valid for the whole 1970–2106 range.
fn civil_from_days(days: i64) -> (i64, u32, u32) {
    let z = days + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1_460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = mp + if mp < 10 { 3 } else { -9 };
    let year = y + if m <= 2 { 1 } else { 0 };

    (year, m as u32, d as u32)
}

/// The inverse of [`civil_from_days`].
fn days_from_civil(year: i64, month: u32, day: u32) -> i64 {
    let year = if month <= 2 { year - 1 } else { year };
    let era = if year >= 0 { year } else { year - 399 } / 400;
    let yoe = year - era * 400;
    let month = i64::from(month);
    let day_of_year =
        (153 * (if month > 2 { month - 3 } else { month + 9 }) + 2) / 5 + i64::from(day) - 1;
    let day_of_era = yoe * 365 + yoe / 4 - yoe / 100 + day_of_year;

    era * 146_097 + day_of_era - 719_468
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn formats_unix_timestamps_as_rfc3339() {
        assert_eq!(unix_to_rfc3339(0), "1970-01-01T00:00:00Z");
        assert_eq!(unix_to_rfc3339(1_700_000_000), "2023-11-14T22:13:20Z");
        assert_eq!(unix_to_rfc3339(4_102_444_800), "2100-01-01T00:00:00Z");
    }

    #[test]
    fn parses_back_every_timestamp_it_writes() {
        for seconds in [0, 1, 951_782_400, 1_700_000_000, 4_102_444_799] {
            assert_eq!(parse_rfc3339(&unix_to_rfc3339(seconds)), Some(seconds));
        }

        assert_eq!(
            parse_rfc3339("2023-11-14T22:13:20+00:00"),
            Some(1_700_000_000)
        );
        assert_eq!(
            parse_rfc3339("2023-11-14T22:13:20.500Z"),
            Some(1_700_000_000)
        );
        assert_eq!(parse_rfc3339("2023-11-14T22:13:20+02:00"), None);
        assert_eq!(parse_rfc3339("yesterday"), None);
        assert_eq!(parse_rfc3339(""), None);
        assert_eq!(parse_rfc3339("2023-13-14T22:13:20Z"), None);
    }

    #[test]
    fn token_expiry_uses_the_injected_epoch() {
        assert_eq!(token_expiry(1_700_000_000, 3_600), "2023-11-14T23:13:20Z");
        assert_eq!(token_expiry(0, 60), "1970-01-01T00:01:00Z");
        // Saturating, never a panic on an absurd TTL.
        assert_eq!(token_expiry(u64::MAX, 10), unix_to_rfc3339(u64::MAX));
    }

    #[test]
    fn device_name_is_bounded_and_human() {
        assert_eq!(device_name(""), "LANoMAT Launcher on PC");
        assert_eq!(device_name(" lan-pc "), "LANoMAT Launcher on lan-pc");
    }
}
