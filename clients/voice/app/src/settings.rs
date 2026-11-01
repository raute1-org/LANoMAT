//! App settings: a small JSON file next to the credential file. It never contains the access
//! token (that stays in voice-core's 0600 credential store); unknown keys are ignored so an older
//! or newer build can read the file.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

/// Default push-to-talk shortcut offered in the settings (best effort, see the README).
pub const DEFAULT_SHORTCUT: &str = "Control+Alt+Space";

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default)]
pub struct Settings {
    /// Last server origin typed in the connect screen (not a secret), to prefill the field.
    pub server_url: String,
    /// Substring of the input device name; `None` means the system default.
    pub input_device: Option<String>,
    /// Substring of the output device name; `None` means the system default.
    pub output_device: Option<String>,
    /// Transmit continuously after joining instead of push-to-talk.
    pub open_mic: bool,
    /// Capture without echo cancellation, noise suppression and AGC (lowest latency, headsets).
    pub headset_mode: bool,
    /// Closing the window hides it to the tray instead of quitting.
    pub close_to_tray: bool,
    /// Show the small always-on-top "who is speaking" window.
    pub overlay: bool,
    /// Global push-to-talk shortcut (works on X11, Windows and macOS; not on Wayland). Empty = off.
    pub ptt_shortcut: String,
}

impl Default for Settings {
    fn default() -> Self {
        Self {
            server_url: String::new(),
            input_device: None,
            output_device: None,
            open_mic: false,
            headset_mode: false,
            close_to_tray: true,
            overlay: false,
            ptt_shortcut: DEFAULT_SHORTCUT.into(),
        }
    }
}

impl Settings {
    /// Trims text fields and turns blank device names into `None`.
    pub fn normalized(mut self) -> Self {
        self.server_url = self.server_url.trim().to_owned();
        self.ptt_shortcut = self.ptt_shortcut.trim().to_owned();
        self.input_device = self
            .input_device
            .map(|d| d.trim().to_owned())
            .filter(|d| !d.is_empty());
        self.output_device = self
            .output_device
            .map(|d| d.trim().to_owned())
            .filter(|d| !d.is_empty());
        self
    }
}

/// `<config dir>/lanomat-voice/settings.json`, beside `credentials.json`.
pub fn default_path() -> Option<PathBuf> {
    directories::ProjectDirs::from("", "", "lanomat-voice")
        .map(|d| d.config_dir().join("settings.json"))
}

/// Loads the settings; a missing or unreadable file yields the defaults (a broken settings file
/// must never keep the app from starting).
pub fn load(path: &Path) -> Settings {
    std::fs::read(path)
        .ok()
        .and_then(|bytes| serde_json::from_slice::<Settings>(&bytes).ok())
        .unwrap_or_default()
        .normalized()
}

pub fn save(path: &Path, settings: &Settings) -> std::io::Result<()> {
    if let Some(dir) = path.parent() {
        std::fs::create_dir_all(dir)?;
    }
    let json = serde_json::to_vec_pretty(settings).map_err(std::io::Error::other)?;
    let tmp = path.with_extension("json.tmp");
    std::fs::write(&tmp, json)?;
    std::fs::rename(&tmp, path)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trip_keeps_every_field_and_no_token_field_exists() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("settings.json");
        let s = Settings {
            server_url: "https://example.test".into(),
            input_device: Some("USB".into()),
            output_device: None,
            open_mic: true,
            headset_mode: true,
            close_to_tray: false,
            overlay: true,
            ptt_shortcut: "F13".into(),
        };
        save(&path, &s).unwrap();
        assert_eq!(load(&path), s);
        let text = std::fs::read_to_string(&path).unwrap().to_lowercase();
        assert!(!text.contains("token"), "{text}");
        assert!(!text.contains("secret"), "{text}");
    }

    #[test]
    fn missing_broken_and_partial_files_fall_back_to_defaults() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("settings.json");
        assert_eq!(load(&path), Settings::default());
        std::fs::write(&path, b"{ not json").unwrap();
        assert_eq!(load(&path), Settings::default());
        std::fs::write(&path, br#"{"open_mic": true, "future_key": 1}"#).unwrap();
        let s = load(&path);
        assert!(s.open_mic);
        assert!(s.close_to_tray, "unset keys keep their default");
    }

    #[test]
    fn normalization_trims_and_drops_blank_devices() {
        let s = Settings {
            server_url: "  https://x.test ".into(),
            input_device: Some("  ".into()),
            output_device: Some(" Headset ".into()),
            ptt_shortcut: " F13 ".into(),
            ..Settings::default()
        }
        .normalized();
        assert_eq!(s.server_url, "https://x.test");
        assert_eq!(s.input_device, None);
        assert_eq!(s.output_device.as_deref(), Some("Headset"));
        assert_eq!(s.ptt_shortcut, "F13");
    }
}
