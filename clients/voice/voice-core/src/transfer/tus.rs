//! A tus 1.0.0 client over reqwest (creation, HEAD, chunked PATCH, termination), behaving like the
//! web app's `useResumableUploads.ts`: the ticket's `chunkSize` is the PATCH size, an interrupted
//! transfer asks the server for its offset (`HEAD`) and continues from there (the offset is
//! generally not chunk aligned), retries back off, and the server's refusals (403, 413, 507 and
//! the `ticket_*` codes) are final.

use std::path::PathBuf;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Duration;

use base64::Engine;
use futures_util::StreamExt;
use reqwest::header::{CONTENT_LENGTH, CONTENT_TYPE, LOCATION};
use reqwest::{Body, StatusCode, Url};
use tokio::io::{AsyncReadExt, AsyncSeekExt};
use tokio_util::io::ReaderStream;

use super::store::{fingerprint, now_secs, Record, UploadStore};
use super::{interrupted, sleep_or_stop, transfer_err, Ctrl, Phase, Reporter, Stop};
use crate::api::{ApiClient, UploadTicket};

const TUS: &str = "1.0.0";
const DEFAULT_CHUNK: u64 = 8 * 1024 * 1024;
const BODY_BLOCK: usize = 64 * 1024;

/// What to upload. `path` is the canonical path of a regular file (see `check_upload_path`).
#[derive(Debug, Clone)]
pub struct UploadSpec {
    pub event: String,
    pub path: PathBuf,
    pub name: String,
    pub mime: Option<String>,
    pub size: u64,
    pub mtime_secs: u64,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct UploadOutcome {
    /// An interrupted transfer was continued (after a pause, a drop or an app restart).
    pub resumed: bool,
}

enum Probe {
    Offset(u64),
    Gone,
}

enum PatchErr {
    Stop(Stop),
    Retry(String),
}

struct Retrier<'a> {
    delays: &'a [Duration],
    idx: usize,
}

impl Retrier<'_> {
    fn reset(&mut self) {
        self.idx = 0;
    }

    async fn wait(&mut self, rep: &dyn Reporter, ctrl: &mut Ctrl, why: &str) -> Result<(), Stop> {
        let Some(d) = self.delays.get(self.idx).copied() else {
            return Err(transfer_err(
                "network",
                format!("upload interrupted: {why}"),
            ));
        };
        self.idx += 1;
        rep.phase(Phase::Reconnecting);
        sleep_or_stop(ctrl, d).await
    }
}

fn b64(s: &str) -> String {
    base64::engine::general_purpose::STANDARD.encode(s)
}

/// The server's stable codes (web app: `UploadErrorCode`); anything else is `forbidden`.
fn refusal(status: StatusCode, body: &[u8]) -> Option<Stop> {
    let v: serde_json::Value = serde_json::from_slice(body).unwrap_or_default();
    let message = v
        .get("message")
        .and_then(|m| m.as_str())
        .unwrap_or("the file host refused the upload");
    match status {
        StatusCode::PAYLOAD_TOO_LARGE => Some(transfer_err("too_large", message)),
        StatusCode::INSUFFICIENT_STORAGE => Some(transfer_err("host_full", message)),
        StatusCode::FORBIDDEN | StatusCode::UNAUTHORIZED => {
            let code = v.get("code").and_then(|c| c.as_str()).unwrap_or("");
            let known = [
                "too_large",
                "blocked_type",
                "quota_exceeded",
                "ticket_expired",
                "ticket_invalid",
                "ticket_mismatch",
                "uploads_unavailable",
                "host_full",
            ];
            let code = if known.contains(&code) {
                code
            } else {
                "forbidden"
            };
            Some(transfer_err(code, message))
        }
        _ => None,
    }
}

fn header_u64(resp: &reqwest::Response, name: &str) -> Option<u64> {
    resp.headers()
        .get(name)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.trim().parse().ok())
}

async fn head(
    http: &reqwest::Client,
    url: &str,
    ctrl: &mut Ctrl,
) -> Result<Result<Probe, String>, Stop> {
    let req = http.head(url).header("Tus-Resumable", TUS);
    let resp = tokio::select! {
        stop = interrupted(ctrl) => return Err(stop),
        r = req.send() => r,
    };
    let resp = match resp {
        Ok(r) => r,
        Err(e) => return Ok(Err(e.without_url().to_string())),
    };
    let status = resp.status();
    if status.is_success() {
        return Ok(match header_u64(&resp, "upload-offset") {
            Some(o) => Ok(Probe::Offset(o)),
            None => Err("the server sent no Upload-Offset".into()),
        });
    }
    if status == StatusCode::NOT_FOUND || status == StatusCode::GONE {
        return Ok(Ok(Probe::Gone));
    }
    if let Some(stop) = refusal(status, &resp.bytes().await.unwrap_or_default()) {
        return Err(stop);
    }
    Ok(Err(format!("HTTP {}", status.as_u16())))
}

