//! The small bearer API subset the launcher needs.
//!
//! The server contract is the same one `lanomat-agent` uses: RFC 8628 device
//! pairing plus the documented `/api/v1` recipe endpoints. The launcher never
//! invents a second auth scheme.
//!
//! Everything here is pure HTTP + decoding. No retries and no sleeps: the poll
//! cadence belongs to [`crate::auth`] and the shell, not to the transport.

use std::time::Duration;

use reqwest::{redirect, Client, StatusCode};
use serde::{Deserialize, Serialize};
use url::Url;

use crate::types::{DeviceStart, MeResponse, RecipePlan, RecipeSummary, ServerList, TokenGrant};

#[derive(Debug, thiserror::Error)]
pub enum ApiError {
    #[error("invalid server URL: {0}")]
    Url(String),
    #[error("network error: {0}")]
    Network(String),
    #[error("server answered HTTP {status}: {message}")]
    Http { status: u16, message: String },
    #[error("not signed in")]
    NotSignedIn,
    #[error("unexpected server response: {0}")]
    Decode(String),
}

impl ApiError {
    /// True for "the bearer token is missing, dead or not allowed". The shell
    /// uses this to drop the stored token and show the pairing screen instead of
    /// a generic error.
    pub fn is_auth(&self) -> bool {
        matches!(self, Self::NotSignedIn)
            || matches!(self, Self::Http { status, .. } if *status == 401 || *status == 403)
    }

    /// The HTTP status, when the failure came from a response or from a missing
    /// token (reported as 401).
    pub fn status(&self) -> Option<u16> {
        match self {
            Self::Http { status, .. } => Some(*status),
            Self::NotSignedIn => Some(401),
            _ => None,
        }
    }
}

#[derive(Debug, Clone)]
pub enum PollEvent {
    Pending,
    SlowDown,
    Token(TokenGrant),
    Denied(String),
    Expired,
    Invalid(String),
}

/// How often the launcher repeats its fleet heartbeat. The server asks for 30
/// seconds (`LauncherTerminal::HEARTBEAT_SECONDS`); a state change is reported
/// immediately and does not wait for this tick.
pub const HEARTBEAT_SECONDS: u64 = 30;

/// What a terminal reports it is doing. The server models the same three
/// values (`idle`, `playing`, `offline`) plus a legacy `online`; the launcher
/// always sends an explicit state instead of leaving the column to default.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum TerminalState {
    Idle,
    Playing,
    Offline,
}

impl TerminalState {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Idle => "idle",
            Self::Playing => "playing",
            Self::Offline => "offline",
        }
    }
}

/// The longest terminal name the server stores.
pub const NAME_MAX: usize = 60;
/// The longest recipe slug the server accepts in `current_game_slug`/`installed`.
pub const SLUG_MAX: usize = 120;
/// The server stores the launcher version in a 32-character column.
pub const VERSION_MAX: usize = 32;

/// One fleet heartbeat: `POST /api/v1/terminals/heartbeat`.
///
/// The bearer token is the identity, never a client-supplied id, so a paired
/// launcher can only ever write its own row. The payload is always built
/// through [`TerminalHeartbeat::new`], which sanitises every field to the
/// server's validation rules (lengths, slug shape, arch characters) — a bad
/// hostname or a hand-edited ledger must not turn into a 422 the monitor never
/// sees.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct TerminalHeartbeat {
    pub name: String,
    pub os: String,
    pub arch: String,
    pub version: String,
    pub state: TerminalState,
    /// The game that is being installed or played right now.
    pub current_game_slug: Option<String>,
    /// The published recipes this machine can start, from `installs.json`.
    pub installed: Vec<String>,
    pub gamepad: bool,
}

impl TerminalHeartbeat {
    /// A heartbeat for this machine's platform, idle and without games. The
    /// caller fills in what it knows via the setters below.
    pub fn new(name: &str, version: &str) -> Self {
        Self {
            name: clipped(name.trim(), NAME_MAX).unwrap_or_else(|| "PC".to_string()),
            os: crate::types::Os::current().as_str().to_string(),
            arch: clean_arch(std::env::consts::ARCH),
            version: clipped(version.trim(), VERSION_MAX).unwrap_or_else(|| "unknown".to_string()),
            state: TerminalState::Idle,
            current_game_slug: None,
            installed: Vec::new(),
            gamepad: false,
        }
    }

