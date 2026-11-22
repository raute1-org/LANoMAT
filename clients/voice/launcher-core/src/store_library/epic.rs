//! Epic source: `<ProgramData>\Epic\EpicGamesLauncher\Data\Manifests\*.item`.

use std::path::{Path, PathBuf};

use serde::Deserialize;

use super::{EpicIds, FileSystem, LocalGame, LocalStatus, SourceReport, Store};

#[derive(Deserialize)]
#[serde(rename_all = "PascalCase")]
struct Manifest {
    app_name: String,
    display_name: String,
    catalog_namespace: String,
    catalog_item_id: String,
    install_location: Option<String>,
    #[serde(default, rename = "bIsIncompleteInstall")]
    is_incomplete_install: bool,
    /// Set on DLC: the `AppName` of the game it belongs to.
    main_game_app_name: Option<String>,
    /// Unreal Engine plugins carry `plugins` or `plugins/engine`.
    app_categories: Option<Vec<String>>,
}

impl Manifest {
    /// DLC and engine plugins are no games of their own.
    fn is_not_a_game(&self) -> bool {
        let dlc = self
            .main_game_app_name
            .as_deref()
            .is_some_and(|main| !main.is_empty() && main != self.app_name);
        let plugin = self.app_categories.iter().flatten().any(|c| {
            let c = c.trim().to_ascii_lowercase();
            c == "plugins" || c == "plugins/engine"
        });
        dlc || plugin
    }
}

fn valid_app_name(s: &str) -> bool {
    (1..=128).contains(&s.len())
        && s.bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
}

/// `None`: malformed. `Some(None)`: well-formed, but DLC or a plugin.
fn parse(text: &str, fs: &dyn FileSystem) -> Option<Option<LocalGame>> {
    // Windows tools write a byte order mark now and then; serde_json refuses it.
    let text = text.strip_prefix('\u{feff}').unwrap_or(text);
    let m: Manifest = serde_json::from_str(text).ok()?;
    if m.is_not_a_game() {
        return Some(None);
    }
    let title = m.display_name.trim();
    if !valid_app_name(&m.app_name)
        || title.is_empty()
        || m.catalog_namespace.is_empty()
        || m.catalog_item_id.is_empty()
    {
        return None;
    }
    let install = m
        .install_location
        .filter(|s| !s.trim().is_empty())
        .map(PathBuf::from);
    let status = match &install {
        Some(p) if fs.is_dir(p) => {
            if m.is_incomplete_install {
                LocalStatus::Updating
            } else {
                LocalStatus::Ready
            }
        }
        _ => LocalStatus::Broken,
    };
    Some(Some(LocalGame {
        store: Store::Epic,
        store_id: m.app_name.clone(),
        title: title.to_string(),
        install_path: install,
        status,
        exe: None,
        epic: Some(EpicIds {
            namespace: m.catalog_namespace,
            catalog_item_id: m.catalog_item_id,
            app_name: m.app_name,
        }),
    }))
}

