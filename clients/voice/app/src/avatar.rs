//! Avatars for the UI: `get_avatar` turns the avatar path a DTO carries into a `data:` URI. The
//! bytes come from the server (bearer token, see `voice_core::api::avatar`) and are kept on disk
//! in the app's cache directory, keyed by user id and content hash, so a changed avatar is a new
//! entry and a known one never touches the network again. The cache is bounded (file count and
//! total size, oldest dropped first). Only a PNG, JPEG or WebP of at most 512 KB is ever returned.

use std::path::{Path, PathBuf};
use std::sync::OnceLock;
use std::time::SystemTime;

use voice_core::api::{data_uri, sniff_mime, ApiClient, AvatarRef, MAX_AVATAR_BYTES};
use voice_core::Result;

/// Most files kept in the cache.
const MAX_FILES: usize = 400;
/// Most bytes kept in the cache.
const MAX_TOTAL_BYTES: u64 = 16 * 1024 * 1024;

/// A bounded on-disk cache of avatar bytes.
#[derive(Debug, Clone)]
pub struct AvatarCache {
    dir: PathBuf,
    max_files: usize,
    max_bytes: u64,
}

impl AvatarCache {
    pub fn new(dir: impl Into<PathBuf>) -> Self {
        Self::with_limits(dir, MAX_FILES, MAX_TOTAL_BYTES)
    }

    pub fn with_limits(dir: impl Into<PathBuf>, max_files: usize, max_bytes: u64) -> Self {
        Self {
            dir: dir.into(),
            max_files,
            max_bytes,
        }
    }

    /// The process-wide cache in the platform cache directory (or the temp directory when the
    /// platform has none).
    pub fn shared() -> &'static AvatarCache {
        static CACHE: OnceLock<AvatarCache> = OnceLock::new();
        CACHE.get_or_init(|| {
            let base = directories::ProjectDirs::from("", "", "lanomat-voice")
                .map(|d| d.cache_dir().to_path_buf())
                .unwrap_or_else(|| std::env::temp_dir().join("lanomat-voice-cache"));
            AvatarCache::new(base.join("avatars"))
        })
    }

    fn file(&self, avatar: &AvatarRef) -> PathBuf {
        // Both parts were validated by `AvatarRef::parse` (digits, lower-case hex): no escapes.
        self.dir
            .join(format!("{}-{}.img", avatar.user_id, avatar.hash))
    }

    /// The cached bytes, if there are any and they are still a valid avatar (a damaged file is
    /// removed and counts as a miss).
    pub fn get(&self, avatar: &AvatarRef) -> Option<Vec<u8>> {
        let file = self.file(avatar);
        let bytes = std::fs::read(&file).ok()?;
        if bytes.len() > MAX_AVATAR_BYTES || sniff_mime(&bytes).is_none() {
            let _ = std::fs::remove_file(&file);
            return None;
        }
        Some(bytes)
    }

    /// Stores the bytes (best effort: a full disk only means no caching) and prunes the cache.
    pub fn put(&self, avatar: &AvatarRef, bytes: &[u8]) {
        if bytes.len() > MAX_AVATAR_BYTES || sniff_mime(bytes).is_none() {
            return;
        }
        if std::fs::create_dir_all(&self.dir).is_err() {
            return;
        }
        // Remove older versions of the same person's avatar: they can never be asked for again.
        self.remove_other_versions(avatar);
        let file = self.file(avatar);
        let tmp = file.with_extension("tmp");
        if std::fs::write(&tmp, bytes).is_ok() && std::fs::rename(&tmp, &file).is_err() {
            let _ = std::fs::remove_file(&tmp);
        }
        self.prune();
    }

    fn remove_other_versions(&self, avatar: &AvatarRef) {
        let prefix = format!("{}-", avatar.user_id);
        let keep = self.file(avatar);
        let Ok(entries) = std::fs::read_dir(&self.dir) else {
            return;
        };
        for entry in entries.flatten() {
            let path = entry.path();
            let is_same_user = path
                .file_name()
                .and_then(|n| n.to_str())
                .is_some_and(|n| n.starts_with(&prefix) && n.ends_with(".img"));
            if is_same_user && path != keep {
                let _ = std::fs::remove_file(path);
            }
        }
    }

    /// Drops the oldest files until the count and the total size are within the limits.
    fn prune(&self) {
        let Ok(entries) = std::fs::read_dir(&self.dir) else {
            return;
        };
        let mut files: Vec<(SystemTime, u64, PathBuf)> = entries
            .flatten()
            .filter(|e| e.path().extension().is_some_and(|x| x == "img"))
            .filter_map(|e| {
                let meta = e.metadata().ok()?;
                Some((meta.modified().ok()?, meta.len(), e.path()))
            })
            .collect();
        files.sort_by_key(|(modified, _, _)| *modified);
        let mut total: u64 = files.iter().map(|(_, len, _)| len).sum();
        let mut count = files.len();
        for (_, len, path) in files {
            if count <= self.max_files && total <= self.max_bytes {
                break;
            }
            if std::fs::remove_file(&path).is_ok() {
                count -= 1;
                total -= len;
            }
        }
    }

    pub fn dir(&self) -> &Path {
        &self.dir
    }
}

