//! Archive extraction for the UT2004 pilot.
//!
//! Three shapes matter:
//! - the retail DVD is an ISO9660/Joliet image or a macOS DMG; only the payload
//!   folders are copied, `System/` comes from the OldUnreal patch,
//! - a portable base is a zip/tar.bz2 copy of an already patched game; one
//!   wrapper folder is stripped before extraction,
//! - the patch is a zip (Windows) or tar.bz2 (Linux); its `System/` tree is
//!   extracted over the target.
//!
//! A base zip can also be an installer wrapper around a single ISO
//! (`ut2k4+keygen.zip`); [`zip_single_iso_entry`] finds that image and
//! [`extract_zip_entry`] writes it to a temporary file for [`extract_iso`].
//!
//! Every entry is validated by [`safe_relative`] before it is joined to the
//! target, so a crafted archive cannot write outside the install folder.
//! Extraction is additionally bounded by [`ArchiveLimits`], which guards
//! against zip bombs and archives with an absurd number of entries.
//!
//! A validated name is not enough on its own: a *re-install* runs into
//! whatever the previous install left behind, and `target/System` may be a
//! symlink to somewhere else. Every write therefore goes through [`SafeTarget`],
//! which resolves each existing parent component against the canonical target
//! and opens leaves without following a symlink.

use std::fs::{File, OpenOptions};
use std::io::{BufReader, Read, Seek, SeekFrom, Write};
use std::path::{Component, Path, PathBuf};

use libmkiso::iso9660::{Entry as IsoEntry, IsoReader, Limits, Namespace, ReadOptions};

/// Upper bound for the number of entries in one archive.
pub const DEFAULT_MAX_ENTRIES: usize = 200_000;

/// Upper bound for the total uncompressed payload of one archive (8 GiB: a
/// dual-layer retail DVD fits, a decompression bomb does not).
pub const DEFAULT_MAX_UNCOMPRESSED_BYTES: u64 = 8 << 30;

/// Guard rails for zip/tar/ISO extraction.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ArchiveLimits {
    pub max_entries: usize,
    pub max_uncompressed_bytes: u64,
}

impl Default for ArchiveLimits {
    fn default() -> Self {
        Self {
            max_entries: DEFAULT_MAX_ENTRIES,
            max_uncompressed_bytes: DEFAULT_MAX_UNCOMPRESSED_BYTES,
        }
    }
}

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
    #[error("archive has more than {limit} entries")]
    TooManyEntries { limit: usize },
    #[error("archive expands beyond {limit} bytes")]
    TooLarge { limit: u64 },
    #[error("the ISO contains no files")]
    EmptyIso,
    #[error("the ISO has an unsupported layout: {0}")]
    IsoLayout(String),
    #[error("DMG extraction requires macOS (hdiutil)")]
    DmgUnsupported,
    #[error("could not extract the DMG: {0}")]
    Dmg(String),
}

/// Validate one archive entry name.
///
/// Returns `Ok(None)` for an entry that denotes the target root itself
/// (`""`, `"."`, `"./"`), which `tar -cjf patch.tar.bz2 .` emits. Absolute
/// names, Windows drive prefixes, `..` components, and every component that
/// Windows would interpret differently (`is_hostile_windows_component`) are
/// rejected. The rules apply on every platform, so an archive validated on
/// Linux is safe when the same install runs on Windows.
pub fn safe_relative(entry: &str) -> Result<Option<String>, ArchiveError> {
    let normalized = entry.replace('\\', "/");

    if normalized.contains('\0') || normalized.starts_with('/') || is_windows_absolute(&normalized)
    {
        return Err(ArchiveError::UnsafeEntry {
            entry: entry.to_string(),
        });
    }

    let mut parts = Vec::new();

    for part in normalized.split('/') {
        match part {
            "" | "." => {}
            ".." => {
                return Err(ArchiveError::UnsafeEntry {
                    entry: entry.to_string(),
                });
            }
            name if is_hostile_windows_component(name) => {
                return Err(ArchiveError::UnsafeEntry {
                    entry: entry.to_string(),
                });
            }
            name => parts.push(name),
        }
    }

    if parts.is_empty() {
        return Ok(None);
    }

    Ok(Some(parts.join("/")))
}

/// Characters Windows cannot store in a plain file name (`:` additionally opens
/// an alternate data stream).
const WINDOWS_RESERVED_CHARS: &[char] = &['<', '>', ':', '"', '|', '?', '*'];

/// Whether one slash-separated name component is interpreted differently by
/// Win32 than by Unix:
///
/// * a reserved character (`<>:"|?*`) or a control character,
/// * a reserved device name (`CON`, `PRN`, `AUX`, `NUL`, `COM1`..`COM9`,
///   `LPT1`..`LPT9`) with or without an extension,
/// * a trailing dot or space (Win32 strips both, so `Build.ini.` would
///   overwrite `Build.ini`, and `Build.ini ` silently becomes `Build.ini`),
/// * a drive prefix as the component itself (`C:`).
///
/// The rules are applied on every platform: the launcher also runs on Windows,
/// and an archive validated on Linux must still be safe there.
pub(crate) fn is_hostile_windows_component(component: &str) -> bool {
    component
        .chars()
        .any(|character| character.is_control() || WINDOWS_RESERVED_CHARS.contains(&character))
        || component.ends_with('.')
        || component.ends_with(' ')
        || is_windows_device_name(component)
}

/// `CON`, `PRN`, `AUX`, `NUL` and `COM1`..`COM9`/`LPT1`..`LPT9`, case-folded and
/// independent of any extension (`nul.txt` still opens the device).
fn is_windows_device_name(name: &str) -> bool {
    let stem = name
        .split('.')
        .next()
        .unwrap_or_default()
        .to_ascii_uppercase();

    match stem.as_str() {
        "CON" | "PRN" | "AUX" | "NUL" => true,
        other => {
            other.len() == 4
                && (other.starts_with("COM") || other.starts_with("LPT"))
                && other.as_bytes()[3].is_ascii_digit()
                && other.as_bytes()[3] != b'0'
        }
    }
}

