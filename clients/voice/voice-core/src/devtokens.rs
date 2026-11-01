//! Local LiveKit token minting from an API key and secret. This is for development servers
//! (`livekit-server --dev`, loopback tests) and for `latency-test`: the LANoMAT v1 API issues one
//! identity per user, while the latency test needs two identities in one room.
//! Credentials come from the environment, never from files in the repository.

use std::time::Duration;

use livekit_api::access_token::{AccessToken, VideoGrants};

use crate::api::{AudioSettings, JoinGrant};
use crate::error::{Error, Result};

pub const ENV_URL: &str = "LANOMAT_VOICE_LIVEKIT_URL";
pub const ENV_API_KEY: &str = "LANOMAT_VOICE_LIVEKIT_API_KEY";
pub const ENV_API_SECRET: &str = "LANOMAT_VOICE_LIVEKIT_API_SECRET";

#[derive(Clone)]
pub struct DevKeys {
    url: String,
    api_key: String,
    api_secret: String,
}

impl std::fmt::Debug for DevKeys {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DevKeys")
            .field("url", &self.url)
            .field("api_key", &"<redacted>")
            .field("api_secret", &"<redacted>")
            .finish()
    }
}

impl DevKeys {
    pub fn new(
        url: impl Into<String>,
        api_key: impl Into<String>,
        api_secret: impl Into<String>,
    ) -> Self {
        Self {
            url: url.into(),
            api_key: api_key.into(),
            api_secret: api_secret.into(),
        }
    }

    pub fn from_env() -> Result<Self> {
        let get = |name: &str| {
            std::env::var(name)
                .ok()
                .filter(|v| !v.is_empty())
                .ok_or_else(|| Error::Usage(format!("{name} is not set")))
        };
        Ok(Self::new(
            get(ENV_URL)?,
            get(ENV_API_KEY)?,
            get(ENV_API_SECRET)?,
        ))
    }

    /// A join grant for `room` with the given identity (publish and subscribe allowed).
    pub fn grant(&self, room: &str, identity: &str, audio: AudioSettings) -> Result<JoinGrant> {
        let token = AccessToken::with_api_key(&self.api_key, &self.api_secret)
            .with_identity(identity)
            .with_name(identity)
            .with_ttl(Duration::from_secs(600))
            .with_grants(VideoGrants {
                room_join: true,
                room: room.to_owned(),
                can_publish: Some(true),
                can_subscribe: Some(true),
                ..Default::default()
            })
            .to_jwt()
            .map_err(|e| Error::Usage(format!("could not mint a token: {e}")))?;
        Ok(JoinGrant {
            url: self.url.clone(),
            token,
            identity: identity.to_owned(),
            muted: false,
            audio,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn minted_grant_carries_identity_and_hides_secrets() {
        let keys = DevKeys::new("ws://localhost:7880", "k-unit", "s-unit-secret");
        let g = keys
            .grant("room-a", "probe-tx", AudioSettings::default())
            .unwrap();
        assert_eq!(g.identity, "probe-tx");
        assert_eq!(g.token.split('.').count(), 3, "a JWT has three parts");
        assert!(!format!("{keys:?}").contains("s-unit-secret"));
        assert!(!format!("{g:?}").contains(&g.token));
    }
}
