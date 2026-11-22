//! GOG source: `HKLM\SOFTWARE\WOW6432Node\GOG.com\Games\<id>`.

use std::path::PathBuf;

use super::{FileSystem, Hive, LocalGame, LocalStatus, Registry, SourceReport, Store};

const GAMES: &str = "SOFTWARE\\WOW6432Node\\GOG.com\\Games";
const GALAXY_PATHS: &str = "SOFTWARE\\WOW6432Node\\GOG.com\\GalaxyClient\\paths";

#[must_use]
pub fn galaxy_installed(reg: &dyn Registry) -> bool {
    reg.string(Hive::LocalMachine, GALAXY_PATHS, "client")
        .is_some_and(|c| !c.trim().is_empty())
}

fn valid_id(s: &str) -> bool {
    (1..=20).contains(&s.len()) && s.bytes().all(|b| b.is_ascii_digit())
}

fn parse(id: &str, reg: &dyn Registry, fs: &dyn FileSystem) -> Option<LocalGame> {
    let key = format!("{GAMES}\\{id}");
    let get = |name: &str| {
        reg.string(Hive::LocalMachine, &key, name)
            .map(|v| v.trim().to_string())
            .filter(|v| !v.is_empty())
    };
    let game_id = get("gameID")?;
    if !valid_id(&game_id) {
        return None;
    }
    let title = get("gameName")?;
    let install = get("path").map(PathBuf::from);
    let exe = get("exe").map(PathBuf::from);
    let status = match &install {
        Some(p) if fs.is_dir(p) => LocalStatus::Ready,
        _ => LocalStatus::Broken,
    };
    Some(LocalGame {
        store: Store::Gog,
        store_id: game_id,
        title,
        install_path: install,
        status,
        exe,
        epic: None,
    })
}

pub fn read(reg: &dyn Registry, fs: &dyn FileSystem) -> (Vec<LocalGame>, SourceReport) {
    let mut report = SourceReport {
        store: Store::Gog,
        present: galaxy_installed(reg),
        found: 0,
        skipped: 0,
    };
    let mut keys = reg.subkeys(Hive::LocalMachine, GAMES);
    keys.sort();
    let mut games: Vec<LocalGame> = Vec::new();
    for key in keys {
        let Some(game) = parse(&key, reg, fs) else {
            report.skipped += 1;
            continue;
        };
        if games.iter().any(|g| g.store_id == game.store_id) {
            continue;
        }
        games.push(game);
    }
    report.found = games.len();
    (games, report)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store_library::testing::{MapFs, MapRegistry};

    fn key(id: &str) -> String {
        format!("{GAMES}\\{id}")
    }

    fn reg_game(id: &str, path: Option<&str>) -> MapRegistry {
        let k = key(id);
        let mut r = MapRegistry::new()
            .with_subkeys(Hive::LocalMachine, GAMES, &[id])
            .with_value(Hive::LocalMachine, &k, "gameID", id)
            .with_value(Hive::LocalMachine, &k, "gameName", "Example Classic");
        if let Some(p) = path {
            r = r.with_value(Hive::LocalMachine, &k, "path", p).with_value(
                Hive::LocalMachine,
                &k,
                "exe",
                &format!("{p}\\classic.exe"),
            );
        }
        r
    }

    #[test]
    fn gog_reads_registry_game() {
        let p = "D:\\GOG Games\\Example Classic";
        let fs = MapFs::new().with_dir(p);
        let (games, rep) = read(&reg_game("1207658924", Some(p)), &fs);
        assert_eq!((rep.found, rep.skipped), (1, 0));
        let g = &games[0];
        assert_eq!(g.key(), "gog:1207658924");
        assert_eq!(g.title, "Example Classic");
        assert_eq!(g.status, LocalStatus::Ready);
        assert_eq!(g.install_path, Some(PathBuf::from(p)));
        assert_eq!(g.exe, Some(PathBuf::from(format!("{p}\\classic.exe"))));
    }

    #[test]
    fn gog_missing_path_is_broken() {
        let (games, _) = read(&reg_game("1207658924", None), &MapFs::new());
        assert_eq!(games[0].status, LocalStatus::Broken);
        let (games, _) = read(&reg_game("1207658924", Some("D:\\Gone")), &MapFs::new());
        assert_eq!(games[0].status, LocalStatus::Broken);
    }

    #[test]
    fn gog_skips_non_numeric_id() {
        let (games, rep) = read(&reg_game("abc", Some("D:\\X")), &MapFs::new());
        assert!(games.is_empty());
        assert_eq!(rep.skipped, 1);
    }

    #[test]
    fn gog_galaxy_detection() {
        let r = MapRegistry::new().with_value(
            Hive::LocalMachine,
            GALAXY_PATHS,
            "client",
            "C:\\Program Files (x86)\\GOG Galaxy",
        );
        assert!(galaxy_installed(&r));
        assert!(!galaxy_installed(&MapRegistry::new()));
    }
}
