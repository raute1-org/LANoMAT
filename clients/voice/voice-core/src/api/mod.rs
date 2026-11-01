//! Client side of `docs/infra/native-voice-client-api.md`: device-code pairing, bearer API v1,
//! and credential storage.

mod client;
mod poll;
mod store;
mod types;

pub use client::{default_device_name, normalize_base_url, ApiClient};
pub use poll::{PollEvent, PollState, PollStep};
pub use store::{FileTokenStore, StoredCredentials, TokenStore};
pub use types::{
    AudioSettings, ClientConfig, DeviceAuthorization, JoinGrant, Me, Room, TokenResponse,
};
