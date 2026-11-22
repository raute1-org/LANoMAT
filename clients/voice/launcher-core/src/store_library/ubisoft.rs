//! Ubisoft Connect source:
//! `HKLM\SOFTWARE\WOW6432Node\Ubisoft\Launcher\Installs\<id>` value `InstallDir`.

use std::path::PathBuf;

use super::{FileSystem, Hive, LocalGame, LocalStatus, Registry, SourceReport, Store};

const LAUNCHER: &str = "SOFTWARE\\WOW6432Node\\Ubisoft\\Launcher";
const INSTALLS: &str = "SOFTWARE\\WOW6432Node\\Ubisoft\\Launcher\\Installs";

fn valid_id(s: &str) -> bool {
    (1..=10).contains(&s.len()) && s.bytes().all(|b| b.is_ascii_digit())
}

fn parse(id: &str, reg: &dyn Registry, fs: &dyn FileSystem) -> Option<LocalGame> {
    if !valid_id(id) {
        return None;
    }
    let dir = reg
        .string(
            Hive::LocalMachine,
            &format!("{INSTALLS}\\{id}"),
            "InstallDir",
        )?
        .trim()
        .to_string();
    let title = dir
        .trim_end_matches(['/', '\\'])
        .rsplit(['/', '\\'])
        .next()
        .unwrap_or("")
        .trim()
        .to_string();
    if title.is_empty() || title.ends_with(':') {
        return None;
    }
    let install = PathBuf::from(&dir);
    let status = if fs.is_dir(&install) {
        LocalStatus::Ready
    } else {
        LocalStatus::Broken
    };
    Some(LocalGame {
        store: Store::Ubisoft,
        store_id: id.to_string(),
        title,
        install_path: Some(install),
        status,
        exe: None,
        epic: None,
    })
}

pub fn read(reg: &dyn Registry, fs: &dyn FileSystem) -> (Vec<LocalGame>, SourceReport) {
    let keys_present = !reg.subkeys(Hive::LocalMachine, INSTALLS).is_empty();
    let mut report = SourceReport {
        store: Store::Ubisoft,
        present: keys_present
            || reg
                .string(Hive::LocalMachine, LAUNCHER, "InstallDir")
                .is_some(),
        found: 0,
        skipped: 0,
    };
    let mut keys = reg.subkeys(Hive::LocalMachine, INSTALLS);
    keys.sort();
    let mut games: Vec<LocalGame> = Vec::new();
    for key in keys {
        let Some(game) = parse(&key, reg, fs) else {
            report.skipped += 1;
            continue;
        };
        games.push(game);
    }
    report.found = games.len();
    (games, report)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store_library::testing::{MapFs, MapRegistry};

    fn reg(ids: &[&str], dir: &str) -> MapRegistry {
        let mut r = MapRegistry::new().with_subkeys(Hive::LocalMachine, INSTALLS, ids);
        for id in ids {
            r = r.with_value(
                Hive::LocalMachine,
                &format!("{INSTALLS}\\{id}"),
                "InstallDir",
                dir,
            );
        }
        r
    }

    #[test]
    fn ubisoft_reads_install() {
        let dir = "C:/Games/Ubisoft/Example Siege/";
        let fs = MapFs::new().with_dir(PathBuf::from(dir));
        let (games, rep) = read(&reg(&["4"], dir), &fs);
        assert!(rep.present);
        assert_eq!((rep.found, rep.skipped), (1, 0));
        assert_eq!(games[0].key(), "ubisoft:4");
        assert_eq!(games[0].title, "Example Siege");
        assert_eq!(games[0].status, LocalStatus::Ready);
    }

    #[test]
    fn ubisoft_missing_dir_is_broken() {
        let (games, _) = read(&reg(&["4"], "C:/Gone/Example Siege"), &MapFs::new());
        assert_eq!(games[0].status, LocalStatus::Broken);
    }

    #[test]
    fn ubisoft_skips_non_numeric_key() {
        let (games, rep) = read(&reg(&["abc", "7"], "C:/Games/Foo"), &MapFs::new());
        assert_eq!(games.len(), 1);
        assert_eq!(games[0].key(), "ubisoft:7");
        assert_eq!((rep.found, rep.skipped), (1, 1));
    }

    #[test]
    fn ubisoft_absent_is_silent() {
        let (games, rep) = read(&MapRegistry::new(), &MapFs::new());
        assert!(games.is_empty());
        assert!(!rep.present);
    }
}
