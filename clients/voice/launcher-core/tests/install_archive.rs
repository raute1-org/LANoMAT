//! Archive hardening: ISO layout planning and extraction, zip/tar.bz2 patch
//! extraction with traversal, symlink and zip-bomb guards.

// Some helpers and imports are only used by the `#[cfg(unix)]` tests below: on Windows they would be
// dead code, and `-D warnings` in `ci/build.sh` (cargo xwin clippy) must stay green.
#![cfg_attr(windows, allow(dead_code, unused_imports))]

use std::fs::File;
use std::io::Write;
use std::path::{Path, PathBuf};

use launcher_core::archive::{
    extract_iso, extract_tar_bz2, extract_tar_bz2_with_limits, extract_zip,
    extract_zip_with_limits, plan_iso_extraction, safe_relative, ArchiveError, ArchiveLimits,
    IsoEntryDesc,
};

fn desc(index: usize, name: &str, directory: bool) -> IsoEntryDesc {
    IsoEntryDesc {
        index,
        name: name.to_string(),
        directory,
    }
}

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

#[test]
fn planner_strips_one_wrapper_skips_system_and_keeps_joliet_case() {
    let entries = vec![
        desc(0, "", true),
        desc(1, "UT2004", true),
        desc(2, "UT2004/System", true),
        desc(3, "UT2004/System/UT2004.exe", false),
        desc(4, "UT2004/Maps", true),
        desc(5, "UT2004/Maps/DM-Rankin.ut2", false),
        desc(6, "UT2004/Textures/Arborea.utx", false),
    ];

    let plan = plan_iso_extraction(&entries).unwrap();

    assert_eq!(plan.wrapper.as_deref(), Some("UT2004"));
    assert_eq!(plan.files, 2);
    assert_eq!(
        plan.entries
            .iter()
            .map(|entry| entry.relative.as_str())
            .collect::<Vec<_>>(),
        vec!["Maps", "Maps/DM-Rankin.ut2", "Textures/Arborea.utx"]
    );
    assert!(plan
        .entries
        .iter()
        .all(|entry| !entry.relative.starts_with("System")));
}

#[test]
fn planner_keeps_a_flat_layout_and_skips_the_root_system_tree() {
    let entries = vec![
        desc(0, "", true),
        desc(1, "System", true),
        desc(2, "System/UT2004.exe", false),
        desc(3, "Maps", true),
        desc(4, "Maps/DM-Rankin.ut2", false),
    ];

    let plan = plan_iso_extraction(&entries).unwrap();

    assert_eq!(plan.wrapper, None);
    assert_eq!(plan.files, 1);
    assert_eq!(plan.entries.len(), 2);
    assert_eq!(plan.entries[1].relative, "Maps/DM-Rankin.ut2");
}

#[test]
fn planner_rejects_parent_and_absolute_entries() {
    for hostile in [
        "../etc/passwd",
        "UT2004/../../etc/passwd",
        "/etc/passwd",
        "C:\\Windows\\x",
    ] {
        let entries = vec![desc(0, hostile, false)];
        assert!(
            matches!(
                plan_iso_extraction(&entries),
                Err(ArchiveError::UnsafeEntry { .. })
            ),
            "{hostile} must be rejected"
        );
    }
}

#[test]
fn planner_requires_at_least_one_payload_file() {
    let entries = vec![
        desc(0, "", true),
        desc(1, "UT2004/System", true),
        desc(2, "UT2004/System/Build.ini", false),
    ];

    assert!(matches!(
        plan_iso_extraction(&entries),
        Err(ArchiveError::IsoLayout(_))
    ));
}

#[test]
fn planner_ignores_the_iso_root_entry() {
    let entries = vec![desc(0, "", true), desc(1, "Maps/DM-Rankin.ut2", false)];
    let plan = plan_iso_extraction(&entries).unwrap();

    assert_eq!(plan.files, 1);
    assert_eq!(plan.entries[0].index, 1);
}

#[test]
fn safe_relative_accepts_root_entries_and_rejects_escapes() {
    assert_eq!(safe_relative("./").unwrap(), None);
    assert_eq!(safe_relative(".").unwrap(), None);
    assert_eq!(
        safe_relative("./System/Build.ini").unwrap(),
        Some("System/Build.ini".to_string())
    );
    assert!(safe_relative("../outside").is_err());
    assert!(safe_relative("/absolute").is_err());
    assert!(safe_relative("C:\\Windows\\x").is_err());
}

#[test]
fn zip_extraction_skips_symlinks_and_special_entries() {
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
        zip.add_symlink("System/ut2004", "ut2004-bin-amd64", options)
            .unwrap();
        zip.finish().unwrap();
    }

    assert_eq!(extract_zip(&zip_path, &target).unwrap(), 1);
    assert!(target.join("System/Build.ini").is_file());
    assert!(!target.join("System/ut2004").exists());
}

