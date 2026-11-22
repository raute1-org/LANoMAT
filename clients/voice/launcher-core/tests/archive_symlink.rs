//! R2 F5: extraction must not follow a symlink that already exists in the
//! target. A re-install into a folder whose `System` is a symlink used to
//! write the patch into the link target instead of the install folder.

// Some helpers and imports are only used by the `#[cfg(unix)]` tests below: on Windows they would be
// dead code, and `-D warnings` in `ci/build.sh` (cargo xwin clippy) must stay green.
#![cfg_attr(windows, allow(dead_code, unused_imports))]

use std::fs::File;
use std::io::Write;
use std::path::{Path, PathBuf};

use launcher_core::archive::{extract_iso, extract_tar_bz2, extract_zip, safe_join, ArchiveError};

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

#[cfg(unix)]
fn symlink(source: &Path, link: &Path) {
    std::os::unix::fs::symlink(source, link).unwrap();
}

fn write_tar_bz2(path: &Path, entries: &[(&str, &[u8])]) {
    let file = File::create(path).unwrap();
    let encoder = bzip2::write::BzEncoder::new(file, bzip2::Compression::best());
    let mut builder = tar::Builder::new(encoder);

    for (name, body) in entries {
        let mut header = tar::Header::new_gnu();
        header.set_size(body.len() as u64);
        header.set_mode(0o644);
        header.set_cksum();
        builder.append_data(&mut header, name, *body).unwrap();
    }

    builder.into_inner().unwrap().finish().unwrap();
}

fn zip_error(zip_path: &Path, target: &Path) -> ArchiveError {
    extract_zip(zip_path, target).unwrap_err()
}

/// The reported failure: `target/System` is a symlink to a directory outside
/// the install folder, so the patch must not land there.
#[cfg(unix)]
#[test]
fn zip_extraction_does_not_follow_a_symlinked_parent_out_of_the_target() {
    let dir = tempfile::tempdir().unwrap();
    let outside = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    std::fs::create_dir_all(&target).unwrap();
    std::fs::write(outside.path().join("keep.txt"), b"keep").unwrap();
    symlink(outside.path(), &target.join("System"));

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[("System/Build.ini", b"Label=v3374\n")],
    );

    let error = zip_error(&zip_path, &target);
    assert!(
        matches!(error, ArchiveError::UnsafeEntry { .. }),
        "expected UnsafeEntry, got {error:?}"
    );
    assert!(
        !outside.path().join("Build.ini").exists(),
        "the patch must not be written into the link target"
    );
    assert_eq!(
        std::fs::read_to_string(outside.path().join("keep.txt")).unwrap(),
        "keep"
    );
    assert_eq!(
        std::fs::read_dir(outside.path()).unwrap().count(),
        1,
        "nothing may be created in the link target"
    );
}

/// The same shape one level deeper: a symlinked directory inside the target
/// that points outside.
#[cfg(unix)]
#[test]
fn zip_extraction_does_not_follow_a_nested_symlinked_parent() {
    let dir = tempfile::tempdir().unwrap();
    let outside = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    std::fs::create_dir_all(target.join("Maps")).unwrap();
    symlink(outside.path(), &target.join("Maps/DM"));

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[("Maps/DM/DM-Rankin.ut2", b"rank in")],
    );

    assert!(matches!(
        zip_error(&zip_path, &target),
        ArchiveError::UnsafeEntry { .. }
    ));
    assert!(!outside.path().join("DM-Rankin.ut2").exists());
}

/// A symlinked *leaf* is replaced by a regular file; the link target keeps its
/// old content.
#[cfg(unix)]
#[test]
fn zip_extraction_replaces_a_symlinked_leaf_instead_of_writing_through_it() {
    let dir = tempfile::tempdir().unwrap();
    let outside = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    std::fs::create_dir_all(target.join("System")).unwrap();
    let victim = outside.path().join("Build.ini");
    std::fs::write(&victim, b"outside\n").unwrap();
    symlink(&victim, &target.join("System/Build.ini"));

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[("System/Build.ini", b"Label=v3374\n")],
    );

    assert_eq!(extract_zip(&zip_path, &target).unwrap(), 1);
    assert_eq!(
        std::fs::read(&victim).unwrap(),
        b"outside\n",
        "the link target must not be overwritten"
    );

    let written = target.join("System/Build.ini");
    assert!(
        !std::fs::symlink_metadata(&written)
            .unwrap()
            .file_type()
            .is_symlink(),
        "the leaf must be a real file after extraction"
    );
    assert_eq!(std::fs::read(&written).unwrap(), b"Label=v3374\n");
}

