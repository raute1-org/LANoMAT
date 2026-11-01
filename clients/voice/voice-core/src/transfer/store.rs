//! The fingerprint file of uploads: which local file (event, path, size, modification time) is
//! being uploaded to which tus upload URL, so an interrupted upload resumes after an app restart.
//! It stores neither the ticket nor any token: the ticket is single use, and the upload URL alone
//! only lets one continue the same upload.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::sync::Mutex;

use serde::{Deserialize, Serialize};

/// Records older than this are forgotten (the server drops stale uploads too).
const MAX_AGE_SECS: u64 = 7 * 24 * 3600;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Record {
    pub upload_url: String,
    pub chunk_size: u64,
    pub size: u64,
    pub name: String,
    /// Unix seconds when the record was written.
    pub saved_at: u64,
}

/// `event | canonical path | size | mtime`: changing the file changes the key.
pub fn fingerprint(event: &str, canonical_path: &Path, size: u64, mtime_secs: u64) -> String {
    format!("{event}|{}|{size}|{mtime_secs}", canonical_path.display())
}

pub fn now_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

pub struct UploadStore {
    path: PathBuf,
    records: Mutex<BTreeMap<String, Record>>,
}

impl UploadStore {
    /// Loads the file; a missing or broken file is an empty store.
    pub fn open(path: PathBuf) -> Self {
        let mut records: BTreeMap<String, Record> = std::fs::read(&path)
            .ok()
            .and_then(|b| serde_json::from_slice(&b).ok())
            .unwrap_or_default();
        let now = now_secs();
        records.retain(|_, r| now.saturating_sub(r.saved_at) < MAX_AGE_SECS);
        Self {
            path,
            records: Mutex::new(records),
        }
    }

    fn guard(&self) -> std::sync::MutexGuard<'_, BTreeMap<String, Record>> {
        self.records.lock().unwrap_or_else(|p| p.into_inner())
    }

    pub fn get(&self, key: &str) -> Option<Record> {
        self.guard().get(key).cloned()
    }

    pub fn put(&self, key: &str, record: Record) {
        let mut g = self.guard();
        g.insert(key.to_owned(), record);
        self.persist(&g);
    }

    pub fn remove(&self, key: &str) {
        let mut g = self.guard();
        if g.remove(key).is_some() {
            self.persist(&g);
        }
    }

    pub fn len(&self) -> usize {
        self.guard().len()
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// Best effort: a failed write only costs the resume after a restart.
    fn persist(&self, g: &BTreeMap<String, Record>) {
        let Ok(json) = serde_json::to_vec_pretty(g) else {
            return;
        };
        if let Some(dir) = self.path.parent() {
            let _ = std::fs::create_dir_all(dir);
        }
        let tmp = self.path.with_extension("json.tmp");
        if write_private(&tmp, &json).is_ok() {
            let _ = std::fs::rename(&tmp, &self.path);
        }
    }
}

#[cfg(unix)]
fn write_private(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    use std::io::Write;
    use std::os::unix::fs::OpenOptionsExt;
    let mut f = std::fs::OpenOptions::new()
        .write(true)
        .create(true)
        .truncate(true)
        .mode(0o600)
        .open(path)?;
    f.write_all(bytes)
}

/// Windows: no mode bits; the file sits under the per-user profile (`%APPDATA%`), whose default
/// ACL already restricts it to that user.
#[cfg(not(unix))]
fn write_private(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    std::fs::write(path, bytes)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rec(url: &str, at: u64) -> Record {
        Record {
            upload_url: url.into(),
            chunk_size: 1024,
            size: 4096,
            name: "a.bin".into(),
            saved_at: at,
        }
    }

    #[test]
    fn records_survive_a_reopen_and_removal_persists() {
        let dir = tempfile::tempdir().unwrap();
        let p = dir.path().join("uploads.json");
        let s = UploadStore::open(p.clone());
        s.put("k1", rec("http://h/u/1", now_secs()));
        s.put("k2", rec("http://h/u/2", now_secs()));
        let s2 = UploadStore::open(p.clone());
        assert_eq!(s2.get("k1").unwrap().upload_url, "http://h/u/1");
        s2.remove("k1");
        assert!(UploadStore::open(p).get("k1").is_none());
    }

    #[test]
    fn old_and_broken_files_are_dropped_quietly() {
        let dir = tempfile::tempdir().unwrap();
        let p = dir.path().join("uploads.json");
        let s = UploadStore::open(p.clone());
        s.put("old", rec("http://h/u/old", 1));
        s.put("new", rec("http://h/u/new", now_secs()));
        let s2 = UploadStore::open(p.clone());
        assert!(s2.get("old").is_none() && s2.get("new").is_some());
        std::fs::write(&p, b"{ broken").unwrap();
        assert!(UploadStore::open(p).is_empty());
    }

    #[cfg(unix)]
    #[test]
    fn the_file_is_private_and_holds_no_credentials() {
        use std::os::unix::fs::PermissionsExt;
        let dir = tempfile::tempdir().unwrap();
        let p = dir.path().join("uploads.json");
        UploadStore::open(p.clone()).put("k", rec("http://h/u/1", now_secs()));
        assert_eq!(
            std::fs::metadata(&p).unwrap().permissions().mode() & 0o777,
            0o600
        );
        let text = std::fs::read_to_string(&p).unwrap().to_lowercase();
        assert!(!text.contains("ticket") && !text.contains("token"));
    }

    #[test]
    fn the_fingerprint_changes_with_the_file() {
        let p = Path::new("/home/u/a.bin");
        let a = fingerprint("e", p, 10, 100);
        assert_ne!(a, fingerprint("e", p, 11, 100));
        assert_ne!(a, fingerprint("e", p, 10, 101));
        assert_ne!(a, fingerprint("other", p, 10, 100));
    }
}