/// The avatar behind a DTO path as a `data:` URI: from the cache, else from the server (and then
/// cached). A path that is not an avatar route is an `Err(Usage)` and never requested.
pub async fn avatar_data_uri(
    api: &ApiClient,
    cache: &AvatarCache,
    path: &str,
) -> Result<Option<String>> {
    let avatar = AvatarRef::parse(path)?;
    if let Some(bytes) = cache.get(&avatar) {
        return Ok(data_uri(&bytes));
    }
    let bytes = api.avatar(&avatar).await?;
    cache.put(&avatar, &bytes);
    Ok(data_uri(&bytes))
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    const PNG: &[u8] = b"\x89PNG\r\n\x1a\n\0\0\0\rIHDR";
    const PATH: &str = "/api/voice-client/v1/avatars/12/0123456789abcdef";

    fn avatar(id: u64, hash: &str) -> AvatarRef {
        AvatarRef::parse(&format!("/avatars/{id}/{hash}")).unwrap()
    }

    #[test]
    fn stores_and_returns_valid_images_only() {
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::new(dir.path());
        let a = avatar(1, "aaaaaaaaaaaaaaaa");

        assert!(cache.get(&a).is_none());
        cache.put(&a, b"<html>nope</html>");
        assert!(cache.get(&a).is_none(), "non-images are never stored");
        cache.put(&a, PNG);
        assert_eq!(cache.get(&a).unwrap(), PNG);
    }

    #[test]
    fn a_damaged_cache_file_is_dropped() {
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::new(dir.path());
        let a = avatar(1, "aaaaaaaaaaaaaaaa");
        cache.put(&a, PNG);
        std::fs::write(cache.file(&a), b"garbage").unwrap();

        assert!(cache.get(&a).is_none());
        assert!(!cache.file(&a).exists());
    }

    #[test]
    fn a_new_hash_replaces_the_old_version_of_the_same_user() {
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::new(dir.path());
        cache.put(&avatar(1, "aaaaaaaaaaaaaaaa"), PNG);
        cache.put(&avatar(2, "bbbbbbbbbbbbbbbb"), PNG);
        cache.put(&avatar(1, "cccccccccccccccc"), PNG);

        assert!(cache.get(&avatar(1, "aaaaaaaaaaaaaaaa")).is_none());
        assert!(cache.get(&avatar(1, "cccccccccccccccc")).is_some());
        assert!(cache.get(&avatar(2, "bbbbbbbbbbbbbbbb")).is_some());
    }

    #[test]
    fn stays_within_the_file_count_and_size_bounds() {
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::with_limits(dir.path(), 3, 1_000_000);
        for id in 1..=6u64 {
            cache.put(&avatar(id, "aaaaaaaaaaaaaaaa"), PNG);
            std::thread::sleep(std::time::Duration::from_millis(15));
        }
        let count = std::fs::read_dir(dir.path()).unwrap().count();
        assert_eq!(count, 3);
        assert!(cache.get(&avatar(6, "aaaaaaaaaaaaaaaa")).is_some());
        assert!(cache.get(&avatar(1, "aaaaaaaaaaaaaaaa")).is_none());

        let small = AvatarCache::with_limits(dir.path().join("b"), 100, PNG.len() as u64 * 2);
        for id in 1..=5u64 {
            small.put(&avatar(id, "aaaaaaaaaaaaaaaa"), PNG);
            std::thread::sleep(std::time::Duration::from_millis(15));
        }
        let total: u64 = std::fs::read_dir(small.dir())
            .unwrap()
            .flatten()
            .map(|e| e.metadata().unwrap().len())
            .sum();
        assert!(total <= PNG.len() as u64 * 2);
    }

    #[tokio::test]
    async fn fetches_once_then_serves_from_the_cache() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(PATH))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(PNG))
            .expect(1)
            .mount(&server)
            .await;
        let api = ApiClient::new(&server.uri()).unwrap().with_token("lvc_tok");
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::new(dir.path());

        let first = avatar_data_uri(&api, &cache, PATH).await.unwrap().unwrap();
        let second = avatar_data_uri(&api, &cache, PATH).await.unwrap().unwrap();

        assert!(first.starts_with("data:image/png;base64,"));
        assert_eq!(first, second);
        // The mock's `expect(1)` is verified when the server drops: a second request would fail it.
    }

    #[tokio::test]
    async fn never_requests_something_that_is_not_an_avatar_path() {
        let server = MockServer::start().await;
        Mock::given(wiremock::matchers::any())
            .respond_with(ResponseTemplate::new(200).set_body_bytes(PNG))
            .expect(0)
            .mount(&server)
            .await;
        let api = ApiClient::new(&server.uri()).unwrap().with_token("lvc_tok");
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::new(dir.path());

        for bad in ["/api/voice-client/v1/me", "https://x.test/a.png", ""] {
            assert!(avatar_data_uri(&api, &cache, bad).await.is_err());
        }
    }

    #[tokio::test]
    async fn a_server_error_is_an_error_and_caches_nothing() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .respond_with(ResponseTemplate::new(404))
            .mount(&server)
            .await;
        let api = ApiClient::new(&server.uri()).unwrap().with_token("lvc_tok");
        let dir = tempfile::tempdir().unwrap();
        let cache = AvatarCache::new(dir.path());

        assert!(avatar_data_uri(&api, &cache, PATH).await.is_err());
        assert!(cache.get(&avatar(12, "0123456789abcdef")).is_none());
    }
}