/// `Ok(Probe)` once the server answered; retries transient failures itself.
async fn probe(
    http: &reqwest::Client,
    url: &str,
    retrier: &mut Retrier<'_>,
    rep: &dyn Reporter,
    ctrl: &mut Ctrl,
) -> Result<Probe, Stop> {
    loop {
        match head(http, url, ctrl).await? {
            Ok(p) => return Ok(p),
            Err(why) => retrier.wait(rep, ctrl, &why).await?,
        }
    }
}

async fn create(
    http: &reqwest::Client,
    ticket: &UploadTicket,
    spec: &UploadSpec,
    retrier: &mut Retrier<'_>,
    rep: &dyn Reporter,
    ctrl: &mut Ctrl,
) -> Result<String, Stop> {
    let endpoint = Url::parse(&ticket.endpoint).map_err(|_| {
        transfer_err(
            "uploads_unavailable",
            "the server sent no usable upload address",
        )
    })?;
    let filetype = spec
        .mime
        .clone()
        .filter(|m| !m.is_empty())
        .unwrap_or_else(|| "application/octet-stream".into());
    let metadata = format!(
        "filename {},filetype {},ticket {}",
        b64(&ticket.name),
        b64(&filetype),
        b64(&ticket.ticket)
    );
    loop {
        let req = http
            .post(endpoint.clone())
            .header("Tus-Resumable", TUS)
            .header("Upload-Length", spec.size.to_string())
            .header("Upload-Metadata", metadata.clone())
            .header(CONTENT_LENGTH, "0");
        let resp = tokio::select! {
            stop = interrupted(ctrl) => return Err(stop),
            r = req.send() => r,
        };
        let why = match resp {
            Err(e) => e.without_url().to_string(),
            Ok(resp) => {
                let status = resp.status();
                if status == StatusCode::CREATED {
                    let loc = resp
                        .headers()
                        .get(LOCATION)
                        .and_then(|v| v.to_str().ok())
                        .ok_or_else(|| transfer_err("upload_failed", "no Location header"))?;
                    let url = endpoint
                        .join(loc)
                        .map_err(|_| transfer_err("upload_failed", "bad Location header"))?;
                    return Ok(url.to_string());
                }
                if let Some(stop) = refusal(status, &resp.bytes().await.unwrap_or_default()) {
                    return Err(stop);
                }
                format!("HTTP {}", status.as_u16())
            }
        };
        retrier.wait(rep, ctrl, &why).await?;
    }
}

async fn patch(
    http: &reqwest::Client,
    url: &str,
    spec: &UploadSpec,
    offset: u64,
    len: u64,
    rep: &Arc<dyn Reporter>,
    ctrl: &mut Ctrl,
) -> Result<u64, PatchErr> {
    let mut file = tokio::fs::File::open(&spec.path)
        .await
        .map_err(|e| PatchErr::Stop(Stop::Failed(crate::Error::io(&spec.path, e))))?;
    file.seek(std::io::SeekFrom::Start(offset))
        .await
        .map_err(|e| PatchErr::Stop(Stop::Failed(crate::Error::io(&spec.path, e))))?;
    let sent = Arc::new(AtomicU64::new(0));
    let (sent2, rep2, size) = (sent.clone(), rep.clone(), spec.size);
    let stream = ReaderStream::with_capacity(file.take(len), BODY_BLOCK).map(move |r| {
        if let Ok(b) = &r {
            let n = sent2.fetch_add(b.len() as u64, Ordering::Relaxed) + b.len() as u64;
            rep2.progress(offset + n, size);
        }
        r
    });
    // An explicit Content-Length keeps the request a plain, length-delimited PATCH.
    let req = http
        .patch(url)
        .header("Tus-Resumable", TUS)
        .header(CONTENT_TYPE, "application/offset+octet-stream")
        .header("Upload-Offset", offset.to_string())
        .header(CONTENT_LENGTH, len.to_string())
        .body(Body::wrap_stream(stream));
    let resp = tokio::select! {
        stop = interrupted(ctrl) => return Err(PatchErr::Stop(stop)),
        r = req.send() => r,
    };
    let resp = resp.map_err(|e| PatchErr::Retry(e.without_url().to_string()))?;
    let status = resp.status();
    if status.is_success() {
        return header_u64(&resp, "upload-offset")
            .ok_or_else(|| PatchErr::Retry("the server sent no Upload-Offset".into()));
    }
    if status == StatusCode::NOT_FOUND || status == StatusCode::GONE {
        return Err(PatchErr::Stop(transfer_err(
            "upload_lost",
            "the server no longer knows this upload",
        )));
    }
    if let Some(stop) = refusal(status, &resp.bytes().await.unwrap_or_default()) {
        return Err(PatchErr::Stop(stop));
    }
    // 409 (offset mismatch), 423 (locked), 429, 5xx: ask for the offset and go on.
    Err(PatchErr::Retry(format!("HTTP {}", status.as_u16())))
}

