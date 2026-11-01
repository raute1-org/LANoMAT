//! Shared files (module Files): list, quota, delete, signed download link, tus upload ticket.
//! JSON keys are snake_case; the upload ticket is the browser payload verbatim (camelCase).

use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use super::client::{error_from_response, net_err};
use super::{seg, ApiClient};
use crate::error::{Error, Result};

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct SharedFile {
    pub id: u64,
    pub name: String,
    pub size_bytes: u64,
    pub uploader_name: Option<String>,
    /// `approved` or `pending` (a client sees only its own pending files).
    pub visibility: String,
    pub created_at: Option<String>,
    pub mine: bool,
    pub can_download: bool,
    pub can_delete: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct Quota {
    pub used_bytes: u64,
    pub cap_bytes: u64,
    pub remaining_bytes: u64,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct UploadInfo {
    /// False when the deployment has no file host: there is no upload then.
    pub resumable: bool,
    pub max_file_bytes: u64,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FilesOverview {
    pub event_slug: String,
    pub files: Vec<SharedFile>,
    pub quota: Quota,
    pub upload: UploadInfo,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct QuotaInfo {
    pub quota: Quota,
    pub upload: UploadInfo,
}

/// Where and how to fetch a file. The `url` of a signed link is a credential: never log it.
#[derive(Clone, Default, Deserialize, PartialEq)]
#[serde(default)]
pub struct DownloadLink {
    pub url: String,
    pub name: String,
    pub size_bytes: Option<u64>,
    pub expires_at: Option<String>,
    /// Set by the client, not the server: the legacy local store streams the file from the app
    /// itself, so the request needs the bearer token (a signed file-host link must never get it).
    #[serde(skip)]
    pub bearer_required: bool,
}

impl std::fmt::Debug for DownloadLink {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DownloadLink")
            .field("url", &"<redacted>")
            .field("name", &self.name)
            .field("size_bytes", &self.size_bytes)
            .field("expires_at", &self.expires_at)
            .field("bearer_required", &self.bearer_required)
            .finish()
    }
}

/// Step 1 of a tus upload: the browser payload, camelCase. The ticket is single use and a
/// credential; never log or persist it.
#[derive(Clone, Default, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct UploadTicket {
    pub endpoint: String,
    pub ticket: String,
    pub chunk_size: u64,
    pub expires_in: u64,
    pub name: String,
    pub reservation_id: Option<u64>,
}

impl std::fmt::Debug for UploadTicket {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("UploadTicket")
            .field("endpoint", &self.endpoint)
            .field("ticket", &"<redacted>")
            .field("chunk_size", &self.chunk_size)
            .field("name", &self.name)
            .finish()
    }
}

fn content_disposition_name(h: &reqwest::header::HeaderMap) -> Option<String> {
    let v = h.get(reqwest::header::CONTENT_DISPOSITION)?.to_str().ok()?;
    let part = v.split(';').map(str::trim).find_map(|p| {
        p.strip_prefix("filename=")
            .map(|n| n.trim_matches('"').to_owned())
    })?;
    (!part.is_empty()).then_some(part)
}

impl ApiClient {
    /// `GET events/{event}/files`.
    pub async fn files(&self, event: &str) -> Result<FilesOverview> {
        self.get_v1(&format!("events/{}/files", seg(event)?), &[])
            .await
    }

    /// `GET events/{event}/files/quota` (cheap to poll during an upload).
    pub async fn files_quota(&self, event: &str) -> Result<QuotaInfo> {
        self.get_v1(&format!("events/{}/files/quota", seg(event)?), &[])
            .await
    }

    /// `DELETE files/{file}` (owner or orga; `204`).
    pub async fn delete_file(&self, file_id: u64) -> Result<()> {
        self.send_v1_empty(Method::DELETE, &format!("files/{file_id}"))
            .await
    }

    /// `GET files/{file}/download`: the signed file-host link. A deployment without a file host
    /// streams the file from the app instead; that is reported as a same-origin link that needs
    /// the bearer token (`bearer_required`).
    pub async fn file_download_link(&self, file_id: u64) -> Result<DownloadLink> {
        let path = format!("files/{file_id}/download");
        let resp = self
            .raw_v1(Method::GET, &path)?
            .send()
            .await
            .map_err(net_err)?;
        if !resp.status().is_success() {
            return Err(error_from_response(resp).await);
        }
        let is_json = resp
            .headers()
            .get(reqwest::header::CONTENT_TYPE)
            .and_then(|v| v.to_str().ok())
            .is_some_and(|v| v.contains("json"));
        if is_json {
            let bytes = resp.bytes().await.map_err(net_err)?;
            return serde_json::from_slice(&bytes)
                .map_err(|e| Error::BadResponse(format!("invalid JSON: {e}")));
        }
        let name = content_disposition_name(resp.headers()).unwrap_or_default();
        let size = resp.content_length();
        drop(resp); // the body is fetched again by the downloader, with resume support
        let url = self.absolute(&format!("api/voice-client/v1/{path}"))?;
        Ok(DownloadLink {
            url: url.into(),
            name,
            size_bytes: size,
            expires_at: None,
            bearer_required: true,
        })
    }

    /// `POST events/{event}/files/uploads/ticket`.
    pub async fn upload_ticket(
        &self,
        event: &str,
        name: &str,
        size: u64,
        mime: Option<&str>,
    ) -> Result<UploadTicket> {
        let mut body = json!({"name": name, "size": size});
        if let Some(m) = mime.filter(|m| !m.is_empty()) {
            body["type"] = Value::String(m.to_owned());
        }
        self.send_v1(
            Method::POST,
            &format!("events/{}/files/uploads/ticket", seg(event)?),
            Some(body),
        )
        .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{body_json, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn client(s: &MockServer) -> ApiClient {
        ApiClient::new(&s.uri()).unwrap().with_token("t")
    }

    #[tokio::test]
    async fn list_and_quota_parse_the_documented_example() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/files"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "event_slug": "lan-party-2026",
              "files": [{"id": 21, "name": "maps.zip", "size_bytes": 1048576, "uploader_name": "Ada",
                         "visibility": "approved", "created_at": "2026-11-06T18:00:00+00:00",
                         "mine": false, "can_download": true, "can_delete": false}],
              "quota": {"used_bytes": 4194304, "cap_bytes": 10485760, "remaining_bytes": 6291456},
              "upload": {"resumable": true, "max_file_bytes": 107374182400u64}
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/files/quota"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "quota": {"used_bytes": 1, "cap_bytes": 10, "remaining_bytes": 9},
              "upload": {"resumable": false, "max_file_bytes": 5}
            })))
            .mount(&server)
            .await;
        let c = client(&server);
        let f = c.files("e").await.unwrap();
        assert_eq!(f.files[0].name, "maps.zip");
        assert!(f.files[0].can_download && !f.files[0].can_delete);
        assert_eq!(f.quota.remaining_bytes, 6_291_456);
        assert!(f.upload.resumable);
        let q = c.files_quota("e").await.unwrap();
        assert!(!q.upload.resumable);
    }

    #[tokio::test]
    async fn signed_download_link_and_its_debug_hides_the_url() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/files/21/download"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "url": "https://files.example/dl/p?expires=1&md5=sig&n=maps.zip", "name": "maps.zip",
              "size_bytes": 1048576, "expires_at": "2026-11-06T18:05:00+00:00"
            })))
            .mount(&server)
            .await;
        let l = client(&server).file_download_link(21).await.unwrap();
        assert!(l.url.starts_with("https://files.example/dl/"));
        assert!(!l.bearer_required);
        assert!(!format!("{l:?}").contains("files.example"));
    }

    #[tokio::test]
    async fn legacy_local_store_streams_from_the_app_and_needs_the_bearer() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/files/22/download"))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("Content-Type", "application/zip")
                    .insert_header("Content-Disposition", "attachment; filename=\"maps.zip\"")
                    .set_body_bytes(vec![0u8; 16]),
            )
            .mount(&server)
            .await;
        let l = client(&server).file_download_link(22).await.unwrap();
        assert!(l.bearer_required);
        assert_eq!(l.name, "maps.zip");
        assert!(l.url.ends_with("/api/voice-client/v1/files/22/download"));
    }

    #[tokio::test]
    async fn download_refusals_map_cleanly() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/files/1/download"))
            .respond_with(ResponseTemplate::new(403).set_body_json(json!({"message": "no"})))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/files/2/download"))
            .respond_with(ResponseTemplate::new(503).set_body_json(json!({"message": "später"})))
            .mount(&server)
            .await;
        let c = client(&server);
        assert!(matches!(
            c.file_download_link(1).await,
            Err(Error::Forbidden(_))
        ));
        let e = c.file_download_link(2).await.unwrap_err();
        assert!(matches!(e, Error::Unavailable) && e.retryable());
    }

    #[tokio::test]
    async fn ticket_and_delete() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/events/e/files/uploads/ticket"))
            .and(body_json(
                json!({"name": "game.zip", "size": 1234, "type": "application/zip"}),
            ))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "endpoint": "https://files.example/upload/", "ticket": "tk", "chunkSize": 67108864,
              "expiresIn": 900, "name": "game.zip", "reservationId": 17
            })))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path("/api/voice-client/v1/files/21"))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(
                "/api/voice-client/v1/events/full/files/uploads/ticket",
            ))
            .respond_with(ResponseTemplate::new(422).set_body_json(
                json!({"code": "quota_exceeded", "message": "Dein Speicher ist voll."}),
            ))
            .mount(&server)
            .await;
        let c = client(&server);
        let t = c
            .upload_ticket("e", "game.zip", 1234, Some("application/zip"))
            .await
            .unwrap();
        assert_eq!(t.chunk_size, 67_108_864);
        assert_eq!(t.reservation_id, Some(17));
        assert!(!format!("{t:?}").contains("tk"));
        c.delete_file(21).await.unwrap();
        let e = c.upload_ticket("full", "a", 1, None).await.unwrap_err();
        assert_eq!(e.code(), Some("quota_exceeded"));
    }
}
