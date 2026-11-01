//! HTTP client for the device flow and the bearer API v1.

use std::future::Future;
use std::time::Duration;

use reqwest::{Method, StatusCode, Url};
use serde::de::DeserializeOwned;

use super::poll::{PollEvent, PollState, PollStep};
use super::types::{
    ClientConfig, DeviceAuthorization, JoinGrant, Me, Room, RoomsResponse, TokenErrorBody,
    TokenResponse,
};
use crate::error::{Error, Result};

const USER_AGENT: &str = concat!("lanomat-voice/", env!("CARGO_PKG_VERSION"));

#[derive(Clone)]
pub struct ApiClient {
    http: reqwest::Client,
    base: Url,
    token: Option<String>,
}

impl std::fmt::Debug for ApiClient {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ApiClient")
            .field("base", &self.base.as_str())
            .field("token", &self.token.as_ref().map(|_| "<redacted>"))
            .finish()
    }
}

/// Parses and normalises the LANoMAT origin the user typed (`https://host`, optional path prefix).
pub fn normalize_base_url(input: &str) -> Result<Url> {
    let trimmed = input.trim();
    let with_scheme = if trimmed.contains("://") {
        trimmed.to_owned()
    } else {
        format!("https://{trimmed}")
    };
    let mut url =
        Url::parse(&with_scheme).map_err(|e| Error::InvalidUrl(format!("{input}: {e}")))?;
    if !matches!(url.scheme(), "http" | "https") {
        return Err(Error::InvalidUrl(format!(
            "{input}: only http and https are supported"
        )));
    }
    if url.host_str().is_none() {
        return Err(Error::InvalidUrl(format!("{input}: missing host")));
    }
    url.set_query(None);
    url.set_fragment(None);
    // A trailing slash makes `Url::join` keep a path prefix.
    if !url.path().ends_with('/') {
        let path = format!("{}/", url.path());
        url.set_path(&path);
    }
    Ok(url)
}

impl ApiClient {
    pub fn new(base_url: &str) -> Result<Self> {
        let base = normalize_base_url(base_url)?;
        let http = reqwest::Client::builder()
            .user_agent(USER_AGENT)
            .connect_timeout(Duration::from_secs(10))
            .timeout(Duration::from_secs(20))
            .build()
            .map_err(|e| Error::Network(e.to_string()))?;
        Ok(Self {
            http,
            base,
            token: None,
        })
    }

    pub fn with_token(mut self, token: impl Into<String>) -> Self {
        self.token = Some(token.into());
        self
    }

    /// The normalised origin, always ending in `/`.
    pub fn base_url(&self) -> &str {
        self.base.as_str()
    }

    fn url(&self, path: &str) -> Result<Url> {
        self.base
            .join(path)
            .map_err(|e| Error::InvalidUrl(format!("{path}: {e}")))
    }

    // ---- device flow (unauthenticated) ----

    /// `POST /api/voice-client/device`.
    pub async fn start_device_flow(&self, device_name: &str) -> Result<DeviceAuthorization> {
        let body = serde_json::json!({ "device_name": device_name });
        let resp = self
            .http
            .post(self.url("api/voice-client/device")?)
            .json(&body)
            .send()
            .await
            .map_err(net_err)?;
        decode(resp).await
    }

    /// One poll of `POST /api/voice-client/token`, classified for the state machine. Never fails:
    /// anything unexpected becomes [`PollEvent::Transient`].
    pub async fn poll_token_once(&self, device_code: &str) -> PollEvent {
        let url = match self.url("api/voice-client/token") {
            Ok(u) => u,
            Err(e) => return PollEvent::Transient(e.to_string()),
        };
        let resp = match self
            .http
            .post(url)
            .json(&serde_json::json!({ "device_code": device_code }))
            .send()
            .await
        {
            Ok(r) => r,
            Err(e) => return PollEvent::Transient(net_err(e).to_string()),
        };
        let status = resp.status();
        if status == StatusCode::TOO_MANY_REQUESTS {
            return PollEvent::RateLimited;
        }
        let bytes = match resp.bytes().await {
            Ok(b) => b,
            Err(e) => return PollEvent::Transient(net_err(e).to_string()),
        };
        classify_token_response(status, &bytes)
    }