    /// Pin the platform instead of reading the build host (used by tests and by
    /// a launcher that reports for a chrooted/containerised terminal).
    pub fn with_platform(mut self, os: crate::types::Os, arch: &str) -> Self {
        self.os = os.as_str().to_string();
        self.arch = clean_arch(arch);
        self
    }

    pub fn state(mut self, state: TerminalState) -> Self {
        self.state = state;
        self
    }

    /// The game in the foreground. An invalid slug is dropped rather than sent
    /// (the server would answer 422 and the monitor would show nothing).
    pub fn current_game(mut self, slug: Option<&str>) -> Self {
        self.current_game_slug = slug.and_then(clean_slug);
        self
    }

    /// The installed recipes, de-duplicated and lowercased: the server matches
    /// slugs against recipe slugs, so "UT2004" and "ut2004" are one game.
    pub fn installed<I>(mut self, slugs: I) -> Self
    where
        I: IntoIterator<Item = String>,
    {
        let mut seen: Vec<String> = Vec::new();

        for slug in slugs {
            let Some(slug) = clean_slug(&slug) else {
                continue;
            };

            if !seen.contains(&slug) {
                seen.push(slug);
            }
        }

        self.installed = seen;
        self
    }

    pub fn gamepad(mut self, connected: bool) -> Self {
        self.gamepad = connected;
        self
    }
}

/// What the server answers to a heartbeat. Only the confirmation is used; the
/// id is opaque (a different backend may number or string it).
#[derive(Debug, Clone, PartialEq, Eq, Deserialize)]
pub struct HeartbeatAck {
    #[serde(default)]
    pub ok: bool,
    #[serde(default)]
    pub terminal_id: serde_json::Value,
    #[serde(default)]
    pub server_time: Option<String>,
}

/// The slug shape the server validates (`^[A-Za-z0-9][A-Za-z0-9._-]*$`, at most
/// 120 characters), lowercased for comparison.
pub fn clean_slug(value: &str) -> Option<String> {
    let slug = value.trim().to_ascii_lowercase();
    let mut chars = slug.chars();

    let first = chars.next()?;

    if slug.len() > SLUG_MAX
        || !first.is_ascii_alphanumeric()
        || !chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-'))
    {
        return None;
    }

    Some(slug)
}

/// At most `max` characters, `None` when the result is empty. Character-based,
/// so a UTF-8 name is never cut mid-codepoint.
fn clipped(value: &str, max: usize) -> Option<String> {
    let value: String = value.chars().take(max).collect();

    if value.is_empty() {
        None
    } else {
        Some(value)
    }
}

/// The arch column is 16 characters of `[A-Za-z0-9_.-]`; anything else the
/// build host reports is replaced rather than rejected.
fn clean_arch(arch: &str) -> String {
    let arch: String = arch
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-'))
        .take(16)
        .collect();

    if arch.is_empty() {
        "unknown".to_string()
    } else {
        arch
    }
}

#[derive(Clone)]
pub struct ApiClient {
    http: Client,
    base: Url,
    token: Option<String>,
}

#[derive(Deserialize)]
struct RecipesBody {
    #[serde(default)]
    data: Vec<RecipeSummary>,
}

/// The two RFC 8628 error shapes the server sends: `{"error": "...",
/// "error_description": "..."}` and Laravel's `{"message": "..."}`.
#[derive(Deserialize)]
struct ErrorBody {
    #[serde(default)]
    error: String,
    #[serde(default)]
    error_description: Option<String>,
    #[serde(default)]
    message: Option<String>,
}