/// Uploads the file, creating the tus upload from a fresh ticket or continuing a recorded one.
pub async fn upload(
    api: &ApiClient,
    http: &reqwest::Client,
    spec: &UploadSpec,
    store: &UploadStore,
    delays: &[Duration],
    rep: &Arc<dyn Reporter>,
    ctrl: &mut Ctrl,
) -> Result<UploadOutcome, Stop> {
    let key = fingerprint(&spec.event, &spec.path, spec.size, spec.mtime_secs);
    let mut url: Option<String> = None;
    let result = upload_inner(api, http, spec, store, &key, delays, rep, ctrl, &mut url).await;
    match &result {
        Ok(_) => store.remove(&key),
        Err(Stop::Cancelled) => {
            store.remove(&key);
            if let Some(u) = &url {
                // Terminate the upload on the host (tus termination extension), best effort.
                let _ = http
                    .delete(u)
                    .header("Tus-Resumable", TUS)
                    .timeout(Duration::from_secs(5))
                    .send()
                    .await;
            }
        }
        Err(Stop::Paused) => {}
        Err(Stop::Failed(e)) => {
            // The final refusals and a lost upload cannot be continued; a network failure can.
            if !matches!(e.code(), Some("network")) {
                store.remove(&key);
            }
        }
    }
    result
}

#[allow(clippy::too_many_arguments)]
async fn upload_inner(
    api: &ApiClient,
    http: &reqwest::Client,
    spec: &UploadSpec,
    store: &UploadStore,
    key: &str,
    delays: &[Duration],
    rep: &Arc<dyn Reporter>,
    ctrl: &mut Ctrl,
    url_out: &mut Option<String>,
) -> Result<UploadOutcome, Stop> {
    if spec.size == 0 {
        return Err(transfer_err(
            "empty_file",
            "an empty file cannot be uploaded",
        ));
    }
    let mut retrier = Retrier { delays, idx: 0 };
    rep.phase(Phase::Preparing);

    let mut resumed = false;
    let mut found: Option<(String, u64, u64)> = None; // url, chunk size, offset
    if let Some(rec) = store.get(key).filter(|r| r.size == spec.size) {
        match probe(http, &rec.upload_url, &mut retrier, rep.as_ref(), ctrl).await? {
            Probe::Offset(o) => {
                resumed = o > 0;
                found = Some((rec.upload_url, rec.chunk_size.max(1), o));
            }
            Probe::Gone => store.remove(key),
        }
    }
    let (url, chunk, mut offset) = match found {
        Some(f) => f,
        None => {
            let ticket = tokio::select! {
                stop = interrupted(ctrl) => return Err(stop),
                t = api.upload_ticket(&spec.event, &spec.name, spec.size, spec.mime.as_deref()) => t?,
            };
            let chunk = if ticket.chunk_size == 0 {
                DEFAULT_CHUNK
            } else {
                ticket.chunk_size
            };
            let url = create(http, &ticket, spec, &mut retrier, rep.as_ref(), ctrl).await?;
            store.put(
                key,
                Record {
                    upload_url: url.clone(),
                    chunk_size: chunk,
                    size: spec.size,
                    name: spec.name.clone(),
                    saved_at: now_secs(),
                },
            );
            retrier.reset();
            (url, chunk, 0)
        }
    };
    *url_out = Some(url.clone());

    while offset < spec.size {
        let len = chunk.min(spec.size - offset);
        rep.phase(Phase::Active);
        rep.progress(offset, spec.size);
        match patch(http, &url, spec, offset, len, rep, ctrl).await {
            Ok(new) if new > offset => {
                offset = new; // the server's word is final, also when it is ahead of what we sent
                retrier.reset();
            }
            Ok(_) => {
                retrier
                    .wait(rep.as_ref(), ctrl, "the server made no progress")
                    .await?;
                offset = resync(http, &url, &mut retrier, rep.as_ref(), ctrl).await?;
                resumed = true;
            }
            Err(PatchErr::Stop(s)) => return Err(s),
            Err(PatchErr::Retry(why)) => {
                retrier.wait(rep.as_ref(), ctrl, &why).await?;
                offset = resync(http, &url, &mut retrier, rep.as_ref(), ctrl).await?;
                resumed = true;
            }
        }
    }
    rep.progress(spec.size, spec.size);
    Ok(UploadOutcome { resumed })
}

async fn resync(
    http: &reqwest::Client,
    url: &str,
    retrier: &mut Retrier<'_>,
    rep: &dyn Reporter,
    ctrl: &mut Ctrl,
) -> Result<u64, Stop> {
    match probe(http, url, retrier, rep, ctrl).await? {
        Probe::Offset(o) => Ok(o),
        Probe::Gone => Err(transfer_err(
            "upload_lost",
            "the server no longer knows this upload",
        )),
    }
}

#[cfg(test)]
mod tests;