    /// Polls until the user decides, the code expires or polling fails. `sleep` is injected so
    /// tests do not wait; production passes `tokio::time::sleep`.
    pub async fn wait_for_token<S, Fut>(
        &self,
        auth: &DeviceAuthorization,
        mut sleep: S,
    ) -> Result<String>
    where
        S: FnMut(Duration) -> Fut,
        Fut: Future<Output = ()>,
    {
        let mut state = PollState::new(auth.interval, auth.expires_in);
        sleep(state.first_wait()).await;
        loop {
            let event = self.poll_token_once(&auth.device_code).await;
            match state.on(event) {
                PollStep::Done(token) => return Ok(token),
                PollStep::Fail(e) => return Err(e),
                PollStep::Wait(d) => sleep(d).await,
            }
        }
    }

    // ---- bearer API v1 ----

    pub async fn me(&self) -> Result<Me> {
        self.get("api/voice-client/v1/me").await
    }

    pub async fn config(&self) -> Result<ClientConfig> {
        self.get("api/voice-client/v1/config").await
    }

    pub async fn rooms(&self) -> Result<Vec<Room>> {
        let r: RoomsResponse = self.get("api/voice-client/v1/rooms").await?;
        Ok(r.rooms)
    }

    /// `POST rooms/{room}/token`. The room is addressed by id only, as the server never takes an
    /// identity or room name from the client.
    pub async fn room_token(&self, room_id: u64) -> Result<JoinGrant> {
        let path = format!("api/voice-client/v1/rooms/{room_id}/token");
        let resp = self
            .bearer(Method::POST, &path)?
            .send()
            .await
            .map_err(net_err)?;
        decode(resp).await
    }

    async fn get<T: DeserializeOwned>(&self, path: &str) -> Result<T> {
        let resp = self
            .bearer(Method::GET, path)?
            .send()
            .await
            .map_err(net_err)?;
        decode(resp).await
    }

    fn bearer(&self, method: Method, path: &str) -> Result<reqwest::RequestBuilder> {
        let token = self.token.as_deref().ok_or(Error::NotLoggedIn)?;
        Ok(self
            .http
            .request(method, self.url(path)?)
            .bearer_auth(token)
            .header(reqwest::header::ACCEPT, "application/json"))
    }
}

/// Exposed for the tests of the token endpoint; pure.
pub(crate) fn classify_token_response(status: StatusCode, body: &[u8]) -> PollEvent {
    if status.is_success() {
        return match serde_json::from_slice::<TokenResponse>(body) {
            Ok(t) if !t.access_token.is_empty() => PollEvent::Approved(t.access_token),
            _ => PollEvent::Transient("token response without an access_token".into()),
        };
    }
    if status == StatusCode::BAD_REQUEST {
        return match serde_json::from_slice::<TokenErrorBody>(body) {
            Ok(b) => match b.error.as_str() {
                "authorization_pending" => PollEvent::Pending,
                "slow_down" => PollEvent::SlowDown(b.interval),
                "access_denied" => PollEvent::Denied,
                "expired_token" => PollEvent::Expired,
                "invalid_grant" => PollEvent::InvalidGrant,
                other => PollEvent::Transient(format!("unknown error code `{other}`")),
            },
            Err(_) => PollEvent::Transient("unreadable 400 response".into()),
        };
    }
    if status.is_server_error() {
        return PollEvent::Transient(format!("HTTP {}", status.as_u16()));
    }
    // 422 and friends: a bug on our side, retrying cannot help but must not loop forever either.
    PollEvent::Transient(format!("unexpected HTTP {}", status.as_u16()))
}

fn net_err(e: reqwest::Error) -> Error {
    // reqwest includes the URL in its message; it never contains the bearer token (a header).
    if e.is_timeout() {
        Error::Network("request timed out".into())
    } else if e.is_connect() {
        Error::Network(format!("connection failed ({e})"))
    } else {
        Error::Network(e.to_string())
    }
}