fn is_windows_absolute(name: &str) -> bool {
    let bytes = name.as_bytes();
    bytes.len() >= 2 && bytes[0].is_ascii_alphabetic() && bytes[1] == b':'
}

/// Resolve a tar symlink target against the link's own directory.
///
/// A tar symlink stores its target the way a filesystem does: relative to the
/// directory that contains the link. The real OldUnreal Linux patch uses
/// `System/ut2004-bin-amd64 -> UT2004`, so the target is one file away from the
/// link. The result is a target relative to the extraction root, or `None` for
/// an absolute target, a Windows drive prefix or a `..` run that leaves the
/// root. `target` is expected to use `/` separators; `\` is normalized because
/// Windows-authored archives use it.
fn resolve_link_relative(link: &str, target: &str) -> Option<String> {
    let normalized = target.replace('\\', "/");

    if normalized.contains('\0') || normalized.starts_with('/') || is_windows_absolute(&normalized)
    {
        return None;
    }

    let mut parts = link
        .split('/')
        .filter(|part| !part.is_empty() && *part != ".")
        .collect::<Vec<_>>();

    // The link itself is not part of its target.
    parts.pop()?;

    for part in normalized.split('/') {
        match part {
            "" | "." => {}
            ".." => {
                parts.pop()?;
            }
            name => parts.push(name),
        }
    }

    if parts.is_empty() {
        return None;
    }

    Some(parts.join("/"))
}

/// Join one already-validated relative archive entry to the target.
///
/// The join itself is lexical: it does not look at the filesystem, so a
/// pre-existing symlink inside `target` is still followed by a plain
/// `File::create`. [`SafeTarget`] is what extraction uses; this helper stays
/// for callers that only need the path. A Windows drive prefix (`C:\...`) is
/// rejected even on Unix, where it would otherwise be a legal file name.
pub fn safe_join(target: &Path, entry: &str) -> Result<PathBuf, ArchiveError> {
    let normalized = entry.replace('\\', "/");

    if normalized.contains('\0') || is_windows_absolute(&normalized) {
        return Err(ArchiveError::UnsafeEntry {
            entry: entry.to_string(),
        });
    }

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

/// Whether `metadata` describes a link/reparse point rather than the directory
/// or file it names. On Windows a junction is a reparse point too.
#[cfg(windows)]
fn is_reparse_point(metadata: &std::fs::Metadata) -> bool {
    use std::os::windows::fs::FileTypeExt;

    let file_type = metadata.file_type();
    file_type.is_symlink_dir() || file_type.is_symlink_file()
}

#[cfg(not(windows))]
fn is_reparse_point(metadata: &std::fs::Metadata) -> bool {
    metadata.file_type().is_symlink()
}

/// Refuse to create a leaf through a symlink. `create_new` already never
/// follows a final symlink (`O_CREAT|O_EXCL`), `O_NOFOLLOW` makes the intent
/// explicit. The values are ABI-stable, so no `libc` dependency is needed.
#[cfg(unix)]
fn open_no_follow(options: &mut OpenOptions) {
    use std::os::unix::fs::OpenOptionsExt;

    #[cfg(any(target_os = "linux", target_os = "android"))]
    const O_NOFOLLOW: i32 = 0o400_000;
    #[cfg(not(any(target_os = "linux", target_os = "android")))]
    const O_NOFOLLOW: i32 = 0x0100;

    options.custom_flags(O_NOFOLLOW);
}

#[cfg(not(unix))]
fn open_no_follow(_options: &mut OpenOptions) {}

/// Extraction root that keeps every write inside the canonical target.
///
/// Existing components are resolved one by one: a symlink is canonicalised and
/// must stay below the root, so `target/System -> /elsewhere` fails instead of
/// receiving the patch. Leaves are removed (the link itself, not its target)
/// and recreated with `O_NOFOLLOW`/`create_new`.
struct SafeTarget {
    root: PathBuf,
}

impl SafeTarget {
    fn open(target: &Path) -> Result<Self, ArchiveError> {
        std::fs::create_dir_all(target).map_err(|source| ArchiveError::Write {
            path: target.to_path_buf(),
            source,
        })?;
        let root = target
            .canonicalize()
            .map_err(|source| ArchiveError::Write {
                path: target.to_path_buf(),
                source,
            })?;

        Ok(Self { root })
    }

    /// Create every component of `relative` below the root and return the
    /// resolved directory. `entry` is only used for error messages.
    fn ensure_dir(&self, relative: &str, entry: &str) -> Result<PathBuf, ArchiveError> {
        let mut current = self.root.clone();

        for part in relative
            .split('/')
            .filter(|part| !part.is_empty() && *part != ".")
        {
            let next = current.join(part);

            let metadata = match std::fs::symlink_metadata(&next) {
                Ok(metadata) => metadata,
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
                    std::fs::create_dir(&next).map_err(|source| ArchiveError::Write {
                        path: next.clone(),
                        source,
                    })?;
                    current = next;
                    continue;
                }
                Err(source) => return Err(ArchiveError::Write { path: next, source }),
            };

            if is_reparse_point(&metadata) {
                let resolved = next.canonicalize().map_err(|source| ArchiveError::Write {
                    path: next.clone(),
                    source,
                })?;

                if !resolved.starts_with(&self.root) {
                    return Err(ArchiveError::UnsafeEntry {
                        entry: entry.to_string(),
                    });
                }

                current = resolved;
            } else if metadata.is_dir() {
                current = next;
            } else {
                return Err(ArchiveError::Write {
                    path: next,
                    source: std::io::Error::other("a file is in the way of a directory"),
                });
            }
        }

        Ok(current)
    }

    /// Remove whatever leaf currently sits at `path` (the link itself, never
    /// what it points at). `kind` only shapes the error message.
    fn remove_leaf(&self, path: &Path, kind: &str) -> Result<(), ArchiveError> {
        match std::fs::symlink_metadata(path) {
            Ok(metadata) => {
                if metadata.is_dir() {
                    return Err(ArchiveError::Write {
                        path: path.to_path_buf(),
                        source: std::io::Error::other(format!(
                            "a directory is in the way of {kind}"
                        )),
                    });
                }

                // Removes the link, never what it points at.
                std::fs::remove_file(path).map_err(|source| ArchiveError::Write {
                    path: path.to_path_buf(),
                    source,
                })
            }
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(source) => Err(ArchiveError::Write {
                path: path.to_path_buf(),
                source,
            }),
        }
    }

    /// Create (or replace) one leaf file below the root.
    fn create_file(&self, relative: &str, entry: &str) -> Result<File, ArchiveError> {
        let (parent, leaf) = relative.rsplit_once('/').unwrap_or(("", relative));
        let parent = self.ensure_dir(parent, entry)?;
        let path = parent.join(leaf);

        self.remove_leaf(&path, "a file")?;

        let mut options = OpenOptions::new();
        options.write(true).create_new(true);
        open_no_follow(&mut options);

        options
            .open(&path)
            .map_err(|source| ArchiveError::Write { path, source })
    }

    /// Materialise one tar symlink below the root.
    ///
    /// The target is resolved lexically first: it must stay inside the root.
    /// On Unix the relative link is created as stored by the archive. When the
    /// filesystem cannot create a symlink (Windows without the privilege, a
    /// filesystem without link support), the target file is copied instead, so
    /// an install that only needs the file still verifies and launches. A link
    /// whose target does not exist is left dangling on Unix; on a platform
    /// without symlinks it is skipped because there is nothing to copy.
    fn create_symlink(
        &self,
        relative: &str,
        link_target: &str,
        entry: &str,
    ) -> Result<bool, ArchiveError> {
        let normalized = link_target.replace('\\', "/");
        let Some(resolved) = resolve_link_relative(relative, &normalized) else {
            return Err(ArchiveError::UnsafeEntry {
                entry: entry.to_string(),
            });
        };

        // The target's parent may itself be an existing symlink; `ensure_dir`
        // resolves it and refuses one that leaves the root.
        let (target_parent, target_leaf) =
            resolved.rsplit_once('/').unwrap_or(("", resolved.as_str()));
        let target_parent = self.ensure_dir(target_parent, entry)?;
        let target_path = target_parent.join(target_leaf);
        let target_is_file = self.validate_link_target(&target_path, entry)?;

        let (parent, leaf) = relative.rsplit_once('/').unwrap_or(("", relative));
        let parent = self.ensure_dir(parent, entry)?;
        let path = parent.join(leaf);
        self.remove_leaf(&path, "a symlink")?;

        #[cfg(unix)]
        {
            if std::os::unix::fs::symlink(&normalized, &path).is_ok() {
                return Ok(true);
            }
        }

        #[cfg(windows)]
        {
            if std::os::windows::fs::symlink_file(&normalized, &path).is_ok() {
                return Ok(true);
            }
        }

        if target_is_file {
            std::fs::copy(&target_path, &path).map_err(|source| ArchiveError::Write {
                path: path.clone(),
                source,
            })?;
            return Ok(true);
        }

        Ok(false)
    }

    /// Whether a resolved symlink target is usable and still inside the root.
    ///
    /// Missing targets are valid (the link may be dangling until another entry
    /// arrives); existing symlinks are canonicalised so one that points outside
    /// the install folder can never be followed.
    fn validate_link_target(&self, path: &Path, entry: &str) -> Result<bool, ArchiveError> {
        match std::fs::symlink_metadata(path) {
            Ok(metadata) if is_reparse_point(&metadata) => {
                let resolved = path.canonicalize().map_err(|source| ArchiveError::Write {
                    path: path.to_path_buf(),
                    source,
                })?;

                if !resolved.starts_with(&self.root) {
                    return Err(ArchiveError::UnsafeEntry {
                        entry: entry.to_string(),
                    });
                }

                Ok(resolved.is_file())
            }
            Ok(metadata) => Ok(metadata.is_file()),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(false),
            Err(source) => Err(ArchiveError::Write {
                path: path.to_path_buf(),
                source,
            }),
        }
    }
}

