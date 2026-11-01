//! Polling state machine of the device flow, kept free of I/O so it can be tested exhaustively.

use std::time::Duration;

use crate::error::Error;

/// What one poll of `POST /api/voice-client/token` produced.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PollEvent {
    /// `400 authorization_pending`: the user has not decided yet.
    Pending,
    /// `400 slow_down`, optionally carrying the server's new interval in seconds.
    SlowDown(Option<u64>),
    /// `429`: treated like slow_down (back off), never as a failure.
    RateLimited,
    /// `400 access_denied`.
    Denied,
    /// `400 expired_token`.
    Expired,
    /// `400 invalid_grant`.
    InvalidGrant,
    /// `200`: the access token.
    Approved(String),
    /// A network error or a 5xx: worth retrying a few times.
    Transient(String),
}

#[derive(Debug)]
pub enum PollStep {
    /// Sleep this long, then poll again.
    Wait(Duration),
    Done(String),
    Fail(Error),
}

/// Extra seconds added per slow_down/429 when the server does not name a new interval (RFC 8628).
const BACKOFF: Duration = Duration::from_secs(5);
/// Consecutive transient failures tolerated before giving up.
const MAX_TRANSIENT: u32 = 5;
const MIN_INTERVAL: Duration = Duration::from_secs(1);

#[derive(Debug, Clone)]
pub struct PollState {
    interval: Duration,
    budget: Duration,
    waited: Duration,
    transient: u32,
}

impl PollState {
    /// `interval` and `expires_in` come from the device authorization response.
    pub fn new(interval: u64, expires_in: u64) -> Self {
        Self {
            interval: Duration::from_secs(interval).max(MIN_INTERVAL),
            budget: Duration::from_secs(expires_in),
            waited: Duration::ZERO,
            transient: 0,
        }
    }

    pub fn interval(&self) -> Duration {
        self.interval
    }

    /// Duration to sleep before the very first poll.
    pub fn first_wait(&self) -> Duration {
        self.interval
    }

    pub fn on(&mut self, event: PollEvent) -> PollStep {
        match event {
            PollEvent::Approved(token) => return PollStep::Done(token),
            PollEvent::Denied => return PollStep::Fail(Error::PairingDenied),
            PollEvent::Expired => return PollStep::Fail(Error::PairingExpired),
            PollEvent::InvalidGrant => return PollStep::Fail(Error::InvalidGrant),
            PollEvent::Pending => self.transient = 0,
            PollEvent::SlowDown(new) => {
                self.transient = 0;
                self.interval = match new {
                    Some(secs) => Duration::from_secs(secs).max(self.interval),
                    None => self.interval + BACKOFF,
                };
            }
            PollEvent::RateLimited => {
                self.transient = 0;
                self.interval += BACKOFF;
            }
            PollEvent::Transient(message) => {
                self.transient += 1;
                if self.transient >= MAX_TRANSIENT {
                    return PollStep::Fail(Error::Network(message));
                }
            }
        }
        self.waited += self.interval;
        if self.waited > self.budget {
            return PollStep::Fail(Error::PairingExpired);
        }
        PollStep::Wait(self.interval)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn secs(s: u64) -> Duration {
        Duration::from_secs(s)
    }

    #[test]
    fn pending_keeps_the_interval() {
        let mut p = PollState::new(5, 600);
        assert!(matches!(p.on(PollEvent::Pending), PollStep::Wait(d) if d == secs(5)));
        assert!(matches!(p.on(PollEvent::Pending), PollStep::Wait(d) if d == secs(5)));
    }

    #[test]
    fn slow_down_uses_the_servers_interval_or_adds_five_seconds() {
        let mut p = PollState::new(5, 600);
        assert!(matches!(p.on(PollEvent::SlowDown(Some(10))), PollStep::Wait(d) if d == secs(10)));
        assert!(matches!(p.on(PollEvent::SlowDown(None)), PollStep::Wait(d) if d == secs(15)));
        // A server interval lower than ours never speeds us up.
        assert!(matches!(p.on(PollEvent::SlowDown(Some(3))), PollStep::Wait(d) if d == secs(15)));
    }

    #[test]
    fn rate_limit_backs_off_instead_of_failing() {
        let mut p = PollState::new(5, 600);
        assert!(matches!(p.on(PollEvent::RateLimited), PollStep::Wait(d) if d == secs(10)));
    }

    #[test]
    fn terminal_errors_map_to_distinct_errors() {
        let mut p = PollState::new(5, 600);
        assert!(matches!(
            p.on(PollEvent::Denied),
            PollStep::Fail(Error::PairingDenied)
        ));
        assert!(matches!(
            p.on(PollEvent::Expired),
            PollStep::Fail(Error::PairingExpired)
        ));
        assert!(matches!(
            p.on(PollEvent::InvalidGrant),
            PollStep::Fail(Error::InvalidGrant)
        ));
    }

    #[test]
    fn approval_returns_the_token() {
        let mut p = PollState::new(5, 600);
        assert!(matches!(p.on(PollEvent::Approved("x".into())), PollStep::Done(t) if t == "x"));
    }

    #[test]
    fn gives_up_when_the_code_lifetime_is_used_up() {
        let mut p = PollState::new(5, 12);
        assert!(matches!(p.on(PollEvent::Pending), PollStep::Wait(_)));
        assert!(matches!(p.on(PollEvent::Pending), PollStep::Wait(_)));
        assert!(matches!(
            p.on(PollEvent::Pending),
            PollStep::Fail(Error::PairingExpired)
        ));
    }

    #[test]
    fn transient_failures_are_retried_a_few_times_then_fail() {
        let mut p = PollState::new(5, 6000);
        for _ in 0..4 {
            assert!(matches!(
                p.on(PollEvent::Transient("down".into())),
                PollStep::Wait(_)
            ));
        }
        assert!(matches!(
            p.on(PollEvent::Transient("down".into())),
            PollStep::Fail(Error::Network(_))
        ));
    }

    #[test]
    fn a_successful_poll_resets_the_transient_counter() {
        let mut p = PollState::new(5, 6000);
        for _ in 0..4 {
            p.on(PollEvent::Transient("x".into()));
        }
        p.on(PollEvent::Pending);
        assert!(matches!(
            p.on(PollEvent::Transient("x".into())),
            PollStep::Wait(_)
        ));
    }

    #[test]
    fn interval_has_a_one_second_floor() {
        let p = PollState::new(0, 600);
        assert_eq!(p.interval(), secs(1));
    }
}