pub fn read(fs: &dyn FileSystem, program_data: &Path) -> (Vec<LocalGame>, SourceReport) {
    let mut report = SourceReport {
        store: Store::Epic,
        present: false,
        found: 0,
        skipped: 0,
    };
    let dir = program_data
        .join("Epic")
        .join("EpicGamesLauncher")
        .join("Data")
        .join("Manifests");
    if !fs.is_dir(&dir) {
        return (Vec::new(), report);
    }
    report.present = true;

    let mut files = fs.list_dir(&dir);
    files.sort();
    let mut games: Vec<LocalGame> = Vec::new();
    for file in files {
        if file.extension().and_then(|e| e.to_str()) != Some("item") {
            continue;
        }
        let Some(parsed) = fs.read_to_string(&file).and_then(|t| parse(&t, fs)) else {
            report.skipped += 1;
            continue;
        };
        let Some(game) = parsed else {
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
    use crate::store_library::testing::MapFs;

    const PD: &str = "C:/ProgramData";
    const FIXTURE: &str = include_str!("../../tests/fixtures/store_library/epic/Kinglet.item");

    fn manifests() -> PathBuf {
        Path::new(PD)
            .join("Epic")
            .join("EpicGamesLauncher")
            .join("Data")
            .join("Manifests")
    }

    fn item(app: &str, loc: &str, incomplete: bool) -> String {
        format!(
            r#"{{"bIsIncompleteInstall":{incomplete},"AppName":"{app}","DisplayName":"Example Game","CatalogNamespace":"ns1","CatalogItemId":"item1","InstallLocation":"{loc}"}}"#
        )
    }

    #[test]
    fn epic_reads_manifest() {
        let fs = MapFs::new()
            .with_file(manifests().join("A.item"), FIXTURE)
            .with_dir("D:\\Games\\Example Game");
        let (games, rep) = read(&fs, Path::new(PD));
        assert!(rep.present);
        assert_eq!((rep.found, rep.skipped), (1, 0));
        let g = &games[0];
        assert_eq!(g.key(), "epic:Kinglet");
        assert_eq!(g.title, "Example Game");
        assert_eq!(g.status, LocalStatus::Ready);
        assert_eq!(
            g.epic,
            Some(EpicIds {
                namespace: "ns1".into(),
                catalog_item_id: "item1".into(),
                app_name: "Kinglet".into()
            })
        );
    }

    #[test]
    fn epic_incomplete_install_is_updating() {
        let fs = MapFs::new()
            .with_file(manifests().join("A.item"), &item("Kinglet", "D:/G", true))
            .with_dir("D:/G");
        let (games, _) = read(&fs, Path::new(PD));
        assert_eq!(games[0].status, LocalStatus::Updating);
    }

    #[test]
    fn epic_missing_location_is_broken() {
        let fs = MapFs::new().with_file(
            manifests().join("A.item"),
            &item("Kinglet", "D:/Gone", false),
        );
        let (games, _) = read(&fs, Path::new(PD));
        assert_eq!(games[0].status, LocalStatus::Broken);
    }

    #[test]
    fn epic_skips_invalid_json_and_bad_appname() {
        let fs = MapFs::new()
            .with_file(manifests().join("A.item"), "{ nope")
            .with_file(manifests().join("B.item"), &item("a/b", "D:/G", false))
            .with_file(manifests().join("C.item"), &item("Kinglet", "D:/G", false))
            .with_file(manifests().join("readme.txt"), "ignored")
            .with_dir("D:/G");
        let (games, rep) = read(&fs, Path::new(PD));
        assert_eq!(games.len(), 1);
        assert_eq!((rep.found, rep.skipped), (1, 2));
    }

    #[test]
    fn epic_skips_dlc_and_engine_plugins() {
        let with = |app: &str, extra: &str| {
            format!(
                r#"{{"AppName":"{app}","DisplayName":"Example Game","CatalogNamespace":"ns1","CatalogItemId":"item1","InstallLocation":"D:/G"{extra}}}"#
            )
        };
        let fs = MapFs::new()
            .with_file(
                manifests().join("A.item"),
                &with(
                    "Kinglet",
                    r#","MainGameAppName":"Kinglet","AppCategories":["public","games"]"#,
                ),
            )
            .with_file(
                manifests().join("B.item"),
                &with("KingletDlc", r#","MainGameAppName":"Kinglet""#),
            )
            .with_file(
                manifests().join("C.item"),
                &with(
                    "SomePlugin",
                    r#","AppCategories":["plugins","plugins/engine"]"#,
                ),
            )
            .with_file(
                manifests().join("D.item"),
                &with("OtherPlugin", r#","AppCategories":["plugins/engine"]"#),
            )
            .with_dir("D:/G");
        let (games, rep) = read(&fs, Path::new(PD));
        assert_eq!(games.len(), 1);
        assert_eq!(games[0].key(), "epic:Kinglet");
        assert_eq!((rep.found, rep.skipped), (1, 0));
    }

    #[test]
    fn epic_reads_a_manifest_with_a_byte_order_mark() {
        let fs = MapFs::new()
            .with_file(
                manifests().join("A.item"),
                &format!("\u{feff}{}", item("Kinglet", "D:/G", false)),
            )
            .with_dir("D:/G");
        let (games, rep) = read(&fs, Path::new(PD));
        assert_eq!(games.len(), 1);
        assert_eq!(rep.skipped, 0);
    }

    #[test]
    fn epic_absent_is_silent() {
        let (games, rep) = read(&MapFs::new(), Path::new(PD));
        assert!(games.is_empty());
        assert_eq!(
            rep,
            SourceReport {
                store: Store::Epic,
                present: false,
                found: 0,
                skipped: 0
            }
        );
    }
}