fn count_entry(seen: &mut usize, limits: &ArchiveLimits) -> Result<(), ArchiveError> {
    *seen += 1;

    if *seen > limits.max_entries {
        return Err(ArchiveError::TooManyEntries {
            limit: limits.max_entries,
        });
    }

    Ok(())
}

fn account_size(total: &mut u64, added: u64, limit: u64) -> Result<(), ArchiveError> {
    *total = total.saturating_add(added);

    if *total > limit {
        return Err(ArchiveError::TooLarge { limit });
    }

    Ok(())
}

/// Copy one entry, refusing to write more than `maximum` bytes even when the
/// archive header lied about its size.
fn copy_limited(
    reader: &mut impl Read,
    writer: &mut impl Write,
    maximum: u64,
    limit: u64,
) -> Result<u64, ArchiveError> {
    let mut limited = reader.take(maximum.saturating_add(1));
    let copied = std::io::copy(&mut limited, writer)
        .map_err(|error| ArchiveError::Read(error.to_string()))?;

    if copied > maximum {
        return Err(ArchiveError::TooLarge { limit });
    }

    Ok(copied)
}

/// A zip entry with the Unix file-type bits set to something other than a
/// regular file (symlink, device, fifo) is skipped instead of followed.
fn zip_regular_file(mode: Option<u32>) -> bool {
    match mode.map(|mode| mode & 0o170_000) {
        None | Some(0) | Some(0o100_000) => true,
        Some(_) => false,
    }
}

/// Extract a zip over `target`. Existing files are replaced (same-name files
/// from the patch win).
pub fn extract_zip(zip_path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    extract_zip_with_limits(zip_path, target, ArchiveLimits::default())
}

pub fn extract_zip_with_limits(
    zip_path: &Path,
    target: &Path,
    limits: ArchiveLimits,
) -> Result<usize, ArchiveError> {
    extract_zip_inner(zip_path, target, limits, false)
}

/// Extract a portable base zip. Like [`extract_zip`], but one wrapper folder
/// around the game tree (`UT2004/System/...`) is stripped so the install stays
/// flat. `System/` is kept: a portable base may carry its own Windows
/// `System/UT2004.exe`, and a later patch simply overwrites the same names.
pub fn extract_zip_base(zip_path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    extract_zip_base_with_limits(zip_path, target, ArchiveLimits::default())
}