#[test]
fn zip_extraction_rejects_traversal_and_absolute_names() {
    for hostile in ["../escape.txt", "/absolute.txt", "System/../../escape.txt"] {
        let dir = tempfile::tempdir().unwrap();
        let zip_path = write_zip(&dir.path().join("hostile.zip"), &[(hostile, b"x")]);
        let target = dir.path().join("game");
        std::fs::create_dir_all(&target).unwrap();

        assert!(matches!(
            extract_zip(&zip_path, &target),
            Err(ArchiveError::UnsafeEntry { .. })
        ));
        assert!(!dir.path().join("escape.txt").exists());
    }
}

#[test]
fn zip_extraction_enforces_the_entry_count() {
    let dir = tempfile::tempdir().unwrap();
    let zip_path = write_zip(
        &dir.path().join("many.zip"),
        &[("System/a.ini", b"a"), ("System/b.ini", b"b")],
    );
    let target = dir.path().join("game");
    let limits = ArchiveLimits {
        max_entries: 1,
        ..ArchiveLimits::default()
    };

    assert!(matches!(
        extract_zip_with_limits(&zip_path, &target, limits),
        Err(ArchiveError::TooManyEntries { limit: 1 })
    ));
}

#[test]
fn zip_extraction_enforces_the_uncompressed_size() {
    let dir = tempfile::tempdir().unwrap();
    let zip_path = write_zip(
        &dir.path().join("bomb.zip"),
        &[("System/zeros.bin", &[0u8; 4096])],
    );
    let target = dir.path().join("game");
    let limits = ArchiveLimits {
        max_uncompressed_bytes: 16,
        ..ArchiveLimits::default()
    };

    assert!(matches!(
        extract_zip_with_limits(&zip_path, &target, limits),
        Err(ArchiveError::TooLarge { limit: 16 })
    ));
}

fn write_tar_bz2(
    path: &Path,
    build: impl FnOnce(&mut tar::Builder<bzip2::write::BzEncoder<File>>),
) {
    let file = File::create(path).unwrap();
    let encoder = bzip2::write::BzEncoder::new(file, bzip2::Compression::best());
    let mut builder = tar::Builder::new(encoder);
    build(&mut builder);
    builder.into_inner().unwrap().finish().unwrap();
}

fn tar_file(builder: &mut tar::Builder<bzip2::write::BzEncoder<File>>, name: &str, body: &[u8]) {
    let mut header = tar::Header::new_gnu();
    header.set_size(body.len() as u64);
    header.set_mode(0o644);
    header.set_cksum();
    builder.append_data(&mut header, name, body).unwrap();
}

fn tar_dir(builder: &mut tar::Builder<bzip2::write::BzEncoder<File>>, name: &str) {
    let mut header = tar::Header::new_gnu();
    header.set_entry_type(tar::EntryType::Directory);
    header.set_size(0);
    header.set_mode(0o755);
    header.set_cksum();
    builder
        .append_data(&mut header, name, std::io::empty())
        .unwrap();
}

fn tar_link(
    builder: &mut tar::Builder<bzip2::write::BzEncoder<File>>,
    kind: tar::EntryType,
    name: &str,
    target: &str,
) {
    let mut header = tar::Header::new_gnu();
    header.set_entry_type(kind);
    header.set_size(0);
    header.set_mode(0o777);
    header.set_cksum();
    builder.append_link(&mut header, name, target).unwrap();
}

/// Write an entry with raw name bytes so a hostile `../` path survives the
/// tar crate's own authoring validation (real-world archives are not written
/// by this crate).
fn tar_raw_file(
    builder: &mut tar::Builder<bzip2::write::BzEncoder<File>>,
    name: &[u8],
    body: &[u8],
) {
    let mut header = tar::Header::new_gnu();
    header.set_size(body.len() as u64);
    header.set_mode(0o644);
    let old = header.as_old_mut();
    old.name[..name.len()].copy_from_slice(name);
    header.set_cksum();
    builder.append(&header, body).unwrap();
}

