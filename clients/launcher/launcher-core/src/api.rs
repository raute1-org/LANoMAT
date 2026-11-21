//! The small bearer API subset the launcher needs.
//!
//! The server contract is the same one `lanomat-agent` uses: RFC 8628 device
//! pairing plus the documented `/api/v1` recipe endpoints. The launcher never
//! invents a second auth scheme.

use std::time::Duration;

use reqwest::{Client, StatusCode};
use serde::Deserialize;
use url::Url;

use crate::types::{DeviceStart, MeResponse, RecipePlan, RecipeSummary, TokenGrant};

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
    pub fn is_auth(&self) -> bool {
        matches!(self, Self::NotSignedIn)
            || matches!(self, Self::Http { status, .. } if *status == 401 || *status == 403)
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
        let mut url = Url::parse(base_url.trim()).map_err(|error| ApiError::Url(error.to_string()))?;
        if !matches!(url.scheme(), "http" | "https") {
            return Err(ApiError::Url("use http:// or https://".into()));
        }
        url.set_query(None);
        url.set_fragment(None);
        if !url.path().ends_with('/') {
            let path = format!("{}/", url.path());
            url.set_path(&path);
        }

        let http = Client::builder()
            .user_agent(concat!("lanomat-launcher/", env!("CARGO_PKG_VERSION")))
            .connect_timeout(Duration::from_secs(10))
            .read_timeout(Duration::from_secs(60))
            .build()
            .map_err(|error| ApiError::Network(error.to_string()))?;

        Ok(Self {
            http,
            base: url,
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

    async fn send(&self, request: reqwest::RequestBuilder, authenticated: bool) -> Result<reqwest::Response, ApiError> {
        let request = if authenticated {
            let token = self.token.as_deref().ok_or(ApiError::NotSignedIn)?;
            request.bearer_auth(token)
        } else {
            request
        };

        let response = request.send().await.map_err(|error| ApiError::Network(error.to_string()))?;

        if response.status().is_success() {
            return Ok(response);
        }

        let status = response.status().as_u16();
        let canonical = response.status().canonical_reason().unwrap_or("request failed").to_string();
        let message = response
            .json::<ErrorBody>()
            .await
            .ok()
            .and_then(|body| body.error_description.or(body.message).or(Some(body.error)))
            .filter(|message| !message.is_empty())
            .unwrap_or(canonical);

        Err(ApiError::Http { status, message })
    }

    pub async fn start_device(&self, device_name: &str) -> Result<DeviceStart, ApiError> {
        let url = self.endpoint("api/mcp/device")?;
        let response = self
            .send(
                self.http.post(url).json(&serde_json::json!({ "device_name": device_name })),
                false,
            )
            .await?;

        response.json().await.map_err(|error| ApiError::Decode(error.to_string()))
    }

    pub async fn poll_token(&self, device_code: &str) -> Result<PollEvent, ApiError> {
        let url = self.endpoint("api/mcp/token")?;
        let response = self
            .http
            .post(url)
            .json(&serde_json::json!({ "device_code": device_code }))
            .send()
            .await
            .map_err(|error| ApiError::Network(error.to_string()))?;

        if response.status() == StatusCode::OK {
            let token = response.json().await.map_err(|error| ApiError::Decode(error.to_string()))?;
            return Ok(PollEvent::Token(token));
        }

        if response.status() == StatusCode::TOO_MANY_REQUESTS {
            return Ok(PollEvent::SlowDown);
        }

        let body = response
            .json::<ErrorBody>()
            .await
            .map_err(|error| ApiError::Decode(error.to_string()))?;

        Ok(match body.error.as_str() {
            "authorization_pending" => PollEvent::Pending,
            "slow_down" => PollEvent::SlowDown,
            "access_denied" => PollEvent::Denied(body.error_description.unwrap_or_else(|| body.error.clone())),
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

        response.json().await.map_err(|error| ApiError::Decode(error.to_string()))
    }

    pub async fn recipes(&self) -> Result<Vec<RecipeSummary>, ApiError> {
        let url = self.endpoint("api/v1/recipes")?;
        let response = self.send(self.http.get(url), true).await?;
        let body: RecipesBody = response.json().await.map_err(|error| ApiError::Decode(error.to_string()))?;

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

        response.json().await.map_err(|error| ApiError::Decode(error.to_string()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes_the_base_url_and_rejects_other_schemes() {
        let client = ApiClient::new("https://lan.test/base").unwrap();
        assert_eq!(client.base_url(), "https://lan.test/base/");

        assert!(matches!(ApiClient::new("file:///etc/passwd"), Err(ApiError::Url(_))));
        assert!(matches!(ApiClient::new("not a url"), Err(ApiError::Url(_))));
    }
}
