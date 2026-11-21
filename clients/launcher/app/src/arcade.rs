//! Arcade (cabinet) mode: the flag, the fullscreen window, and who may leave it.
//!
//! A terminal is started by `install-terminal.sh` with `--arcade` (or
//! `LANOMAT_ARCADE=1`). `--kiosk` / `LANOMAT_KIOSK=1` additionally locks the
//! window: Escape (keyboard or gamepad B) leaves fullscreen only when the mode
//! is not kiosk, so a visitor cannot hand back a half-configured desktop.
//!
//! The flags are parsed exactly once, in `main`, before Tauri starts. That keeps
//! `--arcade` idempotent: repeated flags, the argument and the environment
//! variable, or a restart on top of an already-fullscreen window all produce the
//! same mode and the same window state — nothing toggles.

use serde::Serialize;
use tauri::Manager;

/// How this process was started.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize)]
pub struct ArcadeMode {
    /// Fullscreen, large type, no settings chrome; the connect screen leads
    /// with the QR code so the next guest can pair their account.
    pub arcade: bool,
    /// The fullscreen is locked: only ending the process leaves it.
    pub kiosk: bool,
}

impl ArcadeMode {
    /// Read the process arguments and the environment. `--kiosk` implies
    /// `--arcade` (a locked window that is not fullscreen would be a bug).
    pub fn detect() -> Self {
        let arcade = std::env::var("LANOMAT_ARCADE").ok();
        let kiosk = std::env::var("LANOMAT_KIOSK").ok();

        Self::from_sources(
            std::env::args().skip(1),
            arcade.as_deref(),
            kiosk.as_deref(),
        )
    }

    /// The pure part of [`Self::detect`], testable without a process.
    pub fn from_sources<I>(args: I, arcade_env: Option<&str>, kiosk_env: Option<&str>) -> Self
    where
        I: IntoIterator<Item = String>,
    {
        let mut arcade = is_on(arcade_env);
        let mut kiosk = is_on(kiosk_env);

        for arg in args {
            match arg.trim() {
                "--arcade" => arcade = true,
                "--kiosk" => kiosk = true,
                _ => {}
            }
        }

        Self {
            arcade: arcade || kiosk,
            kiosk,
        }
    }

    /// Whether Escape / gamepad B may leave fullscreen. A kiosk cabinet stays
    /// fullscreen until the launcher process ends.
    pub fn escapable(self) -> bool {
        self.arcade && !self.kiosk
    }
}

/// `1`, `true`, `yes`, `on` — anything else (including unset) is off.
fn is_on(value: Option<&str>) -> bool {
    value.is_some_and(|value| {
        matches!(
            value.trim().to_ascii_lowercase().as_str(),
            "1" | "true" | "yes" | "on"
        )
    })
}

/// Put the main window into arcade fullscreen.
///
/// Idempotent and best effort: an already-fullscreen window is left alone (no
/// flicker when both the flag and the environment variable are set, or when
/// `setup` runs after a restart), and a window manager that refuses fullscreen
/// must never block the launcher. A kiosk cabinet also stays on top, so a game
/// that exits back to the launcher does not end up behind a browser.
pub fn enter(app: &tauri::AppHandle, mode: ArcadeMode) {
    if !mode.arcade {
        return;
    }

    let Some(window) = app.get_webview_window("main") else {
        tracing::debug!(target: "lanomat::arcade", "no main window to make fullscreen");
        return;
    };

    if !window.is_fullscreen().unwrap_or(false) {
        if let Err(error) = window.set_fullscreen(true) {
            tracing::debug!(target: "lanomat::arcade", %error, "could not go fullscreen");
        }
    }

    if mode.kiosk {
        if let Err(error) = window.set_always_on_top(true) {
            tracing::debug!(target: "lanomat::arcade", %error, "could not keep the window on top");
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(values: &[&str]) -> Vec<String> {
        values.iter().map(|value| value.to_string()).collect()
    }

    #[test]
    fn a_plain_start_is_neither_arcade_nor_kiosk() {
        assert_eq!(
            ArcadeMode::from_sources(args(&[]), None, None),
            ArcadeMode::default()
        );
    }

    #[test]
    fn the_flag_and_the_environment_variable_agree() {
        let by_flag = ArcadeMode::from_sources(args(&["--arcade"]), None, None);
        let by_env = ArcadeMode::from_sources(args(&[]), Some("1"), None);

        assert_eq!(by_flag, by_env);
        assert!(by_flag.arcade);
        assert!(!by_flag.kiosk);
        assert!(by_flag.escapable());
    }

    #[test]
    fn a_zero_or_a_word_that_is_not_yes_leaves_arcade_off() {
        for value in ["0", "false", "", "off", "no"] {
            let mode = ArcadeMode::from_sources(args(&[]), Some(value), None);

            assert!(!mode.arcade, "{value:?} must not start arcade mode");
        }

        for value in ["1", "true", "YES", " on "] {
            assert!(
                ArcadeMode::from_sources(args(&[]), Some(value), None).arcade,
                "{value:?} must start arcade mode"
            );
        }
    }

    #[test]
    fn kiosk_implies_arcade_and_locks_the_fullscreen() {
        for mode in [
            ArcadeMode::from_sources(args(&["--kiosk"]), None, None),
            ArcadeMode::from_sources(args(&[]), None, Some("1")),
        ] {
            assert!(mode.arcade);
            assert!(mode.kiosk);
            assert!(!mode.escapable());
        }
    }

    #[test]
    fn repeated_flags_and_both_sources_stay_one_mode() {
        let once = ArcadeMode::from_sources(args(&["--arcade"]), None, None);
        let twice = ArcadeMode::from_sources(args(&["--arcade", "--arcade"]), Some("1"), None);
        let both = ArcadeMode::from_sources(args(&["--kiosk"]), Some("1"), Some("TRUE"));

        assert_eq!(once, twice);
        assert_eq!(
            both,
            ArcadeMode {
                arcade: true,
                kiosk: true
            }
        );
    }

    #[test]
    fn an_unknown_argument_is_ignored() {
        // `lanomat://install/<slug>` links and single-instance arguments reach
        // this parser too; only the two arcade switches may change the mode.
        assert_eq!(
            ArcadeMode::from_sources(args(&["lanomat://launch/ut2004", "--verbose"]), None, None),
            ArcadeMode::default()
        );
    }
}