/// A symlink that stays inside the install folder is still usable.
#[cfg(unix)]
#[test]
fn zip_extraction_may_follow_a_symlink_that_stays_inside_the_target() {
    let dir = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    std::fs::create_dir_all(target.join("RealSystem")).unwrap();
    symlink(&target.join("RealSystem"), &target.join("System"));

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[("System/Build.ini", b"Label=v3374\n")],
    );

    assert_eq!(extract_zip(&zip_path, &target).unwrap(), 1);
    assert_eq!(
        std::fs::read(target.join("RealSystem/Build.ini")).unwrap(),
        b"Label=v3374\n"
    );
}

/// A plain target (first install and re-install over old files) still works.
#[test]
fn zip_extraction_into_a_plain_target_creates_and_replaces_files() {
    let dir = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    std::fs::create_dir_all(target.join("System")).unwrap();
    std::fs::write(target.join("System/Build.ini"), b"old\n").unwrap();
    std::fs::write(target.join("System/Unrelated.ini"), b"keep\n").unwrap();

    let zip_path = write_zip(
        &dir.path().join("patch.zip"),
        &[
            ("System/Build.ini", b"Label=v3374\n"),
            ("Maps/DM-Rankin.ut2", b"rank in"),
        ],
    );

    assert_eq!(extract_zip(&zip_path, &target).unwrap(), 2);
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n"
    );
    assert_eq!(
        std::fs::read(target.join("System/Unrelated.ini")).unwrap(),
        b"keep\n"
    );
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in"
    );
}

/// The tar.bz2 patch (Linux) goes through the same guard.
#[cfg(unix)]
#[test]
fn tar_bz2_extraction_does_not_follow_a_symlinked_parent_out_of_the_target() {
    let dir = tempfile::tempdir().unwrap();
    let outside = tempfile::tempdir().unwrap();
    let target = dir.path().join("game");
    std::fs::create_dir_all(&target).unwrap();
    symlink(outside.path(), &target.join("System"));

    let archive = dir.path().join("patch.tar.bz2");
    write_tar_bz2(&archive, &[("./System/Build.ini", b"Label=v3374\n")]);

    assert!(matches!(
        extract_tar_bz2(&archive, &target),
        Err(ArchiveError::UnsafeEntry { .. })
    ));
    assert!(!outside.path().join("Build.ini").exists());
    assert_eq!(std::fs::read_dir(outside.path()).unwrap().count(), 0);
}

/// The ISO payload (Maps/, Textures/, …) is written through the same guard.
#[cfg(unix)]
#[test]
fn iso_extraction_does_not_follow_a_symlinked_parent_out_of_the_target() {
    let dir = tempfile::tempdir().unwrap();
    let outside = tempfile::tempdir().unwrap();
    let source = dir.path().join("disc");
    std::fs::create_dir_all(source.join("UT2004/System")).unwrap();
    std::fs::create_dir_all(source.join("UT2004/Maps")).unwrap();
    std::fs::write(source.join("UT2004/System/Build.ini"), b"Label=3369\n").unwrap();
    std::fs::write(source.join("UT2004/Maps/DM-Rankin.ut2"), b"rank in").unwrap();

    let iso = dir.path().join("ut2004.iso");
    libmkiso::write_iso9660_with_options(
        &source,
        &iso,
        &libmkiso::IsoOptions {
            joliet: true,
            filename_policy: libmkiso::FilenamePolicy::Mangle,
            ..libmkiso::IsoOptions::default()
        },
    )
    .unwrap();

    let target = dir.path().join("game");
    std::fs::create_dir_all(&target).unwrap();
    symlink(outside.path(), &target.join("Maps"));

    assert!(matches!(
        extract_iso(&iso, &target),
        Err(ArchiveError::UnsafeEntry { .. })
    ));
    assert!(!outside.path().join("DM-Rankin.ut2").exists());
    assert_eq!(std::fs::read_dir(outside.path()).unwrap().count(), 0);
}

/// `safe_join` is lexical, but it must not turn a Windows drive prefix into a
/// harmless Unix file name.
#[test]
fn safe_join_rejects_windows_drive_prefixes() {
    let target = Path::new("/tmp/game");

    for hostile in [
        r"C:\Windows\x",
        "C:/Windows/x",
        r"c:\Windows\x",
        r"D:\UT2004\System\Build.ini",
    ] {
        assert!(
            matches!(
                safe_join(target, hostile),
                Err(ArchiveError::UnsafeEntry { .. })
            ),
            "{hostile} must be rejected"
        );
    }

    assert_eq!(
        safe_join(target, "System/Build.ini").unwrap(),
        target.join("System/Build.ini")
    );
}