async fn decode<T: DeserializeOwned>(resp: reqwest::Response) -> Result<T> {
    let status = resp.status();
    if status.is_success() {
        let bytes = resp.bytes().await.map_err(net_err)?;
        return serde_json::from_slice(&bytes)
            .map_err(|e| Error::BadResponse(format!("invalid JSON: {e}")));
    }
    let message = resp
        .bytes()
        .await
        .ok()
        .and_then(|b| serde_json::from_slice::<serde_json::Value>(&b).ok())
        .and_then(|v| v.get("message").and_then(|m| m.as_str().map(str::to_owned)))
        .unwrap_or_default();
    Err(match status {
        StatusCode::UNAUTHORIZED => Error::Unauthorized,
        StatusCode::NOT_FOUND => {
            Error::NotFound("room (or API route) not found or not visible to you".into())
        }
        StatusCode::GONE => Error::RoomArchived,
        StatusCode::TOO_MANY_REQUESTS => Error::RateLimited,
        StatusCode::SERVICE_UNAVAILABLE => Error::Unavailable,
        other => Error::Server {
            status: other.as_u16(),
            message,
        },
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{Arc, Mutex};
    use wiremock::matchers::{body_json, header, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn client(server: &MockServer) -> ApiClient {
        ApiClient::new(&server.uri()).unwrap()
    }

    #[test]
    fn base_url_normalisation() {
        assert_eq!(
            normalize_base_url("example.org").unwrap().as_str(),
            "https://example.org/"
        );
        assert_eq!(
            normalize_base_url("https://example.org/").unwrap().as_str(),
            "https://example.org/"
        );
        assert_eq!(
            normalize_base_url("http://localhost:8080/sub?x=1#f")
                .unwrap()
                .as_str(),
            "http://localhost:8080/sub/"
        );
        assert!(matches!(
            normalize_base_url("ftp://example.org"),
            Err(Error::InvalidUrl(_))
        ));
        assert!(matches!(
            normalize_base_url("http://"),
            Err(Error::InvalidUrl(_))
        ));
    }

    #[test]
    fn debug_output_hides_secrets() {
        let c = ApiClient::new("https://example.org")
            .unwrap()
            .with_token("lvc_supersecret");
        assert!(!format!("{c:?}").contains("supersecret"));
        let grant: JoinGrant = serde_json::from_value(serde_json::json!({
            "url": "wss://x", "token": "jwt-secret", "identity": "u1", "muted": false, "audio": {}
        }))
        .unwrap();
        assert!(!format!("{grant:?}").contains("jwt-secret"));
    }

    #[test]
    fn classification_of_token_responses() {
        use PollEvent::*;
        let c = |s: u16, b: &str| {
            classify_token_response(StatusCode::from_u16(s).unwrap(), b.as_bytes())
        };
        assert_eq!(
            c(
                200,
                r#"{"access_token":"t","token_type":"Bearer","expires_in":1}"#
            ),
            Approved("t".into())
        );
        assert_eq!(c(400, r#"{"error":"authorization_pending"}"#), Pending);
        assert_eq!(
            c(400, r#"{"error":"slow_down","interval":10}"#),
            SlowDown(Some(10))
        );
        assert_eq!(c(400, r#"{"error":"slow_down"}"#), SlowDown(None));
        assert_eq!(c(400, r#"{"error":"access_denied"}"#), Denied);
        assert_eq!(c(400, r#"{"error":"expired_token"}"#), Expired);
        assert_eq!(c(400, r#"{"error":"invalid_grant"}"#), InvalidGrant);
        assert!(matches!(c(400, "not json"), Transient(_)));
        assert!(matches!(c(502, ""), Transient(_)));
        assert!(matches!(c(200, r#"{"nope":1}"#), Transient(_)));
    }

    #[tokio::test]
    async fn starts_the_device_flow() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/device"))
            .and(body_json(serde_json::json!({"device_name": "my laptop"})))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "device_code": "dc",
                "user_code": "ABCD-EFGH",
                "verification_url": "https://app.example/talk/connect",
                "verification_url_complete": "https://app.example/talk/connect?code=ABCD-EFGH",
                "expires_in": 600,
                "interval": 5
            })))
            .mount(&server)
            .await;
        let auth = client(&server)
            .start_device_flow("my laptop")
            .await
            .unwrap();
        assert_eq!(auth.user_code, "ABCD-EFGH");
        assert_eq!(auth.interval, 5);
        assert!(auth
            .verification_url_complete
            .unwrap()
            .ends_with("code=ABCD-EFGH"));
    }

    #[tokio::test]
    async fn waits_through_pending_and_slow_down_until_approved() {
        let server = MockServer::start().await;
        let replies = Arc::new(Mutex::new(vec![
            (400, serde_json::json!({"error": "authorization_pending"})),
            (
                400,
                serde_json::json!({"error": "slow_down", "interval": 10}),
            ),
            (400, serde_json::json!({"error": "authorization_pending"})),
            (
                200,
                serde_json::json!({"access_token": "lvc_x", "token_type": "Bearer", "expires_in": 100}),
            ),
        ]));
        let queue = replies.clone();
        Mock::given(method("POST"))
            .and(path("/api/voice-client/token"))
            .and(body_json(serde_json::json!({"device_code": "dc"})))
            .respond_with(move |_: &wiremock::Request| {
                let (status, body) = queue.lock().unwrap().remove(0);
                ResponseTemplate::new(status).set_body_json(body)
            })
            .expect(4)
            .mount(&server)
            .await;
        let auth = DeviceAuthorization {
            device_code: "dc".into(),
            user_code: "ABCD-EFGH".into(),
            verification_url: "x".into(),
            verification_url_complete: None,
            expires_in: 600,
            interval: 5,
        };
        let slept = Arc::new(Mutex::new(Vec::new()));
        let log = slept.clone();
        let token = client(&server)
            .wait_for_token(&auth, move |d| {
                log.lock().unwrap().push(d.as_secs());
                std::future::ready(())
            })
            .await
            .unwrap();
        assert_eq!(token, "lvc_x");
        // first wait, then after pending (5), after slow_down (10), after pending (10).
        assert_eq!(*slept.lock().unwrap(), vec![5, 5, 10, 10]);
    }

    #[tokio::test]
    async fn denied_stops_polling() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/token"))
            .respond_with(
                ResponseTemplate::new(400)
                    .set_body_json(serde_json::json!({"error": "access_denied"})),
            )
            .expect(1)
            .mount(&server)
            .await;
        let auth = DeviceAuthorization {
            device_code: "dc".into(),
            user_code: "u".into(),
            verification_url: "x".into(),
            verification_url_complete: None,
            expires_in: 600,
            interval: 5,
        };
        let err = client(&server)
            .wait_for_token(&auth, |_| std::future::ready(()))
            .await
            .unwrap_err();
        assert!(matches!(err, Error::PairingDenied));
    }

    #[tokio::test]
    async fn bearer_routes_send_the_token_and_parse() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/me"))
            .and(header("authorization", "Bearer lvc_tok"))
            .respond_with(ResponseTemplate::new(200).set_body_json(
                serde_json::json!({"id": 7, "name": "Ada", "role": "participant", "identity": "u7"}),
            ))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/rooms"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "rooms": [{"id": 1, "name": "Lobby", "description": null, "kind": "general"}]
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "api_version": 1, "livekit_url": "wss://lk.example", "voice_ready": true,
                "audio": {"bitrate": 24000, "dtx": true, "red": false, "jitter_buffer_target_ms": null,
                          "echo_cancellation": true, "noise_suppression": true, "auto_gain_control": true}
            })))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/rooms/1/token"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "url": "wss://lk.example", "token": "jwt", "identity": "u7", "muted": true,
                "audio": {"bitrate": 32000, "dtx": false, "red": true}
            })))
            .mount(&server)
            .await;
        let c = client(&server).with_token("lvc_tok");
        assert_eq!(c.me().await.unwrap().identity, "u7");
        let rooms = c.rooms().await.unwrap();
        assert_eq!(rooms[0].name, "Lobby");
        let cfg = c.config().await.unwrap();
        assert!(cfg.voice_ready && !cfg.audio.red);
        let grant = c.room_token(1).await.unwrap();
        assert!(grant.muted);
        assert_eq!(grant.audio.bitrate, 32_000);
        // Missing keys fall back to the server defaults.
        assert!(grant.audio.noise_suppression);
    }

    #[tokio::test]
    async fn status_codes_map_to_clear_errors() {
        let server = MockServer::start().await;
        for (room, status) in [
            (401, 401),
            (404, 404),
            (410, 410),
            (429, 429),
            (503, 503),
            (500, 500),
        ] {
            Mock::given(method("POST"))
                .and(path(format!("/api/voice-client/v1/rooms/{room}/token")))
                .respond_with(
                    ResponseTemplate::new(status)
                        .set_body_json(serde_json::json!({"message": "boom"})),
                )
                .mount(&server)
                .await;
        }
        let c = client(&server).with_token("t");
        assert!(matches!(c.room_token(401).await, Err(Error::Unauthorized)));
        assert!(matches!(c.room_token(404).await, Err(Error::NotFound(_))));
        assert!(matches!(c.room_token(410).await, Err(Error::RoomArchived)));
        assert!(matches!(c.room_token(429).await, Err(Error::RateLimited)));
        assert!(matches!(c.room_token(503).await, Err(Error::Unavailable)));
        assert!(
            matches!(c.room_token(500).await, Err(Error::Server { status: 500, ref message }) if message == "boom")
        );
    }

    #[tokio::test]
    async fn without_a_token_bearer_calls_fail_locally() {
        let c = ApiClient::new("https://example.org").unwrap();
        assert!(matches!(c.me().await, Err(Error::NotLoggedIn)));
    }

    #[tokio::test]
    async fn unreachable_server_is_a_network_error_not_a_panic() {
        let c = ApiClient::new("http://127.0.0.1:1")
            .unwrap()
            .with_token("t");
        assert!(matches!(c.me().await, Err(Error::Network(_))));
    }
}
