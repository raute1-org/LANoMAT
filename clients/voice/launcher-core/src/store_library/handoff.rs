//! Validated store handoffs: turns a [`LocalGame`] into the one thing the
//! launcher may do with it: open a store URL, or start an `.exe` that lies
//! inside the game's own install folder. Nothing here runs a process.

use std::path::{Path, PathBuf};

use thiserror::Error;

use super::{FileSystem, LocalGame, LocalStatus, Store};

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Handoff {
    Url(String),
    Exe { program: PathBuf, cwd: PathBuf },
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Error)]
pub enum HandoffError {
    #[error("Dieses Spiel lässt sich nicht starten.")]
    InvalidId,
    #[error("Dieses Spiel lässt sich nicht starten.")]
    NotLaunchable,
    #[error("Die Startdatei liegt außerhalb des Spielordners.")]
    ExeOutsideInstall,
    #[error("Für diesen Shop gibt es das noch nicht.")]
    Unsupported,
}

/// Steam app ids, GOG game ids and Ubisoft install ids are plain digits.
fn digits(s: &str, max_len: usize) -> bool {
    (1..=max_len).contains(&s.len()) && s.bytes().all(|b| b.is_ascii_digit())
}

/// Epic `AppName`, namespace and catalog item id: `[A-Za-z0-9._-]{1,128}`.
fn epic_part(s: &str) -> bool {
    (1..=128).contains(&s.len())
        && s.bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
}

/// GOG may store its `exe` as an absolute Windows path (drive or UNC/root
/// prefix) or relative to the install folder. `Path::is_absolute` alone would
/// miss Windows paths on other platforms, so check the prefix by hand too.
fn is_absolute_windows(p: &str) -> bool {
    let bytes = p.as_bytes();
    Path::new(p).is_absolute()
        || p.starts_with('\\')
        || p.starts_with('/')
        || (bytes.len() >= 2 && bytes[0].is_ascii_alphabetic() && bytes[1] == b':')
}

fn has_exe_extension(p: &Path) -> bool {
    p.to_string_lossy().to_ascii_lowercase().ends_with(".exe")
}

/// Start the registry `exe` directly, but only if it resolves inside the
/// canonical install folder.
fn direct_exe(game: &LocalGame, fs: &dyn FileSystem) -> Result<Handoff, HandoffError> {
    let exe = game
        .exe
        .as_ref()
        .filter(|e| has_exe_extension(e))
        .ok_or(HandoffError::NotLaunchable)?;
    let install = game
        .install_path
        .as_ref()
        .ok_or(HandoffError::NotLaunchable)?;
    let exe_path = if is_absolute_windows(&exe.to_string_lossy()) {
        exe.clone()
    } else {
        install.join(exe)
    };
    let canon_install = fs
        .canonicalize(install)
        .ok_or(HandoffError::NotLaunchable)?;
    let canon_exe = fs
        .canonicalize(&exe_path)
        .ok_or(HandoffError::NotLaunchable)?;
    if !canon_exe.starts_with(&canon_install) {
        return Err(HandoffError::ExeOutsideInstall);
    }
    Ok(Handoff::Exe {
        program: canon_exe,
        cwd: install.clone(),
    })
}

/// How to start `game`. `galaxy_installed` decides GOG's route: through
/// GOG Galaxy when present, otherwise the validated direct `exe`.
pub fn launch(
    game: &LocalGame,
    galaxy_installed: bool,
    fs: &dyn FileSystem,
) -> Result<Handoff, HandoffError> {
    if game.status == LocalStatus::Broken {
        return Err(HandoffError::NotLaunchable);
    }
    match game.store {
        Store::Steam => {
            if !digits(&game.store_id, 10) {
                return Err(HandoffError::InvalidId);
            }
            Ok(Handoff::Url(format!("steam://rungameid/{}", game.store_id)))
        }
        Store::Epic => {
            let ids = game.epic.as_ref().ok_or(HandoffError::NotLaunchable)?;
            if ![&ids.namespace, &ids.catalog_item_id, &ids.app_name]
                .iter()
                .all(|part| epic_part(part))
            {
                return Err(HandoffError::InvalidId);
            }
            Ok(Handoff::Url(format!(
                "com.epicgames.launcher://apps/{}%3A{}%3A{}?action=launch&silent=true",
                ids.namespace, ids.catalog_item_id, ids.app_name
            )))
        }
        Store::Gog => {
            if !digits(&game.store_id, 20) {
                return Err(HandoffError::InvalidId);
            }
            if galaxy_installed {
                Ok(Handoff::Url(format!(
                    "goggalaxy://openGameView/{}",
                    game.store_id
                )))
            } else {
                direct_exe(game, fs)
            }
        }
        Store::Ubisoft => {
            if !digits(&game.store_id, 10) {
                return Err(HandoffError::InvalidId);
            }
            Ok(Handoff::Url(format!("uplay://launch/{}/0", game.store_id)))
        }
    }
}