impl ApiClient {
    pub fn new(base_url: &str) -> Result<Self, ApiError> {
        let base = normalize_base_url(base_url)?;

        let http = Client::builder()
            .user_agent(concat!("lanomat-launcher/", env!("CARGO_PKG_VERSION")))
            .connect_timeout(Duration::from_secs(10))
            .read_timeout(Duration::from_secs(60))
            // A bearer token must never follow a redirect to another host (or
            // downgrade to plain http). Same-origin redirects still work.
            .redirect(redirect::Policy::custom(|attempt| {
                let previous = attempt.previous().last();

                match previous {
                    Some(previous)
                        if previous.host_str() == attempt.url().host_str()
                            && previous.scheme() == attempt.url().scheme()
                            && previous.port_or_known_default()
                                == attempt.url().port_or_known_default()
                            && attempt.previous().len() < 5 =>
                    {
                        attempt.follow()
                    }
                    _ => attempt.stop(),
                }
            }))
            .build()
            .map_err(|error| ApiError::Network(error.to_string()))?;

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

    pub fn base_url(&self) -> &str {
        self.base.as_str()
    }

    pub fn token(&self) -> Option<&str> {
        self.token.as_deref()
    }

    fn endpoint(&self, path: &str) -> Result<Url, ApiError> {
        self.base
            .join(path.trim_start_matches('/'))
            .map_err(|error| ApiError::Url(error.to_string()))
    }

    async fn send(
        &self,
        request: reqwest::RequestBuilder,
        authenticated: bool,
    ) -> Result<reqwest::Response, ApiError> {
        let request = if authenticated {
            let token = self.token.as_deref().ok_or(ApiError::NotSignedIn)?;
            request.bearer_auth(token)
        } else {
            request
        };

        let response = request
            .send()
            .await
            .map_err(|error| ApiError::Network(error.to_string()))?;

        if response.status().is_success() {
            return Ok(response);
        }

        let status = response.status().as_u16();
        let canonical = response
            .status()
            .canonical_reason()
            .unwrap_or("request failed")
            .to_string();
        let message = response
            .json::<ErrorBody>()
            .await
            .ok()
            .and_then(|body| body.error_description.or(body.message).or(Some(body.error)))
            .filter(|message| !message.is_empty())
            .unwrap_or(canonical);

        Err(ApiError::Http { status, message })
    }

    /// Step 1 of RFC 8628: ask for a `user_code`. The device name is only a
    /// label for the server's "approved devices" list.
    pub async fn start_device(&self, device_name: &str) -> Result<DeviceStart, ApiError> {
        let url = self.endpoint("api/mcp/device")?;
        let response = self
            .send(
                self.http
                    .post(url)
                    .json(&serde_json::json!({ "device_name": device_name })),
                false,
            )
            .await?;

        response
            .json()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))
    }

    /// Step 3 of RFC 8628: one poll. HTTP 200 is the token, a 400 with an
    /// `error` code is a state transition, 429 is a `slow_down`.
    pub async fn poll_token(&self, device_code: &str) -> Result<PollEvent, ApiError> {
        let url = self.endpoint("api/mcp/token")?;
        let response = self
            .http
            .post(url)
            .json(&serde_json::json!({ "device_code": device_code }))
            .send()
            .await
            .map_err(|error| ApiError::Network(error.to_string()))?;

        let status = response.status();

        if status == StatusCode::OK {
            let token = response
                .json()
                .await
                .map_err(|error| ApiError::Decode(error.to_string()))?;
            return Ok(PollEvent::Token(token));
        }

        if status == StatusCode::TOO_MANY_REQUESTS {
            return Ok(PollEvent::SlowDown);
        }

        let body = response
            .json::<ErrorBody>()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))?;

        Ok(match body.error.as_str() {
            "authorization_pending" => PollEvent::Pending,
            "slow_down" => PollEvent::SlowDown,
            "access_denied" => PollEvent::Denied(
                body.error_description
                    .filter(|reason| !reason.is_empty())
                    .unwrap_or_else(|| body.error.clone()),
            ),
            "expired_token" => PollEvent::Expired,
            other => PollEvent::Invalid(if other.is_empty() {
                "invalid_grant".into()
            } else {
                other.to_string()
            }),
        })
    }

    pub async fn me(&self) -> Result<MeResponse, ApiError> {
        let url = self.endpoint("api/v1/me")?;
        let response = self.send(self.http.get(url), true).await?;

        response
            .json()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))
    }

    pub async fn recipes(&self) -> Result<Vec<RecipeSummary>, ApiError> {
        let url = self.endpoint("api/v1/recipes")?;
        let response = self.send(self.http.get(url), true).await?;
        let body: RecipesBody = response
            .json()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))?;

        Ok(body.data)
    }

    pub async fn plan(&self, slug: &str, os: crate::types::Os) -> Result<RecipePlan, ApiError> {
        let url = self.endpoint(&format!("api/v1/recipes/{slug}/plan"))?;
        let response = self
            .send(
                self.http
                    .post(url)
                    .json(&serde_json::json!({ "os": os.as_str(), "dry_run": false })),
                true,
            )
            .await?;

        response
            .json()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))
    }

    /// The aggregated server browser: active/recent event servers plus the
    /// permanent servers, with no per-viewer player names.
    pub async fn servers(&self) -> Result<ServerList, ApiError> {
        let url = self.endpoint("api/v1/servers")?;
        let response = self.send(self.http.get(url), true).await?;
        let body = response
            .json()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))?;

        Ok(body)
    }

    /// `POST /api/v1/terminals/heartbeat` — one fleet heartbeat.
    ///
    /// Best effort by contract: a 422 means the payload drifted from the
    /// server's validation rules, a 401 means the pairing is dead. Neither is
    /// fatal for the launcher, so the caller decides (the shell logs at debug
    /// and tries again on the next tick).
    pub async fn heartbeat(&self, heartbeat: &TerminalHeartbeat) -> Result<HeartbeatAck, ApiError> {
        let url = self.endpoint("api/v1/terminals/heartbeat")?;
        let response = self.send(self.http.post(url).json(heartbeat), true).await?;

        response
            .json()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))
    }
}

