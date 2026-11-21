//! Error strings that cross the IPC bridge.
//!
//! The shell never translates a core error: the UI maps the documented core
//! shapes (`missing LAN files: …`, `network error: …`, …) to German itself. The
//! one thing the shell guarantees is that the bearer token never appears in a
//! message, not even when a server echoes it back.

use launcher_core::install::InstallError;

pub const REDACTED: &str = "***";

/// Replace every occurrence of the bearer token with `***`.
pub fn redact(text: impl Into<String>, secret: Option<&str>) -> String {
    let text = text.into();

    match secret.filter(|secret| !secret.is_empty()) {
        Some(secret) => text.replace(secret, REDACTED),
        None => text,
    }
}

/// A finished install that was cancelled is reported as `cancelled`, the word
/// the UI recognises, instead of an error.
pub fn install(error: &InstallError, token: Option<&str>) -> String {
    redact(error.to_string(), token)
}

/// The UI decides between cancelled and failed from this.
pub fn is_cancelled(message: &str) -> bool {
    message.to_ascii_lowercase().contains("cancelled")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn redacts_the_token_everywhere() {
        let message = "server said: token abc123 is valid for abc123";

        assert_eq!(
            redact(message, Some("abc123")),
            "server said: token *** is valid for ***"
        );
        assert_eq!(redact(message, None), message);
        assert_eq!(redact(message, Some("")), message);
    }

    #[test]
    fn cancelled_is_recognised_in_both_core_shapes() {
        assert!(is_cancelled("cancelled"));
        assert!(is_cancelled("download cancelled"));
        assert!(!is_cancelled("missing LAN files: UT2004 DVD"));
    }

    #[test]
    fn an_install_error_keeps_the_core_shape() {
        let error = InstallError::Cancelled;

        assert_eq!(install(&error, Some("tok")), "cancelled");
    }
}
