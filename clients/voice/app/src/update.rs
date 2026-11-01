//! The update check behind the `get_update_status` command: ask the server what it runs and
//! offers (`GET config`, `client` block) and let [`voice_core::update::assess`] decide. Kept out
//! of `features.rs` and free of Tauri types so it can be tested against a mock server.

use voice_core::update::{assess, UpdateStatus};

use crate::engine::{Engine, UiError};

/// Compares this build with what the connected server wants. A server too old to send the
/// `client` block counts as "up to date"; connection trouble is an error the UI may ignore.
pub async fn update_status(engine: &Engine) -> Result<UpdateStatus, UiError> {
    let config = engine.client()?.config().await?;
    Ok(assess(
        voice_core::build_info().version,
        config.client.as_ref(),
    ))
}

#[cfg(test)]
mod tests {
    use std::sync::Arc;

    use serde_json::json;
    use voice_core::api::{FileTokenStore, StoredCredentials, TokenStore};
    use wiremock::matchers::{method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    use super::*;

    async fn status_for(config: serde_json::Value) -> UpdateStatus {
        let server = MockServer::start().await;
        let dir = tempfile::tempdir().unwrap();
        let store = FileTokenStore::new(dir.path().join("credentials.json"));
        store
            .save(&StoredCredentials {
                base_url: server.uri(),
                access_token: "tok".into(),
                device_name: "test".into(),
            })
            .unwrap();
        let engine = Engine::new(
            store,
            dir.path().join("settings.json"),
            Arc::new(|_| {}),
            true,
        );
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(ResponseTemplate::new(200).set_body_json(config))
            .mount(&server)
            .await;
        update_status(&engine).await.unwrap()
    }

    #[tokio::test]
    async fn the_servers_client_block_is_read_and_a_dev_build_never_nags() {
        let base = json!({"api_version": 1, "livekit_url": null, "voice_ready": false});
        assert_eq!(status_for(base).await, UpdateStatus::UpToDate);
        let with_block = json!({
            "api_version": 1, "livekit_url": null, "voice_ready": false,
            "client": {"min_client_version": "m12-1-gaaaaaaa", "latest_client_version": "m12-9-gbbbbbbb"}
        });
        // The test binary is a `dev` build: unorderable, so it fails open.
        assert_eq!(status_for(with_block).await, UpdateStatus::UpToDate);
    }
}