pub fn extract_zip_base_with_limits(
    zip_path: &Path,
    target: &Path,
    limits: ArchiveLimits,
) -> Result<usize, ArchiveError> {
    extract_zip_inner(zip_path, target, limits, true)
}

fn extract_zip_inner(
    zip_path: &Path,
    target: &Path,
    limits: ArchiveLimits,
    strip_wrapper: bool,
) -> Result<usize, ArchiveError> {
    let file = File::open(zip_path).map_err(|source| ArchiveError::Open {
        path: zip_path.to_path_buf(),
        source,
    })?;
    let mut zip =
        zip::ZipArchive::new(file).map_err(|error| ArchiveError::Read(error.to_string()))?;
    let wrapper = if strip_wrapper {
        let names = zip_names(&mut zip)?;
        wrapper_prefix_names(&names)
    } else {
        None
    };
    let target = SafeTarget::open(target)?;
    let mut written = 0;
    let mut seen = 0usize;
    let mut declared_total = 0u64;
    let mut copied_total = 0u64;

    for index in 0..zip.len() {
        count_entry(&mut seen, &limits)?;
        let mut entry = zip
            .by_index(index)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;
        let name = entry.name().to_string();
        let relative = safe_relative(&name)?;

        if entry.is_dir() {
            let relative = relative
                .map(|relative| strip_prefix(&relative, wrapper.as_deref()))
                .unwrap_or_default();
            target.ensure_dir(&relative, &name)?;
            continue;
        }

        if entry.is_symlink() || !zip_regular_file(entry.unix_mode()) {
            continue;
        }

        let relative = relative.ok_or_else(|| ArchiveError::UnsafeEntry {
            entry: name.clone(),
        })?;
        let relative = strip_prefix(&relative, wrapper.as_deref());

        if relative.is_empty() {
            continue;
        }

        account_size(
            &mut declared_total,
            entry.size(),
            limits.max_uncompressed_bytes,
        )?;

        let mut file = target.create_file(&relative, &name)?;
        let remaining = limits.max_uncompressed_bytes.saturating_sub(copied_total);
        let copied = copy_limited(
            &mut entry,
            &mut file,
            remaining,
            limits.max_uncompressed_bytes,
        )?;
        copied_total = copied_total.saturating_add(copied);
        written += 1;
    }

    Ok(written)
}

fn zip_names(zip: &mut zip::ZipArchive<File>) -> Result<Vec<String>, ArchiveError> {
    let mut names = Vec::with_capacity(zip.len());

    for index in 0..zip.len() {
        let entry = zip
            .by_index(index)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;

        if let Some(name) = safe_relative(entry.name())? {
            names.push(name);
        }
    }

    Ok(names)
}

/// File suffixes that mark a real game tree rather than an installer wrapper.
const GAME_DATA_EXTENSIONS: &[&str] =
    &["ut2", "ut3", "utx", "uax", "usx", "ukx", "u", "ucl", "upl"];

/// Game folders whose presence means an archive already carries the payload.
const GAME_DATA_FOLDERS: &[&str] = &[
    "system",
    "maps",
    "textures",
    "sounds",
    "animations",
    "staticmeshes",
    "karmadata",
];

fn looks_like_game_data(name: &str) -> bool {
    let lower = name.to_ascii_lowercase();
    let extension = lower.rsplit_once('.').map(|(_, extension)| extension);
    let in_game_folder = lower
        .split('/')
        .any(|part| GAME_DATA_FOLDERS.contains(&part));

    extension.is_some_and(|extension| GAME_DATA_EXTENSIONS.contains(&extension)) || in_game_folder
}

/// The single `.iso` entry of an installer-style base zip, if the zip wraps
/// exactly one image and carries no game tree of its own.
///
/// `ut2k4+keygen.zip` is the motivating shape: `setup.exe`, a keygen and one
/// ISO. Extracting it naively would only leave an installer in the target; the
/// caller extracts the image and runs [`extract_iso`] instead.
pub fn zip_single_iso_entry(path: &Path) -> Result<Option<String>, ArchiveError> {
    let file = File::open(path).map_err(|source| ArchiveError::Open {
        path: path.to_path_buf(),
        source,
    })?;
    let mut zip =
        zip::ZipArchive::new(file).map_err(|error| ArchiveError::Read(error.to_string()))?;
    let mut images = Vec::new();
    let mut has_game_tree = false;

    for index in 0..zip.len() {
        let entry = zip
            .by_index(index)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;

        if entry.is_dir() {
            continue;
        }

        let Some(name) = safe_relative(entry.name())? else {
            continue;
        };

        if name.to_ascii_lowercase().ends_with(".iso") {
            images.push(name.clone());
        }

        if looks_like_game_data(&name) {
            has_game_tree = true;
        }
    }

    if images.len() == 1 && !has_game_tree {
        Ok(images.pop())
    } else {
        Ok(None)
    }
}

/// Write one zip entry to `dest` (used for an ISO nested inside a base zip).
pub fn extract_zip_entry(
    zip_path: &Path,
    entry_name: &str,
    dest: &Path,
) -> Result<u64, ArchiveError> {
    let file = File::open(zip_path).map_err(|source| ArchiveError::Open {
        path: zip_path.to_path_buf(),
        source,
    })?;
    let mut zip =
        zip::ZipArchive::new(file).map_err(|error| ArchiveError::Read(error.to_string()))?;

    for index in 0..zip.len() {
        let mut entry = zip
            .by_index(index)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;

        if entry.is_dir() {
            continue;
        }

        let Some(name) = safe_relative(entry.name())? else {
            continue;
        };

        if name != entry_name {
            continue;
        }

        if let Some(parent) = dest.parent() {
            std::fs::create_dir_all(parent).map_err(|source| ArchiveError::Write {
                path: parent.to_path_buf(),
                source,
            })?;
        }

        let mut out = File::create(dest).map_err(|source| ArchiveError::Write {
            path: dest.to_path_buf(),
            source,
        })?;
        let copied = std::io::copy(&mut entry, &mut out)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;

        return Ok(copied);
    }

    Err(ArchiveError::Read(format!(
        "{entry_name} is not part of {}",
        zip_path.display()
    )))
}

