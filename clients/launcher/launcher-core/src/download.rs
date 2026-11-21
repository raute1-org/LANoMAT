//! Resume-safe HTTP downloads with SHA-256 verification.
//!
//! The LAN file host speaks HTTP Range; a dropped connection resumes from the
//! `.part` file. The final file only appears after the hash check passes, so a
//! later launcher start can trust it.

use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use reqwest::header::{CONTENT_LENGTH, RANGE};
use sha2::{Digest, Sha256};

#[derive(Debug, Clone)]
pub struct DownloadTask {
    pub url: String,
    pub filename: String,
    pub dest_dir: PathBuf,
    pub sha256: Option<String>,
    pub size_bytes: Option<u64>,
    /// The bearer token for the LANoMAT API download route.
    pub bearer: Option<String>,
}

#[derive(Debug, Clone, Default)]
pub struct DownloadProgress {
    pub filename: String,
    pub received: u64,
    pub total: Option<u64>,
    pub bytes_per_second: u64,
}

#[derive(Debug, thiserror::Error)]
pub enum DownloadError {
    #[error("invalid file name for a download: {0}")]
    InvalidName(String),
    #[error("could not create {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("network error: {0}")]
    Network(String),
    #[error("server answered HTTP {status}")]
    Http { status: u16 },
    #[error("checksum mismatch for {file}: expected {expected}, got {actual}")]
    Checksum {
        file: String,
        expected: String,
        actual: String,
    },
    #[error("download cancelled")]
    Cancelled,
}

/// Cooperative cancellation shared by the UI and the engine.
#[derive(Debug, Clone, Default)]
pub struct CancelToken(Arc<AtomicBool>);

impl CancelToken {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn cancel(&self) {
        self.0.store(true, Ordering::SeqCst);
    }

    pub fn is_cancelled(&self) -> bool {
        self.0.load(Ordering::SeqCst)
    }
}

/// Keep only the last component and strip characters that would be a path on
/// either Windows or Unix.
pub fn sanitize_filename(input: &str) -> Result<String, DownloadError> {
    let name = input.replace('\\', "/");
    let name = name.rsplit('/').next().unwrap_or("");
    let name = name.trim();

    if name.is_empty() || name == "." || name == ".." || name.contains('\0') {
        return Err(DownloadError::InvalidName(input.to_string()));
    }

    Ok(name.to_string())
}

fn sha256_file(path: &Path) -> Result<String, DownloadError> {
    use std::io::Read;
    let mut file = std::fs::File::open(path).map_err(|source| DownloadError::Io {
        path: path.to_path_buf(),
        source,
    })?;
    let mut hash = Sha256::new();
    let mut buffer = [0u8; 64 * 1024];

    loop {
        let read = file.read(&mut buffer).map_err(|source| DownloadError::Io {
            path: path.to_path_buf(),
            source,
        })?;
        if read == 0 {
            break;
        }
        hash.update(&buffer[..read]);
    }

    Ok(format!("{:x}", hash.finalize()))
}