/// The store page of a game (stage 1: Steam only).
pub fn store_page(store: Store, store_id: &str) -> Result<String, HandoffError> {
    match store {
        Store::Steam if digits(store_id, 10) => Ok(format!("steam://store/{store_id}")),
        Store::Steam => Err(HandoffError::InvalidId),
        Store::Epic | Store::Gog | Store::Ubisoft => Err(HandoffError::Unsupported),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store_library::testing::MapFs;
    use crate::store_library::{EpicIds, LocalGame, LocalStatus, Store};

    const GOG_INSTALL: &str = "D:/GOG Games/Example Classic";

    fn game(store: Store, store_id: &str) -> LocalGame {
        LocalGame {
            store,
            store_id: store_id.to_string(),
            title: "Example".to_string(),
            install_path: None,
            status: LocalStatus::Ready,
            exe: None,
            epic: None,
        }
    }

    fn epic(app_name: &str) -> LocalGame {
        LocalGame {
            epic: Some(EpicIds {
                namespace: "ns1".to_string(),
                catalog_item_id: "item1".to_string(),
                app_name: app_name.to_string(),
            }),
            ..game(Store::Epic, app_name)
        }
    }

    fn gog_with_exe(exe: Option<&str>) -> LocalGame {
        LocalGame {
            install_path: Some(PathBuf::from(GOG_INSTALL)),
            exe: exe.map(PathBuf::from),
            ..game(Store::Gog, "1207658924")
        }
    }

    /// File system where the install folder and the given exe resolve to themselves.
    fn fs_inside(exe: &str) -> MapFs {
        MapFs::new()
            .with_dir(GOG_INSTALL)
            .with_canonical(GOG_INSTALL, GOG_INSTALL)
            .with_canonical(exe, exe)
    }

    #[test]
    fn steam_handoff_is_rungameid() {
        assert_eq!(
            launch(&game(Store::Steam, "10"), false, &MapFs::new()),
            Ok(Handoff::Url("steam://rungameid/10".to_string()))
        );
    }

    #[test]
    fn steam_rejects_non_digit_id() {
        assert_eq!(
            launch(&game(Store::Steam, "10;calc"), false, &MapFs::new()),
            Err(HandoffError::InvalidId)
        );
    }

    #[test]
    fn epic_handoff_is_launcher_uri() {
        assert_eq!(
            launch(&epic("Kinglet"), false, &MapFs::new()),
            Ok(Handoff::Url(
                "com.epicgames.launcher://apps/ns1%3Aitem1%3AKinglet?action=launch&silent=true"
                    .to_string()
            ))
        );
    }

    #[test]
    fn epic_rejects_hostile_app_name() {
        for name in ["a&b", "a/b", "a?b"] {
            assert_eq!(
                launch(&epic(name), false, &MapFs::new()),
                Err(HandoffError::InvalidId),
                "app name {name}"
            );
        }
    }

    #[test]
    fn gog_with_galaxy_opens_galaxy_view() {
        assert_eq!(
            launch(&gog_with_exe(None), true, &MapFs::new()),
            Ok(Handoff::Url(
                "goggalaxy://openGameView/1207658924".to_string()
            ))
        );
    }

    #[test]
    fn gog_direct_absolute_exe_inside_install_runs() {
        let exe = "D:/GOG Games/Example Classic/game.exe";
        assert_eq!(
            launch(&gog_with_exe(Some(exe)), false, &fs_inside(exe)),
            Ok(Handoff::Exe {
                program: PathBuf::from(exe),
                cwd: PathBuf::from(GOG_INSTALL),
            })
        );
    }

    #[test]
    fn gog_direct_relative_exe_resolves_against_install() {
        let exe = "D:/GOG Games/Example Classic/game.exe";
        assert_eq!(
            launch(&gog_with_exe(Some("game.exe")), false, &fs_inside(exe)),
            Ok(Handoff::Exe {
                program: PathBuf::from(exe),
                cwd: PathBuf::from(GOG_INSTALL),
            })
        );
    }

    #[test]
    fn gog_exe_extension_is_case_insensitive() {
        let exe = "D:/GOG Games/Example Classic/GAME.EXE";
        assert!(matches!(
            launch(&gog_with_exe(Some(exe)), false, &fs_inside(exe)),
            Ok(Handoff::Exe { .. })
        ));
    }

    #[test]
    fn gog_exe_escaping_with_dotdot_is_refused() {
        let exe = "D:/GOG Games/Example Classic/../../Windows/System32/cmd.exe";
        let fs = MapFs::new()
            .with_dir(GOG_INSTALL)
            .with_canonical(GOG_INSTALL, GOG_INSTALL)
            .with_canonical(exe, "D:/Windows/System32/cmd.exe");
        assert_eq!(
            launch(&gog_with_exe(Some(exe)), false, &fs),
            Err(HandoffError::ExeOutsideInstall)
        );
    }

    #[test]
    fn gog_absolute_exe_outside_install_is_refused() {
        let exe = "E:/Elsewhere/evil.exe";
        assert_eq!(
            launch(&gog_with_exe(Some(exe)), false, &fs_inside(exe)),
            Err(HandoffError::ExeOutsideInstall)
        );
    }

    #[test]
    fn gog_exe_without_exe_extension_is_refused() {
        let exe = "D:/GOG Games/Example Classic/readme.txt";
        assert_eq!(
            launch(&gog_with_exe(Some(exe)), false, &fs_inside(exe)),
            Err(HandoffError::NotLaunchable)
        );
    }

    #[test]
    fn gog_missing_exe_without_galaxy_is_not_launchable() {
        assert_eq!(
            launch(&gog_with_exe(None), false, &MapFs::new()),
            Err(HandoffError::NotLaunchable)
        );
    }

    #[test]
    fn gog_unresolvable_path_is_not_launchable() {
        // Nothing canonicalizes: the install folder itself cannot be verified.
        let exe = "D:/GOG Games/Example Classic/game.exe";
        assert_eq!(
            launch(&gog_with_exe(Some(exe)), false, &MapFs::new()),
            Err(HandoffError::NotLaunchable)
        );
    }

    #[test]
    fn ubisoft_handoff_is_uplay_uri() {
        assert_eq!(
            launch(&game(Store::Ubisoft, "4"), false, &MapFs::new()),
            Ok(Handoff::Url("uplay://launch/4/0".to_string()))
        );
    }

    #[test]
    fn broken_game_is_not_launchable_in_any_store() {
        let broken = |g: LocalGame| LocalGame {
            status: LocalStatus::Broken,
            ..g
        };
        let cases = [
            broken(game(Store::Steam, "10")),
            broken(epic("Kinglet")),
            broken(game(Store::Ubisoft, "4")),
            broken(gog_with_exe(None)),
        ];
        for g in cases {
            assert_eq!(
                launch(&g, true, &MapFs::new()),
                Err(HandoffError::NotLaunchable),
                "{:?}",
                g.store
            );
        }
    }

    #[test]
    fn store_page_for_steam_only() {
        assert_eq!(
            store_page(Store::Steam, "620"),
            Ok("steam://store/620".to_string())
        );
        assert_eq!(
            store_page(Store::Epic, "Kinglet"),
            Err(HandoffError::Unsupported)
        );
        assert_eq!(
            store_page(Store::Gog, "1207658924"),
            Err(HandoffError::Unsupported)
        );
        assert_eq!(
            store_page(Store::Steam, "620;x"),
            Err(HandoffError::InvalidId)
        );
    }
}