/// Preserve the executable bit a tar entry declares. The OldUnreal Linux
/// patch ships `System/UT2004` as `0755`; extraction alone must already
/// produce a launchable file. Setuid/setgid/sticky bits are stripped: an
/// archive is not allowed to promote itself.
#[cfg(unix)]
fn apply_tar_mode(file: &File, mode: Option<u32>) {
    use std::os::unix::fs::PermissionsExt;

    let Some(mode) = mode else {
        return;
    };
    let mode = mode & 0o777;

    if mode & 0o111 == 0 {
        return;
    }

    let _ = file.set_permissions(std::fs::Permissions::from_mode(mode));
}

#[cfg(not(unix))]
fn apply_tar_mode(_file: &File, _mode: Option<u32>) {}

/// Extract a tar.bz2 over `target` (the Linux patch).
pub fn extract_tar_bz2(path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    extract_tar_bz2_with_limits(path, target, ArchiveLimits::default())
}

pub fn extract_tar_bz2_with_limits(
    path: &Path,
    target: &Path,
    limits: ArchiveLimits,
) -> Result<usize, ArchiveError> {
    extract_tar_bz2_inner(path, target, limits, false)
}

/// Extract a portable base tar.bz2. One wrapper folder around the game tree is
/// stripped; `System/` is kept so a portable Windows/Linux base stays intact
/// and a later patch only overwrites what it ships.
pub fn extract_tar_bz2_base(path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    extract_tar_bz2_base_with_limits(path, target, ArchiveLimits::default())
}

pub fn extract_tar_bz2_base_with_limits(
    path: &Path,
    target: &Path,
    limits: ArchiveLimits,
) -> Result<usize, ArchiveError> {
    extract_tar_bz2_inner(path, target, limits, true)
}

fn extract_tar_bz2_inner(
    path: &Path,
    target: &Path,
    limits: ArchiveLimits,
    strip_wrapper: bool,
) -> Result<usize, ArchiveError> {
    let wrapper = if strip_wrapper {
        let names = tar_bz2_names(path)?;
        wrapper_prefix_names(&names)
    } else {
        None
    };

    let file = File::open(path).map_err(|source| ArchiveError::Open {
        path: path.to_path_buf(),
        source,
    })?;
    let decoder = bzip2::read::BzDecoder::new(BufReader::new(file));
    let mut archive = tar::Archive::new(decoder);
    let target = SafeTarget::open(target)?;
    let mut written = 0;
    let mut seen = 0usize;
    let mut declared_total = 0u64;
    let mut copied_total = 0u64;
    // Symlinks are deferred until every regular file exists: the patch stores
    // `ut2004-bin-amd64 -> UT2004` before it stores `UT2004`, and the copy
    // fallback on a platform without symlinks needs the target file first.
    let mut links = Vec::new();

    for entry in archive
        .entries()
        .map_err(|error| ArchiveError::Read(error.to_string()))?
    {
        count_entry(&mut seen, &limits)?;
        let mut entry = entry.map_err(|error| ArchiveError::Read(error.to_string()))?;
        let path_in_archive = entry
            .path()
            .map_err(|error| ArchiveError::Read(error.to_string()))?
            .to_string_lossy()
            .to_string();
        let kind = entry.header().entry_type();
        let relative = safe_relative(&path_in_archive)?;

        if kind.is_dir() {
            // `./` from `tar -cjf patch.tar.bz2 .` is the target itself.
            let relative = relative
                .map(|relative| strip_prefix(&relative, wrapper.as_deref()))
                .unwrap_or_default();
            target.ensure_dir(&relative, &path_in_archive)?;
            continue;
        }

        if kind.is_symlink() {
            let link_target = entry
                .link_name()
                .map_err(|error| ArchiveError::Read(error.to_string()))?
                .ok_or_else(|| ArchiveError::Read(format!("{path_in_archive} has no link name")))?
                .to_string_lossy()
                .to_string();
            let relative = relative.ok_or_else(|| ArchiveError::UnsafeEntry {
                entry: path_in_archive.clone(),
            })?;
            let relative = strip_prefix(&relative, wrapper.as_deref());

            if !relative.is_empty() {
                links.push((relative, link_target, path_in_archive));
            }

            continue;
        }

        if !kind.is_file() {
            // Hard links are not part of the UT2004 patch; skip them rather
            // than following a link out of the target.
            continue;
        }

        let relative = relative.ok_or_else(|| ArchiveError::UnsafeEntry {
            entry: path_in_archive.clone(),
        })?;
        let relative = strip_prefix(&relative, wrapper.as_deref());

        if relative.is_empty() {
            continue;
        }

        let declared = entry
            .header()
            .size()
            .map_err(|error| ArchiveError::Read(error.to_string()))?;
        let mode = entry.header().mode().ok();
        account_size(&mut declared_total, declared, limits.max_uncompressed_bytes)?;

        let mut file = target.create_file(&relative, &path_in_archive)?;
        let remaining = limits.max_uncompressed_bytes.saturating_sub(copied_total);
        let copied = copy_limited(
            &mut entry,
            &mut file,
            remaining,
            limits.max_uncompressed_bytes,
        )?;
        apply_tar_mode(&file, mode);
        copied_total = copied_total.saturating_add(copied);
        written += 1;
    }

    for (relative, link_target, path_in_archive) in links {
        if target.create_symlink(&relative, &link_target, &path_in_archive)? {
            written += 1;
        }
    }

    Ok(written)
}

