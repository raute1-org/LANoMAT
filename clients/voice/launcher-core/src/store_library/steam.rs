//! Steam source: install dir from the registry, libraries from
//! `libraryfolders.vdf`, games from `appmanifest_<id>.acf`.

use std::path::{Path, PathBuf};

use super::vdf::{self, Node};
use super::{FileSystem, Hive, LocalGame, LocalStatus, Registry, SourceReport, Store};

fn norm(p: &str) -> String {
    p.replace('/', "\\").trim_end_matches('\\').to_lowercase()
}

fn steam_root(reg: &dyn Registry, fs: &dyn FileSystem) -> Option<PathBuf> {
    let candidates = [
        (Hive::CurrentUser, "Software\\Valve\\Steam", "SteamPath"),
        (
            Hive::LocalMachine,
            "SOFTWARE\\WOW6432Node\\Valve\\Steam",
            "InstallPath",
        ),
        (Hive::LocalMachine, "SOFTWARE\\Valve\\Steam", "InstallPath"),
    ];
    candidates
        .iter()
        .filter_map(|(h, path, name)| reg.string(*h, path, name))
        .map(PathBuf::from)
        .find(|p| fs.is_dir(p))
}

fn library_paths(root: &Path, fs: &dyn FileSystem) -> Vec<PathBuf> {
    let mut libs = vec![root.to_path_buf()];
    let vdf_path = root.join("steamapps").join("libraryfolders.vdf");
    if let Some(node) = fs.read_to_string(&vdf_path).as_deref().and_then(vdf::parse) {
        if let Some(lf) = node.get("libraryfolders") {
            for (_, child) in lf.children() {
                if let Some(path) = child.get("path").and_then(Node::str) {
                    libs.push(PathBuf::from(path));
                }
            }
        }
    }
    let mut seen = Vec::new();
    libs.retain(|l| {
        let n = norm(&l.to_string_lossy());
        if seen.contains(&n) {
            false
        } else {
            seen.push(n);
            true
        }
    });
    libs
}

fn app_id_from_file_name(name: &str) -> Option<Option<String>> {
    let id = name.strip_prefix("appmanifest_")?.strip_suffix(".acf")?;
    let valid = (1..=10).contains(&id.len()) && id.bytes().all(|b| b.is_ascii_digit());
    Some(valid.then(|| id.to_string()))
}

/// Steam apps that are tools, not games: the redistributables every install carries, SteamVR
/// and the compatibility layers.
const TOOL_APP_IDS: &[&str] = &[
    "228980",  // Steamworks Common Redistributables
    "250820",  // SteamVR
    "1070560", // Steam Linux Runtime
    "1391110", // Steam Linux Runtime (soldier)
    "1493710", // Proton Experimental
    "1628350", // Steam Linux Runtime (sniper)
];

fn safe_dir_name(s: &str) -> bool {
    !s.is_empty() && s != "." && s != ".." && !s.contains(['/', '\\', ':'])
}

fn parse_manifest(
    text: &str,
    id: &str,
    steamapps: &Path,
    fs: &dyn FileSystem,
) -> Option<LocalGame> {
    let root = vdf::parse(text)?;
    let state = root.get("AppState")?;
    let title = state.get("name").and_then(Node::str)?.trim();
    let dir = state.get("installdir").and_then(Node::str)?;
    if title.is_empty() || !safe_dir_name(dir) {
        return None;
    }
    let flags: Option<u64> = state
        .get("StateFlags")
        .and_then(Node::str)
        .and_then(|f| f.trim().parse().ok());
    let install = steamapps.join("common").join(dir);
    let status = if !fs.is_dir(&install) {
        LocalStatus::Broken
    } else {
        match flags {
            // No flags but an install folder: Steam will sort it out when the game starts.
            None => LocalStatus::Ready,
            // Fully installed (4) and no download running (1024 update running, 256 started):
            // "update required" (2) alone does not keep the game from starting, Steam updates
            // first. Anything else is an install or update in progress.
            Some(f) if f & 4 != 0 && f & (1024 | 256) == 0 => LocalStatus::Ready,
            Some(_) => LocalStatus::Updating,
        }
    };
    Some(LocalGame {
        store: Store::Steam,
        store_id: id.to_string(),
        title: title.to_string(),
        install_path: Some(install),
        status,
        exe: None,
        epic: None,
    })
}

