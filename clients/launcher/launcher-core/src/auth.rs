//! Device-flow login state machine, independent of any UI.
//!
//! Start with [`DeviceLogin::start`], paint `device.user_code` and
//! `device.verification_uri`, then call [`DeviceLogin::poll`] every
//! `interval` seconds. The state machine never sleeps, so the Tauri shell
//! (or a test) decides when the next poll happens.

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
}

#[derive(Debug, Clone)]
pub enum LoginPoll {
    Pending,
    SlowDown { interval: Duration },
    Done { token: String, expires_at: String, expires_in: u64 },
}

pub fn device_name(hostname: &str) -> String {
    let host = if hostname.trim().is_empty() { "PC" } else { hostname.trim() };

    format!("LANoMAT Launcher on {host}")
}

impl DeviceLogin {
    pub async fn start(api: &ApiClient, device_name: &str, now: Instant) -> Result<Self, LoginError> {
        let device = api.start_device(device_name).await?;
        let interval = Duration::from_secs(device.interval.max(1));

        Ok(Self {
            deadline: now + Duration::from_secs(device.expires_in.max(interval.as_secs())),
            device,
            interval,
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

    /// One poll. On a token, returns the absolute expiry so the caller can
    /// store it next to the token.
    pub async fn poll(&mut self, api: &ApiClient, now: Instant) -> Result<LoginPoll, LoginError> {
        if self.is_expired(now) {
            return Err(LoginError::Expired);
        }

        match api.poll_token(&self.device.device_code).await? {
            PollEvent::Pending => Ok(LoginPoll::Pending),
            PollEvent::SlowDown => {
                self.interval += Duration::from_secs(5);
                Ok(LoginPoll::SlowDown { interval: self.interval })
            }
            PollEvent::Token(token) => {
                let expires_at = chrono_like_rfc3339(now, token.expires_in);
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

/// RFC 3339 in UTC without pulling in `chrono`: the agent stores the same
/// shape and the server does not parse it back.
fn chrono_like_rfc3339(now: Instant, expires_in: u64) -> String {
    let _ = now;
    let seconds = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs() + expires_in)
        .unwrap_or(expires_in);
    unix_to_rfc3339(seconds)
}

pub fn unix_to_rfc3339(seconds: u64) -> String {
    let days = (seconds / 86_400) as i64;
    let rest = seconds % 86_400;
    let (hour, minute, second) = (rest / 3_600, (rest % 3_600) / 60, rest % 60);
    let (year, month, day) = civil_from_days(days);

    format!("{year:04}-{month:02}-{day:02}T{hour:02}:{minute:02}:{second:02}Z")
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn formats_unix_timestamps_as_rfc3339() {
        assert_eq!(unix_to_rfc3339(0), "1970-01-01T00:00:00Z");
        assert_eq!(unix_to_rfc3339(1_700_000_000), "2023-11-14T22:13:20Z");
    }

    #[test]
    fn device_name_is_bounded_and_human() {
        assert_eq!(device_name(""), "LANoMAT Launcher on PC");
        assert_eq!(device_name(" lan-pc "), "LANoMAT Launcher on lan-pc");
    }
}