/// Trim, validate and canonicalise a server base URL.
///
/// A path prefix is kept (`https://lan.test/lanomat` → `.../lanomat/`), a query
/// string and a fragment are dropped (they belong to a request, and `Url::join`
/// would drop them anyway while making the base look meaningful). Trailing
/// slashes are stripped and exactly one is added back, so joining a relative
/// endpoint keeps the prefix.
fn normalize_base_url(base_url: &str) -> Result<Url, ApiError> {
    let trimmed = base_url.trim();

    if trimmed.is_empty() {
        return Err(ApiError::Url("the server URL is empty".into()));
    }

    let mut url = Url::parse(trimmed).map_err(|error| ApiError::Url(error.to_string()))?;

    if !matches!(url.scheme(), "http" | "https") {
        return Err(ApiError::Url("use http:// or https://".into()));
    }

    // Credentials in the URL would be written to config.json and shown in the
    // status/footer; the bearer token already authenticates the launcher.
    if !url.username().is_empty() || url.password().is_some() {
        return Err(ApiError::Url(
            "the server URL must not carry credentials".into(),
        ));
    }

    if url.host_str().is_none_or(str::is_empty) {
        return Err(ApiError::Url("the server URL needs a host".into()));
    }

    url.set_query(None);
    url.set_fragment(None);

    let path = url.path().trim_end_matches('/').to_string();
    url.set_path(&format!("{path}/"));

    Ok(url)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes_the_base_url_and_rejects_other_schemes() {
        let client = ApiClient::new("https://lan.test/base").unwrap();
        assert_eq!(client.base_url(), "https://lan.test/base/");

        assert!(matches!(
            ApiClient::new("file:///etc/passwd"),
            Err(ApiError::Url(_))
        ));
        assert!(matches!(ApiClient::new("not a url"), Err(ApiError::Url(_))));
    }

    #[test]
    fn keeps_a_path_prefix_and_drops_query_and_fragment() {
        assert_eq!(
            ApiClient::new("  https://lan.test/lanomat/?event=x#top  ")
                .unwrap()
                .base_url(),
            "https://lan.test/lanomat/"
        );
        assert_eq!(
            ApiClient::new("https://lan.test//").unwrap().base_url(),
            "https://lan.test/"
        );
        assert_eq!(
            ApiClient::new("http://127.0.0.1:8080/lanomat")
                .unwrap()
                .base_url(),
            "http://127.0.0.1:8080/lanomat/"
        );
        assert!(matches!(ApiClient::new("   "), Err(ApiError::Url(_))));
    }
}
