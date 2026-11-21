//! Archive extraction for the UT2004 pilot.
//!
//! Two shapes matter:
//! - the retail DVD is an ISO9660/Joliet image; only the payload folders are
//!   copied, `System/` comes from the OldUnreal patch,
//! - the patch is a zip (Windows) or tar.bz2 (Linux); its `System/` tree is
//!   extracted over the target.
//!
//! Every entry passes through [`safe_join`], so a crafted archive cannot
//! write outside the install folder.

use std::fs::File;
use std::io::{BufReader, Read, Write};
use std::path::{Component, Path, PathBuf};

use libmkiso::iso9660::{IsoReader, Limits};

#[derive(Debug, thiserror::Error)]
pub enum ArchiveError {
    #[error("could not open {path}: {source}")]
    Open {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("could not read archive: {0}")]
    Read(String),
    #[error("unsafe archive entry {entry}")]
    UnsafeEntry { entry: String },
    #[error("could not write {path}: {source}")]
    Write {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("the ISO contains no files")]
    EmptyIso,
    #[error("the ISO has an unsupported layout: {0}")]
    IsoLayout(String),
}

/// Join one archive entry to the target, rejecting absolute paths and `..`.
pub fn safe_join(target: &Path, entry: &str) -> Result<PathBuf, ArchiveError> {
    let normalized = entry.replace('\\', "/");
    let relative = Path::new(normalized.trim_start_matches('/'));
    let mut out = PathBuf::new();

    for component in relative.components() {
        match component {
            Component::Normal(name) => out.push(name),
            Component::CurDir => {}
            Component::ParentDir | Component::RootDir | Component::Prefix(_) => {
                return Err(ArchiveError::UnsafeEntry {
                    entry: entry.to_string(),
                });
            }
        }
    }

    if out.as_os_str().is_empty() {
        return Err(ArchiveError::UnsafeEntry {
            entry: entry.to_string(),
        });
    }

    Ok(target.join(out))
}

fn create_parent(path: &Path) -> Result<(), ArchiveError> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent).map_err(|source| ArchiveError::Write {
            path: parent.to_path_buf(),
            source,
        })?;
    }

    Ok(())
}

/// Extract a zip over `target`. Existing files are replaced atomically enough
/// for a game patch (same-name files from the patch win).
pub fn extract_zip(zip_path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    let file = File::open(zip_path).map_err(|source| ArchiveError::Open {
        path: zip_path.to_path_buf(),
        source,
    })?;
    let mut zip = zip::ZipArchive::new(file).map_err(|error| ArchiveError::Read(error.to_string()))?;
    let mut written = 0;

    for index in 0..zip.len() {
        let mut entry = zip.by_index(index).map_err(|error| ArchiveError::Read(error.to_string()))?;
        let name = entry.name().to_string();

        if entry.is_dir() {
            let dir = safe_join(target, &name)?;
            std::fs::create_dir_all(&dir).map_err(|source| ArchiveError::Write { path: dir, source })?;
            continue;
        }

        let out = safe_join(target, &name)?;
        create_parent(&out)?;
        let mut file = File::create(&out).map_err(|source| ArchiveError::Write {
            path: out.clone(),
            source,
        })?;
        std::io::copy(&mut entry, &mut file).map_err(|error| ArchiveError::Read(error.to_string()))?;
        written += 1;
    }

    Ok(written)
}

/// Extract a tar.bz2 over `target` (the Linux patch).
pub fn extract_tar_bz2(path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    let file = File::open(path).map_err(|source| ArchiveError::Open {
        path: path.to_path_buf(),
        source,
    })?;
    let decoder = bzip2::read::BzDecoder::new(BufReader::new(file));
    let mut archive = tar::Archive::new(decoder);
    let mut written = 0;

    for entry in archive.entries().map_err(|error| ArchiveError::Read(error.to_string()))? {
        let mut entry = entry.map_err(|error| ArchiveError::Read(error.to_string()))?;
        let path_in_archive = entry
            .path()
            .map_err(|error| ArchiveError::Read(error.to_string()))?
            .to_string_lossy()
            .to_string();
        let out = safe_join(target, &path_in_archive)?;
        let kind = entry.header().entry_type();

        if kind.is_dir() {
            std::fs::create_dir_all(&out).map_err(|source| ArchiveError::Write { path: out, source })?;
            continue;
        }

        if !kind.is_file() {
            // Hard links/symlinks are not part of the UT2004 patch; skip them
            // rather than following a link out of the target.
            continue;
        }

        create_parent(&out)?;
        let mut file = File::create(&out).map_err(|source| ArchiveError::Write {
            path: out.clone(),
            source,
        })?;
        std::io::copy(&mut entry, &mut file).map_err(|error| ArchiveError::Read(error.to_string()))?;
        written += 1;
    }

    Ok(written)
}