#[cfg(unix)]
#[test]
fn tar_bz2_accepts_a_dot_root_entry_and_materialises_symlinks() {
    // Regression: `tar -cjf patch.tar.bz2 .` emits a `./` root entry. It must
    // not abort the patch; `..` must still be rejected. The OldUnreal patch
    // stores `ut2004-bin-amd64 -> UT2004`, so a symlink is materialised while
    // hard links are skipped.
    let dir = tempfile::tempdir().unwrap();
    let archive = dir.path().join("patch.tar.bz2");

    write_tar_bz2(&archive, |builder| {
        tar_dir(builder, "./");
        tar_dir(builder, "./System");
        tar_file(builder, "./System/Build.ini", b"Label=v3374\n");
        tar_file(builder, "./System/Manifest.ini", b"manifest\n");
        tar_link(
            builder,
            tar::EntryType::Symlink,
            "./System/ut2004",
            "ut2004-bin-amd64",
        );
        tar_link(
            builder,
            tar::EntryType::Link,
            "./System/UT2004.exe",
            "./System/ut2004-bin-amd64",
        );
    });

    let target = dir.path().join("game");
    assert_eq!(extract_tar_bz2(&archive, &target).unwrap(), 3);
    assert_eq!(
        std::fs::read(target.join("System/Build.ini")).unwrap(),
        b"Label=v3374\n"
    );

    let link = target.join("System/ut2004");
    let metadata = std::fs::symlink_metadata(&link).unwrap();
    assert!(
        metadata.file_type().is_symlink(),
        "the relative symlink must be materialised"
    );
    assert_eq!(
        std::fs::read_link(&link).unwrap(),
        std::path::Path::new("ut2004-bin-amd64")
    );
    assert!(
        !target.join("System/UT2004.exe").exists(),
        "hard links are still skipped"
    );
}

#[test]
fn tar_bz2_rejects_parent_entries() {
    let dir = tempfile::tempdir().unwrap();
    let archive = dir.path().join("hostile.tar.bz2");

    write_tar_bz2(&archive, |builder| {
        tar_raw_file(builder, b"./../escape.txt", b"x");
    });

    assert!(matches!(
        extract_tar_bz2(&archive, &dir.path().join("game")),
        Err(ArchiveError::UnsafeEntry { .. })
    ));
    assert!(!dir.path().join("escape.txt").exists());
}

#[test]
fn tar_bz2_enforces_entry_and_size_limits() {
    let dir = tempfile::tempdir().unwrap();
    let archive = dir.path().join("limits.tar.bz2");

    write_tar_bz2(&archive, |builder| {
        tar_file(builder, "./System/Build.ini", &[0u8; 1024]);
    });

    let too_small = ArchiveLimits {
        max_uncompressed_bytes: 16,
        ..ArchiveLimits::default()
    };
    assert!(matches!(
        extract_tar_bz2_with_limits(&archive, &dir.path().join("game"), too_small),
        Err(ArchiveError::TooLarge { limit: 16 })
    ));

    let too_few_entries = ArchiveLimits {
        max_entries: 1,
        ..ArchiveLimits::default()
    };
    let dir2 = tempfile::tempdir().unwrap();
    let archive2 = dir2.path().join("limits.tar.bz2");
    write_tar_bz2(&archive2, |builder| {
        tar_file(builder, "./a.ini", b"a");
        tar_file(builder, "./b.ini", b"b");
    });
    assert!(matches!(
        extract_tar_bz2_with_limits(&archive2, &dir2.path().join("game"), too_few_entries),
        Err(ArchiveError::TooManyEntries { limit: 1 })
    ));
}

/// A real, tiny ISO9660/Joliet image written by libmkiso's native writer. It
/// proves the reader integration and the Joliet name preservation, not just
/// the pure planner.
#[test]
fn iso_fixture_extracts_the_payload_and_keeps_joliet_names() {
    let dir = tempfile::tempdir().unwrap();
    let source = dir.path().join("disc");
    std::fs::create_dir_all(source.join("UT2004/System")).unwrap();
    std::fs::create_dir_all(source.join("UT2004/Maps")).unwrap();
    std::fs::create_dir_all(source.join("UT2004/Textures")).unwrap();
    std::fs::write(
        source.join("UT2004/System/ut2004-bin-amd64"),
        b"#!/bin/sh\n",
    )
    .unwrap();
    std::fs::write(source.join("UT2004/System/Build.ini"), b"Label=3369\n").unwrap();
    std::fs::write(source.join("UT2004/Maps/DM-Rankin.ut2"), b"rank in").unwrap();
    std::fs::write(source.join("UT2004/Textures/Arborea.utx"), b"texture").unwrap();

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

    assert_eq!(extract_iso(&iso, &target).unwrap(), 2);
    assert_eq!(
        std::fs::read(target.join("Maps/DM-Rankin.ut2")).unwrap(),
        b"rank in"
    );
    assert_eq!(
        std::fs::read(target.join("Textures/Arborea.utx")).unwrap(),
        b"texture"
    );
    assert!(
        !target.join("System").exists(),
        "System/ comes from the patch"
    );
    assert!(
        !target.join("UT2004").exists(),
        "the wrapper folder is stripped"
    );
}