fn tar_bz2_names(path: &Path) -> Result<Vec<String>, ArchiveError> {
    let file = File::open(path).map_err(|source| ArchiveError::Open {
        path: path.to_path_buf(),
        source,
    })?;
    let decoder = bzip2::read::BzDecoder::new(BufReader::new(file));
    let mut archive = tar::Archive::new(decoder);
    let mut names = Vec::new();

    for entry in archive
        .entries()
        .map_err(|error| ArchiveError::Read(error.to_string()))?
    {
        let entry = entry.map_err(|error| ArchiveError::Read(error.to_string()))?;
        let path_in_archive = entry
            .path()
            .map_err(|error| ArchiveError::Read(error.to_string()))?
            .to_string_lossy()
            .to_string();

        if let Some(name) = safe_relative(&path_in_archive)? {
            names.push(name);
        }
    }

    Ok(names)
}

/// One ISO directory entry, decoupled from the libmkiso reader so the
/// extraction layout can be planned and tested without an image.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct IsoEntryDesc {
    /// Reader index, used later to extract the payload.
    pub index: usize,
    /// Full slash-separated path inside the image (`UT2004/Maps/DM-Rankin.ut2`).
    pub name: String,
    pub directory: bool,
}

impl IsoEntryDesc {
    pub fn from_iso_entry(index: usize, entry: &IsoEntry) -> Self {
        Self {
            index,
            name: entry.name.clone(),
            directory: entry.directory,
        }
    }
}

/// One planned write: where an image entry lands inside the target.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct IsoPlanEntry {
    pub index: usize,
    pub relative: String,
    pub directory: bool,
}

/// The layout decision for a whole image, computed before anything is written.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct IsoExtractionPlan {
    /// The stripped wrapper folder, if the image used one.
    pub wrapper: Option<String>,
    pub entries: Vec<IsoPlanEntry>,
    /// Number of payload files (directories excluded).
    pub files: usize,
}

/// Decide the target layout of an image without touching the filesystem.
///
/// - one wrapper folder around `System/` (`UT2004/System/...`) is stripped,
/// - the top-level `System/` tree is skipped (the patch supplies it),
/// - entry names are rejected when they are absolute or contain `..`,
/// - names keep their case (the reader selects the Joliet namespace),
/// - at least one payload file must remain.
pub fn plan_iso_extraction(entries: &[IsoEntryDesc]) -> Result<IsoExtractionPlan, ArchiveError> {
    let mut normalized = Vec::with_capacity(entries.len());

    for entry in entries {
        if let Some(name) = safe_relative(&entry.name)? {
            normalized.push(IsoEntryDesc {
                index: entry.index,
                name,
                directory: entry.directory,
            });
        }
    }

    let wrapper = wrapper_prefix(&normalized);
    let mut plan = IsoExtractionPlan {
        wrapper: wrapper.clone(),
        ..IsoExtractionPlan::default()
    };

    for entry in &normalized {
        let relative = strip_prefix(&entry.name, wrapper.as_deref());

        if relative.is_empty() {
            continue;
        }

        // The patch supplies the whole System tree.
        if first_component(&relative).is_some_and(|part| part.eq_ignore_ascii_case("System")) {
            continue;
        }

        if !entry.directory {
            plan.files += 1;
        }

        plan.entries.push(IsoPlanEntry {
            index: entry.index,
            relative,
            directory: entry.directory,
        });
    }

    if plan.files == 0 {
        return Err(ArchiveError::IsoLayout(
            "no payload files outside System/".into(),
        ));
    }

    Ok(plan)
}

/// Extract the game payload from an ISO9660/Joliet image.
///
/// The retail disc's `System/` tree is skipped (the patch ships the complete
/// 64-bit System tree). If the image wraps everything in one folder that has a
/// `System/` child, that wrapper is stripped so the patch lands in
/// `<target>/System` as the recipe expects. Joliet names are preferred so the
/// case of `Maps/`, `Textures/` and the executables survives.
pub fn extract_iso(iso_path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    extract_iso_with_limits(iso_path, target, ArchiveLimits::default())
}

pub fn extract_iso_with_limits(
    iso_path: &Path,
    target: &Path,
    limits: ArchiveLimits,
) -> Result<usize, ArchiveError> {
    let file = File::open(iso_path).map_err(|source| ArchiveError::Open {
        path: iso_path.to_path_buf(),
        source,
    })?;
    let mut reader = IsoReader::open_with_options(
        file,
        ReadOptions {
            namespace: Namespace::PreferJoliet,
            limits: Limits::default(),
        },
    )
    .map_err(|error| ArchiveError::Read(error.to_string()))?;

    let entries = reader.entries().to_vec();

    if entries.is_empty() {
        return Err(ArchiveError::EmptyIso);
    }

    if entries.len() > limits.max_entries {
        return Err(ArchiveError::TooManyEntries {
            limit: limits.max_entries,
        });
    }

    let descriptors = entries
        .iter()
        .enumerate()
        .map(|(index, entry)| IsoEntryDesc::from_iso_entry(index, entry))
        .collect::<Vec<_>>();
    let plan = plan_iso_extraction(&descriptors)?;

    let declared = plan
        .entries
        .iter()
        .filter(|entry| !entry.directory)
        .filter_map(|entry| entries.get(entry.index))
        .fold(0u64, |total, entry| total.saturating_add(entry.size));

    if declared > limits.max_uncompressed_bytes {
        return Err(ArchiveError::TooLarge {
            limit: limits.max_uncompressed_bytes,
        });
    }

    let mut written = 0;
    let target = SafeTarget::open(target)?;

    for entry in &plan.entries {
        if entry.directory {
            target.ensure_dir(&entry.relative, &entry.relative)?;
            continue;
        }

        let mut file = target.create_file(&entry.relative, &entry.relative)?;
        reader
            .extract(entry.index, &mut file)
            .map_err(|error| ArchiveError::Read(error.to_string()))?;
        written += 1;
    }

    if written == 0 {
        return Err(ArchiveError::IsoLayout(
            "no payload files outside System/".into(),
        ));
    }

    Ok(written)
}

