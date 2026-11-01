//! Wire types of `docs/infra/native-voice-client-api.md`.

use serde::{Deserialize, Serialize};

/// Response of `POST /api/voice-client/device`.
#[derive(Debug, Clone, Deserialize, PartialEq)]
pub struct DeviceAuthorization {
    /// Secret half; only ever sent back to the token endpoint, never printed.
    pub device_code: String,
    pub user_code: String,
    pub verification_url: String,
    #[serde(default)]
    pub verification_url_complete: Option<String>,
    pub expires_in: u64,
    pub interval: u64,
}

/// Success body of `POST /api/voice-client/token`.
#[derive(Clone, Deserialize)]
pub struct TokenResponse {
    pub access_token: String,
    #[serde(default)]
    pub token_type: String,
    #[serde(default)]
    pub expires_in: u64,
}

impl std::fmt::Debug for TokenResponse {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TokenResponse")
            .field("access_token", &"<redacted>")
            .field("token_type", &self.token_type)
            .field("expires_in", &self.expires_in)
            .finish()
    }
}

/// Error body of the token endpoint (`400 {"error": ..., "interval"?: ...}`).
#[derive(Debug, Clone, Deserialize, PartialEq)]
pub struct TokenErrorBody {
    pub error: String,
    #[serde(default)]
    pub interval: Option<u64>,
}

/// `GET me`.
#[derive(Debug, Clone, Deserialize, PartialEq)]
pub struct Me {
    pub id: u64,
    pub name: String,
    pub role: String,
    /// The LiveKit identity (`u<id>`) the client has in a room.
    pub identity: String,
}

/// Audio tuning defaults the server hands out (`config.audio`, and again with every room token).
/// Unknown keys are ignored; every key is optional so an older or newer server never breaks us.
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct AudioSettings {
    pub bitrate: u64,
    pub dtx: bool,
    pub red: bool,
    pub echo_cancellation: bool,
    pub noise_suppression: bool,
    pub auto_gain_control: bool,
    /// Browser-only knob (`RTCRtpReceiver.jitterBufferTarget`); the Rust SDK has no equivalent.
    pub jitter_buffer_target_ms: Option<u32>,
}

impl Default for AudioSettings {
    /// The server's own defaults (`config/webvoice.php`), used when the server sends nothing.
    fn default() -> Self {
        Self {
            bitrate: 24_000,
            dtx: true,
            red: true,
            echo_cancellation: true,
            noise_suppression: true,
            auto_gain_control: true,
            jitter_buffer_target_ms: None,
        }
    }
}

/// `GET config`.
#[derive(Debug, Clone, Deserialize, PartialEq)]
pub struct ClientConfig {
    pub api_version: u32,
    pub livekit_url: Option<String>,
    pub voice_ready: bool,
    #[serde(default)]
    pub audio: AudioSettings,
}

/// One entry of `GET rooms`.
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq)]
pub struct Room {
    pub id: u64,
    pub name: String,
    #[serde(default)]
    pub description: Option<String>,
    /// `general` or `match` today; kept as a string so a new kind does not break parsing.
    pub kind: String,
}

#[derive(Debug, Clone, Deserialize)]
pub(crate) struct RoomsResponse {
    pub rooms: Vec<Room>,
}

/// `POST rooms/{room}/token`: everything needed to connect to LiveKit.
#[derive(Clone, Deserialize)]
pub struct JoinGrant {
    pub url: String,
    /// LiveKit join token (short lived); never logged.
    pub token: String,
    pub identity: String,
    /// A moderator mute: the token has no publish right, so join listen-only.
    #[serde(default)]
    pub muted: bool,
    #[serde(default)]
    pub audio: AudioSettings,
}

impl std::fmt::Debug for JoinGrant {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("JoinGrant")
            .field("url", &self.url)
            .field("token", &"<redacted>")
            .field("identity", &self.identity)
            .field("muted", &self.muted)
            .field("audio", &self.audio)
            .finish()
    }
}
