//! Security boundaries of every path the launcher builds from untrusted input:
//! archive entry names (zip/tar/ISO) and the download file name from a plan.
//!
//! The tests lock the invariants that must hold on every platform: traversal,
//! drive prefixes, backslashes, Windows-reserved names, symlinks planted in the
//! target. The launcher installs on Windows too, so a name validated on Linux
//! must already be Windows-safe.
//!
//! L9 (security review) raised S1; the tests are its regression lock.

// Some helpers and imports are only used by the `#[cfg(unix)]` tests below: on Windows they would be
// dead code, and `-D warnings` in `ci/build.sh` (cargo xwin clippy) must stay green.
#![cfg_attr(windows, allow(dead_code, unused_imports))]

use std::fs::File;
use std::io::Write;
use std::path::{Path, PathBuf};

use launcher_core::archive::{
    extract_zip, plan_iso_extraction, safe_relative, ArchiveError, IsoEntryDesc,
};
use launcher_core::download::sanitize_filename;

fn write_zip(path: &Path, entries: &[(&str, &[u8])]) -> PathBuf {
    let file = File::create(path).unwrap();
    let mut zip = zip::ZipWriter::new(file);
    let options = zip::write::SimpleFileOptions::default();

    for (name, body) in entries {
        zip.start_file(*name, options).unwrap();
        zip.write_all(body).unwrap();
    }

    zip.finish().unwrap();
    path.to_path_buf()
}

fn desc(index: usize, name: &str, directory: bool) -> IsoEntryDesc {
    IsoEntryDesc {
        index,
        name: name.to_string(),
        directory,
    }
}

#[test]
fn no_archive_entry_spelling_escapes_with_dotdot_or_backslashes() {
    for hostile in [
        "../escape.txt",
        "..\\escape.txt",
        "./../escape.txt",
        "System/../../escape.txt",
        "System\\..\\..\\escape.txt",
        "/etc/passwd",
        "\\\\server\\share\\escape.txt",
        "C:\\Windows\\escape.txt",
        "C:escape.txt",
        "\\\\?\\C:\\escape.txt",
    ] {
        assert!(
            safe_relative(hostile).is_err(),
            "{hostile:?} must not be a relative entry"
        );
    }

    assert_eq!(safe_relative(".").unwrap(), None);
    assert_eq!(safe_relative("./").unwrap(), None);
    assert_eq!(safe_relative("").unwrap(), None);
    assert_eq!(
        safe_relative("./System/Build.ini").unwrap(),
        Some("System/Build.ini".to_string())
    );
}

#[test]
fn no_iso_entry_spelling_escapes_the_plan() {
    for hostile in [
        "../Maps/DM-Rankin.ut2",
        "/Maps/DM-Rankin.ut2",
        "D:/Maps/DM-Rankin.ut2",
        "UT2004/../../Maps/DM-Rankin.ut2",
    ] {
        let entries = vec![desc(0, hostile, false)];
        assert!(
            matches!(
                plan_iso_extraction(&entries),
                Err(ArchiveError::UnsafeEntry { .. })
            ),
            "{hostile:?} must be refused"
        );
    }
}

#[test]
fn a_download_name_keeps_only_its_last_component() {
    for (input, expected) in [
        ("../../../etc/ut2004.iso", "ut2004.iso"),
        ("..\\..\\Windows\\ut2004.iso", "ut2004.iso"),
        ("/tmp/ut2004.iso", "ut2004.iso"),
        ("ut2004.iso", "ut2004.iso"),
    ] {
        assert_eq!(sanitize_filename(input).unwrap(), expected);
    }
}

/// A link planted in the target must not receive the patch. `SafeTarget`
/// resolves every existing component and refuses a symlink that leaves the
/// install root.
#[cfg(unix)]
#[test]
fn extraction_never_writes_through_a_preexisting_directory_symlink() {
    let dir = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    let outside = dir.path().join("outside");
    std::fs::create_dir_all(&target).unwrap();
    std::fs::create_dir_all(&outside).unwrap();
    std::os::unix::fs::symlink(&outside, target.join("System")).unwrap();

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[("System/Build.ini", b"pwned")],
    );

    let result = extract_zip(&zip_path, &target);

    assert!(
        matches!(result, Err(ArchiveError::UnsafeEntry { .. })),
        "{result:?}"
    );
    assert!(
        !outside.join("Build.ini").exists(),
        "the patch must not land outside the install root"
    );
}

/// A symlinked leaf is replaced (the link, not its target) instead of being
/// written through.
#[cfg(unix)]
#[test]
fn extraction_replaces_a_preexisting_leaf_symlink_instead_of_following_it() {
    let dir = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    let outside = dir.path().join("outside");
    std::fs::create_dir_all(target.join("System")).unwrap();
    std::fs::create_dir_all(&outside).unwrap();
    let victim = outside.join("victim.ini");
    std::fs::write(&victim, b"untouched").unwrap();
    std::os::unix::fs::symlink(&victim, target.join("System/Build.ini")).unwrap();

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[("System/Build.ini", b"pwned")],
    );

    assert_eq!(extract_zip(&zip_path, &target).unwrap(), 1);

    assert_eq!(std::fs::read(&victim).unwrap(), b"untouched");
    let metadata = std::fs::symlink_metadata(target.join("System/Build.ini")).unwrap();
    assert!(!metadata.file_type().is_symlink());
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"pwned"
    );
}

/// S1 (Windows): `sanitize_filename` refuses a Windows drive prefix.
/// `PathBuf::push` replaces the base when the pushed name has a prefix, so
/// `dest_dir.join("C:evil.iso")` would be `C:evil.iso` — a drive-relative path
/// outside the download directory. `BuildInstallPlan` sends the uploader's
/// `original_name` unchanged, so `C:WebView2Loader.dll` is a plausible upload
/// name and would land next to the launcher binary (DLL hijack) instead of in
/// `downloads_dir`.
#[test]
fn a_download_name_with_a_windows_drive_prefix_is_refused() {
    assert!(
        sanitize_filename("C:ut2004.iso").is_err(),
        "a drive-prefixed name escapes dest_dir on Windows"
    );
    assert!(sanitize_filename("C:").is_err());
    assert!(sanitize_filename("z:WebView2Loader.dll").is_err());
}

/// S1 (Windows): `sanitize_filename` refuses characters Windows cannot use in
/// a plain file name. `:` writes an alternate data stream, `*?\<>|` make the
/// create fail or resolve to another file.
#[test]
fn a_download_name_with_windows_reserved_characters_is_refused() {
    for hostile in [
        "ut2004:evil.iso",
        "ut2004*.iso",
        "ut2004?.iso",
        "ut2004\".iso",
        "ut2004<.iso",
        "ut2004>.iso",
        "ut2004|.iso",
        "UT2004.EXE:stream",
    ] {
        assert!(
            sanitize_filename(hostile).is_err(),
            "{hostile:?} must be refused on a launcher that runs on Windows"
        );
    }
}

/// S1 (Windows): the same class for archive entries. `CON`/`NUL` open a device
/// instead of a file, a trailing dot or space is stripped by Win32 (so
/// `Build.ini.` overwrites `Build.ini`), and a colon writes an ADS.
#[test]
fn an_archive_entry_with_a_windows_hostile_name_is_refused() {
    for hostile in [
        "System/CON",
        "System/NUL",
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
        assert!(
            safe_relative(hostile).is_err(),
            "{hostile:?} must be refused on a launcher that extracts on Windows"
        );
    }
}