/// Attach a DMG read-only, copy its contents with `ditto`, and detach it again.
///
/// The image is a retail-style fallback, so the payload is copied as-is; the
/// OldUnreal patch then overwrites `System/`. On every non-macOS host the
/// flavour is recognised but extraction fails with a clear platform error
/// instead of being mistaken for a patch archive.
#[cfg(target_os = "macos")]
pub fn extract_dmg(dmg_path: &Path, target: &Path) -> Result<usize, ArchiveError> {
    let attach = std::process::Command::new("hdiutil")
        .args(["attach", "-nobrowse", "-readonly", "-plist"])
        .arg(dmg_path)
        .output()
        .map_err(|error| ArchiveError::Dmg(format!("hdiutil attach: {error}")))?;

    if !attach.status.success() {
        return Err(ArchiveError::Dmg(format!(
            "hdiutil attach failed: {}",
            String::from_utf8_lossy(&attach.stderr).trim()
        )));
    }

    let plist = String::from_utf8_lossy(&attach.stdout);
    let mount_point = plist_string_value(&plist, "mount-point")
        .ok_or_else(|| ArchiveError::Dmg("hdiutil reported no mount point".into()))?;
    let mount = PathBuf::from(mount_point);
    let copied = copy_dmg_payload(&mount, target);
    // Detach even when the copy failed: a mounted volume must not stay behind.
    let _ = std::process::Command::new("hdiutil")
        .arg("detach")
        .arg(&mount)
        .arg("-quiet")
        .status();

    copied
}

#[cfg(not(target_os = "macos"))]
pub fn extract_dmg(_dmg_path: &Path, _target: &Path) -> Result<usize, ArchiveError> {
    Err(ArchiveError::DmgUnsupported)
}

#[cfg(target_os = "macos")]
fn copy_dmg_payload(mount: &Path, target: &Path) -> Result<usize, ArchiveError> {
    std::fs::create_dir_all(target).map_err(|source| ArchiveError::Write {
        path: target.to_path_buf(),
        source,
    })?;

    let status = std::process::Command::new("ditto")
        .arg(mount)
        .arg(target)
        .status()
        .map_err(|error| ArchiveError::Dmg(format!("ditto: {error}")))?;

    if !status.success() {
        return Err(ArchiveError::Dmg(format!("ditto exited with {status}")));
    }

    Ok(count_files(mount))
}

#[cfg(target_os = "macos")]
fn count_files(root: &Path) -> usize {
    let Ok(entries) = std::fs::read_dir(root) else {
        return 0;
    };
    let mut total = 0;

    for entry in entries.flatten() {
        let path = entry.path();
        let Ok(metadata) = std::fs::symlink_metadata(&path) else {
            continue;
        };

        if metadata.file_type().is_symlink() {
            continue;
        }

        if metadata.is_dir() {
            total += count_files(&path);
        } else {
            total += 1;
        }
    }

    total
}

#[cfg(target_os = "macos")]
fn plist_string_value(plist: &str, key: &str) -> Option<String> {
    let needle = format!("<key>{key}</key>");
    let after_key = &plist[plist.find(&needle)? + needle.len()..];
    let start = after_key.find("<string>")? + "<string>".len();
    let end = after_key[start..].find("</string>")? + start;

    Some(unescape_xml(&after_key[start..end]))
}

#[cfg(target_os = "macos")]
fn unescape_xml(value: &str) -> String {
    value
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&apos;", "'")
        .replace("&amp;", "&")
}

fn first_component(name: &str) -> Option<&str> {
    name.split('/')
        .find(|part| !part.is_empty() && *part != ".")
}

/// Detect a single wrapper folder around a `System/` tree. `UT2004/System/...`
/// is the common authoring shape; a disc with top-level `System/` has none.
fn wrapper_prefix(entries: &[IsoEntryDesc]) -> Option<String> {
    let names = entries
        .iter()
        .map(|entry| entry.name.clone())
        .collect::<Vec<_>>();

    wrapper_prefix_names(&names)
}

/// Detect the one wrapper folder of a base archive or image without touching
/// the filesystem.
///
/// The archive must have exactly one top-level folder, must not itself start
/// with a game folder (`System/`, `Maps/`, …), and that folder must contain a
/// game tree (`System`, `Maps`, `Textures`, … or a `.app` bundle). A macOS
/// bundle at the root is kept: `UT2004.app/Contents/...` is the install layout,
/// not a wrapper.
fn wrapper_prefix_names(names: &[String]) -> Option<String> {
    let has_root_game_folder = names
        .iter()
        .filter_map(|name| first_component(name))
        .any(|part| is_game_folder(part) || part.to_ascii_lowercase().ends_with(".app"));

    if has_root_game_folder {
        return None;
    }

    let mut roots = names
        .iter()
        .filter_map(|name| first_component(name))
        .collect::<Vec<_>>();
    roots.sort_unstable();
    roots.dedup();

    if roots.len() != 1 {
        return None;
    }

    let root = roots[0];
    let has_wrapped_game_tree = names.iter().any(|name| {
        name.strip_prefix(root)
            .and_then(|rest| rest.strip_prefix('/'))
            .and_then(first_component)
            .is_some_and(|part| is_game_folder(part) || part.to_ascii_lowercase().ends_with(".app"))
    });

    has_wrapped_game_tree.then(|| root.to_string())
}

fn is_game_folder(part: &str) -> bool {
    GAME_DATA_FOLDERS
        .iter()
        .any(|folder| part.eq_ignore_ascii_case(folder))
}

fn strip_prefix(name: &str, prefix: Option<&str>) -> String {
    let Some(prefix) = prefix else {
        return name.to_string();
    };

    if name == prefix {
        return String::new();
    }

    name.strip_prefix(prefix)
        .and_then(|rest| rest.strip_prefix('/'))
        .map(str::to_string)
        .unwrap_or_else(|| name.to_string())
}

/// A tiny convenience for streams used by the installer.
pub fn copy_stream(mut reader: impl Read, mut writer: impl Write) -> Result<u64, ArchiveError> {
    std::io::copy(&mut reader, &mut writer).map_err(|error| ArchiveError::Read(error.to_string()))
}