/// Extract the game payload from an ISO9660/Joliet image.
///
/// The retail disc's `System/` tree is skipped (the patch ships the complete
/// 64-bit System tree). If the image wraps everything in one folder that has a
/// `System/` child, that wrapper is stripped so the patch lands in
/// `<target>/System` as the recipe expects.
pub fn extract_iso(iso_path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    let file = File::open(iso_path).map_err(|source| ArchiveError::Open {
        path: iso_path.to_path_buf(),
        source,
    })?;
    let mut reader = IsoReader::open(file, Limits::default()).map_err(|error| ArchiveError::Read(error.to_string()))?;

    let entries: Vec<(usize, String, bool)> = reader
        .entries()
        .iter()
        .enumerate()
        .map(|(index, entry)| (index, entry.name.replace('\\', "/"), entry.directory))
        .collect();

    if entries.is_empty() {
        return Err(ArchiveError::EmptyIso);
    }

    let strip = wrapper_prefix(&entries);
    let mut written = 0;

    for (index, name, directory) in entries {
        let relative = strip_prefix(&name, strip.as_deref());

        if relative.is_empty() {
            continue;
        }

        // The patch supplies the whole System tree.
        if relative.split('/').next().is_some_and(|part| part.eq_ignore_ascii_case("System")) {
            continue;
        }

        let out = safe_join(target, &relative)?;

        if directory {
            std::fs::create_dir_all(&out).map_err(|source| ArchiveError::Write { path: out, source })?;
            continue;
        }

        create_parent(&out)?;
        let mut file = File::create(&out).map_err(|source| ArchiveError::Write {
            path: out.clone(),
            source,
        })?;
        reader
            .extract(index, &mut file)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;
        written += 1;
    }

    if written == 0 {
        return Err(ArchiveError::IsoLayout("no payload files outside System/".into()));
    }

    Ok(written)
}

/// Detect a single wrapper folder around a `System/` tree. `UT2004/System/...`
/// is the common authoring shape; a disc with top-level `System/` has none.
fn wrapper_prefix(entries: &[(usize, String, bool)]) -> Option<String> {
    let has_root_system = entries.iter().any(|(_, name, _)| {
        name.split('/').next().is_some_and(|part| part.eq_ignore_ascii_case("System"))
    });

    if has_root_system {
        return None;
    }

    let mut roots = entries
        .iter()
        .filter_map(|(_, name, _)| name.split('/').next())
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>();
    roots.sort_unstable();
    roots.dedup();

    if roots.len() != 1 {
        return None;
    }

    let root = roots[0];
    let has_wrapped_system = entries.iter().any(|(_, name, _)| {
        name.strip_prefix(root)
            .and_then(|rest| rest.strip_prefix('/'))
            .and_then(|rest| rest.split('/').next())
            .is_some_and(|part| part.eq_ignore_ascii_case("System"))
    });

    has_wrapped_system.then(|| root.to_string())
}

fn strip_prefix<'a>(name: &'a str, prefix: Option<&str>) -> String {
    match prefix.and_then(|prefix| name.strip_prefix(prefix).and_then(|rest| rest.strip_prefix('/'))) {
        Some(rest) => rest.to_string(),
        None => name.to_string(),
    }
}

/// A tiny convenience for streams used by the installer.
pub fn copy_stream(mut reader: impl Read, mut writer: impl Write) -> Result<u64, ArchiveError> {
    std::io::copy(&mut reader, &mut writer).map_err(|error| ArchiveError::Read(error.to_string()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn safe_join_rejects_absolute_and_parent_entries() {
        let target = Path::new("/tmp/game");
        assert_eq!(safe_join(target, "Maps/DM-Rankin.ut2").unwrap(), target.join("Maps/DM-Rankin.ut2"));
        assert_eq!(safe_join(target, "/Maps/x").unwrap(), target.join("Maps/x"));
        assert!(safe_join(target, "../etc/passwd").is_err());
        assert!(safe_join(target, "Maps/../../etc/passwd").is_err());
        assert!(safe_join(target, "C:\\Windows\\x").is_ok());
    }

    #[test]
    fn zip_extraction_writes_files_and_rejects_traversal() {
        let dir = tempfile::tempdir().unwrap();
        let zip_path = dir.path().join("patch.zip");
        let target = dir.path().join("game");
        std::fs::create_dir_all(&target).unwrap();

        {
            let file = File::create(&zip_path).unwrap();
            let mut zip = zip::ZipWriter::new(file);
            let options = zip::write::SimpleFileOptions::default();
            zip.start_file("System/Build.ini", options).unwrap();
            zip.write_all(b"Label=v3374\n").unwrap();
            zip.start_file("Textures/Test.utx", options).unwrap();
            zip.write_all(b"data").unwrap();
            zip.finish().unwrap();
        }

        assert_eq!(extract_zip(&zip_path, &target).unwrap(), 2);
        assert_eq!(std::fs::read(target.join("System/Build.ini")).unwrap(), b"Label=v3374\n");
    }

    #[test]
    fn wrapper_detection_strips_one_ut2004_folder() {
        let entries = vec![
            (0, "UT2004/System/UT2004.exe".to_string(), false),
            (1, "UT2004/Maps/DM-Rankin.ut2".to_string(), false),
        ];
        assert_eq!(wrapper_prefix(&entries), Some("UT2004".to_string()));

        let flat = vec![
            (0, "System/UT2004.exe".to_string(), false),
            (1, "Maps/DM-Rankin.ut2".to_string(), false),
        ];
        assert_eq!(wrapper_prefix(&flat), None);
    }
}