/// Download one file. Returns the final path.
pub async fn download_file<F>(
    task: &DownloadTask,
    cancel: &CancelToken,
    mut on_progress: F,
) -> Result<PathBuf, DownloadError>
where
    F: FnMut(DownloadProgress),
{
    let filename = sanitize_filename(&task.filename)?;
    std::fs::create_dir_all(&task.dest_dir).map_err(|source| DownloadError::Io {
        path: task.dest_dir.clone(),
        source,
    })?;

    let final_path = task.dest_dir.join(&filename);
    let part_path = task.dest_dir.join(format!("{filename}.part"));

    if final_path.is_file() {
        if let Some(expected) = task.sha256.as_deref() {
            if sha256_file(&final_path)?.eq_ignore_ascii_case(expected) {
                on_progress(DownloadProgress {
                    filename: filename.clone(),
                    received: std::fs::metadata(&final_path).map(|m| m.len()).unwrap_or(0),
                    total: task.size_bytes,
                    bytes_per_second: 0,
                });
                return Ok(final_path);
            }
        } else if task.size_bytes.is_none()
            || std::fs::metadata(&final_path).map(|meta| meta.len()).ok() == task.size_bytes
        {
            return Ok(final_path);
        }

        let _ = std::fs::remove_file(&final_path);
    }

    let existing = std::fs::metadata(&part_path).map(|meta| meta.len()).unwrap_or(0);
    let mut request = reqwest::Client::new()
        .get(&task.url)
        .timeout(Duration::from_secs(60 * 30));

    if let Some(token) = task.bearer.as_deref() {
        request = request.bearer_auth(token);
    }

    if existing > 0 {
        request = request.header(RANGE, format!("bytes={existing}-"));
    }

    let response = request.send().await.map_err(|error| DownloadError::Network(error.to_string()))?;

    if !response.status().is_success() {
        return Err(DownloadError::Http {
            status: response.status().as_u16(),
        });
    }

    let total = response
        .content_length()
        .map(|length| length + if response.status().as_u16() == 206 { existing } else { 0 })
        .or(task.size_bytes);
    let append = existing > 0 && response.status().as_u16() == 206;
    let mut file = if append {
        std::fs::OpenOptions::new()
            .append(true)
            .open(&part_path)
            .map_err(|source| DownloadError::Io {
                path: part_path.clone(),
                source,
            })?
    } else {
        std::fs::File::create(&part_path).map_err(|source| DownloadError::Io {
            path: part_path.clone(),
            source,
        })?
    };

    let mut received = if append { existing } else { 0 };
    let started = Instant::now();
    let mut last_report = Instant::now() - Duration::from_secs(1);
    let mut response = response;

    use std::io::Write;
    while let Some(chunk) = response.chunk().await.map_err(|error| DownloadError::Network(error.to_string()))? {
        if cancel.is_cancelled() {
            return Err(DownloadError::Cancelled);
        }

        file.write_all(&chunk).map_err(|source| DownloadError::Io {
            path: part_path.clone(),
            source,
        })?;
        received += chunk.len() as u64;

        if last_report.elapsed() >= Duration::from_millis(200) {
            let elapsed = started.elapsed().as_secs_f64().max(0.001);
            let bytes_per_second = ((received.saturating_sub(existing)) as f64 / elapsed) as u64;
            on_progress(DownloadProgress {
                filename: filename.clone(),
                received,
                total,
                bytes_per_second,
            });
            last_report = Instant::now();
        }
    }

    file.flush().map_err(|source| DownloadError::Io {
        path: part_path.clone(),
        source,
    })?;
    drop(file);

    if let Some(expected) = task.sha256.as_deref() {
        let actual = sha256_file(&part_path)?;
        if !actual.eq_ignore_ascii_case(expected) {
            let _ = std::fs::remove_file(&part_path);
            return Err(DownloadError::Checksum {
                file: filename,
                expected: expected.to_string(),
                actual,
            });
        }
    }

    std::fs::rename(&part_path, &final_path).map_err(|source| DownloadError::Io {
        path: final_path.clone(),
        source,
    })?;

    on_progress(DownloadProgress {
        filename,
        received,
        total,
        bytes_per_second: 0,
    });

    Ok(final_path)
}

/// Download a list sequentially; the caller decides which files are needed.
pub async fn download_many<F>(
    tasks: &[DownloadTask],
    cancel: &CancelToken,
    mut on_progress: F,
) -> Result<Vec<PathBuf>, DownloadError>
where
    F: FnMut(usize, DownloadProgress),
{
    let mut paths = Vec::with_capacity(tasks.len());

    for (index, task) in tasks.iter().enumerate() {
        let path = download_file(task, cancel, |progress| on_progress(index, progress)).await?;
        paths.push(path);
    }

    Ok(paths)
}

/// The expected `Content-Length` header is not trusted for the size cap; this
/// is only a helper for the UI.
pub fn announced_size(response: &reqwest::Response) -> Option<u64> {
    response
        .headers()
        .get(CONTENT_LENGTH)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.parse().ok())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sanitizes_file_names() {
        assert_eq!(sanitize_filename("ut2004.iso").unwrap(), "ut2004.iso");
        assert_eq!(sanitize_filename("C:\\Games\\ut2004.iso").unwrap(), "ut2004.iso");
        assert_eq!(sanitize_filename("/tmp/ut2004.iso").unwrap(), "ut2004.iso");
        assert!(sanitize_filename("..").is_err());
        assert!(sanitize_filename("").is_err());
    }

    #[tokio::test]
    async fn downloads_and_verifies_a_file_with_a_retryable_part() {
        use wiremock::matchers::{method, path};
        use wiremock::{Mock, MockServer, ResponseTemplate};

        let server = MockServer::start().await;
        let body = b"iso-bytes";
        let hash = {
            let mut hasher = Sha256::new();
            hasher.update(body);
            format!("{:x}", hasher.finalize())
        };

        Mock::given(method("GET"))
            .and(path("/file"))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(body))
            .mount(&server)
            .await;

        let dir = tempfile::tempdir().unwrap();
        let task = DownloadTask {
            url: format!("{}/file", server.uri()),
            filename: "ut2004.iso".into(),
            dest_dir: dir.path().to_path_buf(),
            sha256: Some(hash),
            size_bytes: Some(body.len() as u64),
            bearer: None,
        };
        let path = download_file(&task, &CancelToken::new(), |_| {}).await.unwrap();
        assert_eq!(std::fs::read(&path).unwrap(), body);
        assert!(!dir.path().join("ut2004.iso.part").exists());
    }
}