pub fn read(reg: &dyn Registry, fs: &dyn FileSystem) -> (Vec<LocalGame>, SourceReport) {
    let mut report = SourceReport {
        store: Store::Steam,
        present: false,
        found: 0,
        skipped: 0,
    };
    let Some(root) = steam_root(reg, fs) else {
        return (Vec::new(), report);
    };
    report.present = true;

    let mut games: Vec<LocalGame> = Vec::new();
    for lib in library_paths(&root, fs) {
        let steamapps = lib.join("steamapps");
        if !fs.is_dir(&steamapps) {
            continue;
        }
        let mut files = fs.list_dir(&steamapps);
        files.sort();
        for file in files {
            let Some(name) = file.file_name().and_then(|n| n.to_str()) else {
                continue;
            };
            let Some(id) = app_id_from_file_name(name) else {
                continue;
            };
            if id.as_deref().is_some_and(|id| TOOL_APP_IDS.contains(&id)) {
                continue;
            }
            let game = id.and_then(|id| {
                let text = fs.read_to_string(&file)?;
                parse_manifest(&text, &id, &steamapps, fs)
            });
            let Some(game) = game else {
                report.skipped += 1;
                continue;
            };
            match games.iter_mut().find(|g| g.store_id == game.store_id) {
                Some(existing) => {
                    if existing.status == LocalStatus::Broken && game.status != LocalStatus::Broken
                    {
                        *existing = game;
                    }
                }
                None => games.push(game),
            }
        }
    }
    report.found = games.len();
    (games, report)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store_library::testing::{MapFs, MapRegistry};

    const ROOT: &str = "C:/Program Files (x86)/Steam";

    fn reg() -> MapRegistry {
        MapRegistry::new().with_value(
            Hive::CurrentUser,
            "Software\\Valve\\Steam",
            "SteamPath",
            ROOT,
        )
    }

    fn acf(name: &str, dir: &str, flags: &str) -> String {
        format!(
            "\"AppState\"\n{{\n\t\"appid\"\t\t\"0\"\n\t\"name\"\t\t\"{name}\"\n\t\"StateFlags\"\t\t\"{flags}\"\n\t\"installdir\"\t\t\"{dir}\"\n}}\n"
        )
    }

    fn libs(paths: &[&str]) -> String {
        let mut s = String::from("\"libraryfolders\"\n{\n");
        for (i, p) in paths.iter().enumerate() {
            s += &format!(
                "\t\"{i}\"\n\t{{\n\t\t\"path\"\t\t\"{}\"\n\t}}\n",
                p.replace('\\', "\\\\")
            );
        }
        s + "}\n"
    }

    fn apps(lib: &str) -> PathBuf {
        PathBuf::from(lib).join("steamapps")
    }

    fn base_fs() -> MapFs {
        MapFs::new()
            .with_dir(PathBuf::from(ROOT))
            .with_dir(apps(ROOT))
    }

    #[test]
    fn steam_finds_install_from_hkcu() {
        let fs = base_fs();
        let (games, rep) = read(&reg(), &fs);
        assert!(rep.present);
        assert_eq!(rep.store, Store::Steam);
        assert!(games.is_empty());
    }

    #[test]
    fn steam_falls_back_to_hklm_32bit() {
        let r = MapRegistry::new().with_value(
            Hive::LocalMachine,
            "SOFTWARE\\WOW6432Node\\Valve\\Steam",
            "InstallPath",
            ROOT,
        );
        let fs = base_fs()
            .with_file(
                apps(ROOT).join("appmanifest_10.acf"),
                &acf("Counter-Strike", "Half-Life", "4"),
            )
            .with_dir(apps(ROOT).join("common").join("Half-Life"));
        let (games, rep) = read(&r, &fs);
        assert!(rep.present);
        assert_eq!(games.len(), 1);
        assert_eq!(games[0].key(), "steam:10");
        assert_eq!(
            games[0].install_path,
            Some(apps(ROOT).join("common").join("Half-Life"))
        );
    }

    #[test]
    fn steam_reads_two_libraries() {
        let d = "D:\\SteamLibrary";
        let fs = base_fs()
            .with_file(
                apps(ROOT).join("libraryfolders.vdf"),
                &libs(&["C:\\Steam", d]),
            )
            .with_dir(apps("C:\\Steam"))
            .with_dir(apps(d))
            .with_file(
                apps("C:\\Steam").join("appmanifest_10.acf"),
                &acf("Counter-Strike", "Half-Life", "4"),
            )
            .with_dir(apps("C:\\Steam").join("common").join("Half-Life"))
            .with_file(
                apps(d).join("appmanifest_620.acf"),
                &acf("Portal 2", "Portal 2", "6"),
            )
            .with_dir(apps(d).join("common").join("Portal 2"));
        let (games, rep) = read(&reg(), &fs);
        assert_eq!(games.len(), 2);
        assert_eq!((rep.found, rep.skipped), (2, 0));
        let g10 = games.iter().find(|g| g.key() == "steam:10").unwrap();
        assert_eq!(g10.status, LocalStatus::Ready);
        assert_eq!(g10.title, "Counter-Strike");
        assert_eq!(
            g10.install_path,
            Some(
                PathBuf::from("C:\\Steam")
                    .join("steamapps")
                    .join("common")
                    .join("Half-Life")
            )
        );
        // Flag 6: fully installed, update required, nothing downloading: still playable.
        let g620 = games.iter().find(|g| g.key() == "steam:620").unwrap();
        assert_eq!(g620.status, LocalStatus::Ready);
    }

    fn status_of(flags: Option<&str>) -> LocalStatus {
        let text = match flags {
            Some(f) => acf("Portal 2", "Portal 2", f),
            None => "\"AppState\"{\"name\"\"Portal 2\"\"installdir\"\"Portal 2\"}".to_string(),
        };
        let fs = base_fs()
            .with_file(apps(ROOT).join("appmanifest_620.acf"), &text)
            .with_dir(apps(ROOT).join("common").join("Portal 2"));
        read(&reg(), &fs).0[0].status
    }

    #[test]
    fn steam_status_follows_the_state_flags() {
        assert_eq!(status_of(Some("4")), LocalStatus::Ready);
        // Update required but no download running.
        assert_eq!(status_of(Some("6")), LocalStatus::Ready);
        // An active download (1024) or a started one (256).
        assert_eq!(status_of(Some("1030")), LocalStatus::Updating);
        assert_eq!(status_of(Some("260")), LocalStatus::Updating);
        // Not installed (yet).
        assert_eq!(status_of(Some("2")), LocalStatus::Updating);
        // No StateFlags at all, but the install folder exists.
        assert_eq!(status_of(None), LocalStatus::Ready);
    }

    #[test]
    fn steam_skips_tool_app_ids() {
        let fs = base_fs()
            .with_file(
                apps(ROOT).join("appmanifest_228980.acf"),
                &acf(
                    "Steamworks Common Redistributables",
                    "Steamworks Shared",
                    "4",
                ),
            )
            .with_dir(apps(ROOT).join("common").join("Steamworks Shared"))
            .with_file(
                apps(ROOT).join("appmanifest_10.acf"),
                &acf("Counter-Strike", "Half-Life", "4"),
            )
            .with_dir(apps(ROOT).join("common").join("Half-Life"));
        let (games, rep) = read(&reg(), &fs);
        assert_eq!(games.len(), 1);
        assert_eq!(games[0].key(), "steam:10");
        assert_eq!((rep.found, rep.skipped), (1, 0));
    }

    #[test]
    fn steam_marks_missing_install_dir_broken() {
        let fs = base_fs().with_file(
            apps(ROOT).join("appmanifest_10.acf"),
            &acf("Counter-Strike", "Half-Life", "4"),
        );
        let (games, _) = read(&reg(), &fs);
        assert_eq!(games[0].status, LocalStatus::Broken);
    }

    #[test]
    fn steam_skips_missing_library() {
        let fs = base_fs()
            .with_file(
                apps(ROOT).join("libraryfolders.vdf"),
                &libs(&["E:\\Gone", "D:\\SteamLibrary"]),
            )
            .with_dir(apps("D:\\SteamLibrary"))
            .with_file(
                apps("D:\\SteamLibrary").join("appmanifest_620.acf"),
                &acf("Portal 2", "Portal 2", "4"),
            );
        let (games, rep) = read(&reg(), &fs);
        assert_eq!(games.len(), 1);
        assert_eq!(games[0].key(), "steam:620");
        assert!(rep.present);
    }

    #[test]
    fn steam_dedupes_app_ids() {
        let d = "D:\\SteamLibrary";
        let fs = base_fs()
            .with_file(apps(ROOT).join("libraryfolders.vdf"), &libs(&[d]))
            .with_dir(apps(d))
            // stale manifest in the Steam root, no install dir there
            .with_file(
                apps(ROOT).join("appmanifest_10.acf"),
                &acf("Counter-Strike", "Half-Life", "4"),
            )
            .with_file(
                apps(d).join("appmanifest_10.acf"),
                &acf("Counter-Strike", "Half-Life", "4"),
            )
            .with_dir(apps(d).join("common").join("Half-Life"));
        let (games, rep) = read(&reg(), &fs);
        assert_eq!(games.len(), 1);
        assert_eq!(rep.found, 1);
        assert_eq!(games[0].status, LocalStatus::Ready);
        assert_eq!(
            games[0].install_path,
            Some(apps(d).join("common").join("Half-Life"))
        );
    }

    #[test]
    fn steam_skips_malformed_manifest() {
        let fs = base_fs()
            .with_file(
                apps(ROOT).join("appmanifest_1.acf"),
                "\"AppState\" { \"name\"",
            )
            .with_file(
                apps(ROOT).join("appmanifest_10.acf"),
                &acf("Counter-Strike", "Half-Life", "4"),
            );
        let (games, rep) = read(&reg(), &fs);
        assert_eq!(games.len(), 1);
        assert_eq!((rep.found, rep.skipped), (1, 1));
    }

    #[test]
    fn steam_absent_is_silent() {
        let (games, rep) = read(&MapRegistry::new(), &MapFs::new());
        assert!(games.is_empty());
        assert_eq!(
            rep,
            SourceReport {
                store: Store::Steam,
                present: false,
                found: 0,
                skipped: 0
            }
        );
    }
}