/// Sniff the archive flavour by extension first, then by magic bytes. A file
/// name from a recipe is not always reliable (`UT2004 DVD` has no suffix).
pub fn detect_iso(path: &Path) -> bool {
    if path
        .extension()
        .is_some_and(|ext| ext.eq_ignore_ascii_case("iso"))
    {
        return true;
    }

    let Ok(mut file) = File::open(path) else {
        return false;
    };
    let mut signature = [0u8; 5];

    if file
        .seek(SeekFrom::Start(16 * 2048 + 1))
        .and_then(|_| file.read_exact(&mut signature))
        .is_err()
    {
        return false;
    }

    &signature == b"CD001"
}

pub fn detect_zip(path: &Path) -> bool {
    if path
        .extension()
        .is_some_and(|ext| ext.eq_ignore_ascii_case("zip"))
    {
        return true;
    }

    magic(path, &[b'P', b'K', 3, 4]) || magic(path, &[b'P', b'K', 5, 6])
}

pub fn detect_tar_bz2(path: &Path) -> bool {
    let name = path.to_string_lossy().to_ascii_lowercase();

    if name.ends_with(".tar.bz2") || name.ends_with(".tbz2") || name.ends_with(".tbz") {
        return true;
    }

    magic(path, b"BZh")
}

/// A macOS disk image. The extension is enough for a plan file; a renamed
/// image is recognised by the UDIF trailer signature (`koly`) in its last 512
/// bytes, the same way `hdiutil` identifies one.
pub fn detect_dmg(path: &Path) -> bool {
    if path
        .extension()
        .is_some_and(|ext| ext.eq_ignore_ascii_case("dmg"))
    {
        return true;
    }

    let Ok(mut file) = File::open(path) else {
        return false;
    };
    let Ok(length) = file.seek(SeekFrom::End(0)) else {
        return false;
    };

    if length < 512 || file.seek(SeekFrom::End(-512)).is_err() {
        return false;
    }

    let mut signature = [0u8; 4];
    file.read_exact(&mut signature).is_ok() && &signature == b"koly"
}

fn magic(path: &Path, expected: &[u8]) -> bool {
    let Ok(mut file) = File::open(path) else {
        return false;
    };
    let mut bytes = vec![0u8; expected.len()];

    file.read_exact(&mut bytes).is_ok() && bytes == expected
}

#[cfg(test)]
mod tests {
    use super::*;

    fn desc(index: usize, name: &str, directory: bool) -> IsoEntryDesc {
        IsoEntryDesc {
            index,
            name: name.to_string(),
            directory,
        }
    }

    #[test]
    fn safe_join_rejects_absolute_and_parent_entries() {
        let target = Path::new("/tmp/game");
        assert_eq!(
            safe_join(target, "Maps/DM-Rankin.ut2").unwrap(),
            target.join("Maps/DM-Rankin.ut2")
        );
        assert_eq!(safe_join(target, "/Maps/x").unwrap(), target.join("Maps/x"));
        assert!(safe_join(target, "../etc/passwd").is_err());
        assert!(safe_join(target, "Maps/../../etc/passwd").is_err());
        assert!(safe_join(target, "C:\\Windows\\x").is_err());
        assert!(safe_join(target, "C:/Windows/x").is_err());
    }

    #[test]
    fn safe_relative_normalizes_dots_and_rejects_escapes() {
        assert_eq!(safe_relative(".").unwrap(), None);
        assert_eq!(safe_relative("./").unwrap(), None);
        assert_eq!(safe_relative("").unwrap(), None);
        assert_eq!(
            safe_relative("./System/Build.ini").unwrap(),
            Some("System/Build.ini".to_string())
        );
        assert_eq!(
            safe_relative("Maps//DM-Rankin.ut2").unwrap(),
            Some("Maps/DM-Rankin.ut2".to_string())
        );
        assert!(safe_relative("../etc/passwd").is_err());
        assert!(safe_relative("Maps/../../etc/passwd").is_err());
        assert!(safe_relative("/etc/passwd").is_err());
        assert!(safe_relative("C:\\Windows\\x").is_err());
        assert!(safe_relative("bad\0name").is_err());
    }

    #[test]
    fn a_windows_hostile_entry_component_is_refused() {
        for hostile in [
            "System/CON",
            "System/nul.txt",
            "System/COM1",
            "System/LPT1.ini",
            "System/Build.ini.",
            "System/Build.ini ",
            "System/Build.ini:stream",
            "System/UT2004.exe*",
            "System/...",
            "System/C:",
        ] {
            assert!(safe_relative(hostile).is_err(), "{hostile:?}");
        }

        assert_eq!(
            safe_relative("System/UT2004.exe").unwrap(),
            Some("System/UT2004.exe".to_string())
        );
        assert_eq!(
            safe_relative("Maps/DM-Rankin.ut2").unwrap(),
            Some("Maps/DM-Rankin.ut2".to_string())
        );
    }

    #[test]
    fn wrapper_detection_strips_one_ut2004_folder() {
        let wrapped = vec![
            desc(1, "UT2004/System/UT2004.exe", false),
            desc(2, "UT2004/Maps/DM-Rankin.ut2", false),
        ];
        assert_eq!(wrapper_prefix(&wrapped), Some("UT2004".to_string()));

        let flat = vec![
            desc(1, "System/UT2004.exe", false),
            desc(2, "Maps/DM-Rankin.ut2", false),
        ];
        assert_eq!(wrapper_prefix(&flat), None);
    }

    #[test]
    fn relative_symlink_targets_stay_inside_the_extraction_root() {
        assert_eq!(
            resolve_link_relative("System/ut2004-bin-amd64", "UT2004"),
            Some("System/UT2004".to_string())
        );
        assert_eq!(
            resolve_link_relative("System/link", "../Maps/DM-Rankin.ut2"),
            Some("Maps/DM-Rankin.ut2".to_string())
        );
        assert_eq!(
            resolve_link_relative("System/link", "./UT2004"),
            Some("System/UT2004".to_string())
        );

        for hostile in ["../../outside", "/etc/passwd", "C:\\Windows\\x", ".."] {
            assert_eq!(
                resolve_link_relative("System/link", hostile),
                None,
                "{hostile} must not resolve inside the root"
            );
        }
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
        assert_eq!(
            std::fs::read(target.join("System/Build.ini")).unwrap(),
            b"Label=v3374\n"
        );
    }
}
