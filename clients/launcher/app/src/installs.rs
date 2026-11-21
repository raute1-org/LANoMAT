//! The local install ledger (`installs.json`) next to the launcher config.
//!
//! One record per recipe slug, newest last so the UI's "last record" lookup can
//! simply take the tail. A corrupt file falls back to an empty ledger instead of
//! blocking the launcher; a write is a temp file plus rename so a crash never
//! leaves half a JSON document behind.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

pub const FILE_NAME: &str = "installs.json";
pub const MAX_RECORDS: usize = 20;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct InstallRecord {
    pub slug: String,
    pub target_dir: String,
    #[serde(default)]
    pub version: Option<String>,
    #[serde(default)]
    pub verified: Vec<String>,
    /// The OS-specific start command from the recipe/plan. Older records have
    /// none and fall back to the UT2004 `System/` launcher.
    #[serde(default)]
    pub launch_command: Option<String>,
}

pub fn path(dir: &Path) -> PathBuf {
    dir.join(FILE_NAME)
}

/// The ledger, oldest first. A missing or corrupt file is an empty ledger.
pub fn load(dir: &Path) -> Vec<InstallRecord> {
    std::fs::read_to_string(path(dir))
        .ok()
        .and_then(|raw| serde_json::from_str(&raw).ok())
        .unwrap_or_default()
}

/// The newest record for a slug, if any.
pub fn find(dir: &Path, slug: &str) -> Option<InstallRecord> {
    load(dir)
        .into_iter()
        .rev()
        .find(|record| record.slug == slug)
}

/// Record a finished install, replacing an older record for the same slug.
pub fn save(dir: &Path, record: &InstallRecord) -> std::io::Result<()> {
    std::fs::create_dir_all(dir)?;

    let mut records = load(dir);
    records.retain(|entry| entry.slug != record.slug);
    records.push(record.clone());

    if records.len() > MAX_RECORDS {
        let overflow = records.len() - MAX_RECORDS;
        records.drain(..overflow);
    }
    let raw = serde_json::to_vec_pretty(&records).map_err(std::io::Error::other)?;
    let temp = dir.join("installs.json.tmp");

    std::fs::write(&temp, raw)?;
    std::fs::rename(&temp, path(dir))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn record(slug: &str, dir: &str) -> InstallRecord {
        InstallRecord {
            slug: slug.to_string(),
            target_dir: dir.to_string(),
            version: Some("3374".into()),
            verified: vec!["System/Build.ini".into()],
            launch_command: None,
        }
    }

    #[test]
    fn round_trips_records_and_keeps_the_newest_last() {
        let dir = tempfile::tempdir().unwrap();

        save(dir.path(), &record("ut2004", "/games/ut2004")).unwrap();
        save(dir.path(), &record("other", "/games/other")).unwrap();

        let records = load(dir.path());
        assert_eq!(records.len(), 2);
        assert_eq!(records.last().unwrap().slug, "other");
        assert_eq!(
            find(dir.path(), "ut2004").unwrap().target_dir,
            "/games/ut2004"
        );
        assert_eq!(records[0].version.as_deref(), Some("3374"));
    }

    #[test]
    fn a_reinstall_replaces_the_older_record() {
        let dir = tempfile::tempdir().unwrap();

        save(dir.path(), &record("ut2004", "/games/old")).unwrap();
        save(dir.path(), &record("ut2004", "/games/new")).unwrap();

        let records = load(dir.path());
        assert_eq!(records.len(), 1);
        assert_eq!(records[0].target_dir, "/games/new");
    }

    #[test]
    fn a_corrupt_ledger_is_not_fatal() {
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(path(dir.path()), b"{ not json").unwrap();

        assert!(load(dir.path()).is_empty());
        assert!(find(dir.path(), "ut2004").is_none());

        save(dir.path(), &record("ut2004", "/games/ut2004")).unwrap();
        assert_eq!(load(dir.path()).len(), 1);
    }

    #[test]
    fn the_ledger_is_capped() {
        let dir = tempfile::tempdir().unwrap();

        for index in 0..(MAX_RECORDS + 5) {
            save(dir.path(), &record(&format!("game-{index}"), "/games")).unwrap();
        }

        let records = load(dir.path());
        assert_eq!(records.len(), MAX_RECORDS);
        assert_eq!(
            records.last().unwrap().slug,
            format!("game-{}", MAX_RECORDS + 4)
        );
    }
}
