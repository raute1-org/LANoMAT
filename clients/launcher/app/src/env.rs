//! What the launcher says about itself: version, commit, OS and config dir.
//!
//! The version comes from Cargo, the commit from the build script (an env var
//! of the release job, or `git rev-parse` when the build has a checkout). The
//! UI shows this in its footer so a support report can name the build.
//!
//! The same footer compares the running build with what the server publishes on
//! its public `/launcher` page (the Inertia page props `release`). That page is a
//! hint, not a contract: anything unexpected is "unknown" and the UI stays quiet.

use std::path::Path;

use serde::Serialize;

use crate::arcade::ArcadeMode;

pub const VERSION: &str = env!("CARGO_PKG_VERSION");
pub const COMMIT: &str = match option_env!("LANOMAT_LAUNCHER_COMMIT") {
    Some(commit) => commit,
    None => "unknown",
};

/// The longest page payload the parser accepts. The `/launcher` Inertia JSON is a
/// few KiB; the cap keeps an odd page from being scanned without bound.
const MAX_PAGE_JSON: usize = 1 << 20;

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct EnvironmentView {
    pub version: String,
    pub commit: String,
    pub os: String,
    pub arch: String,
    pub config_dir: String,
    /// This machine's name (`lan-pc`, …). A LAN party has a window open on
    /// every table, so each one has to say which terminal it is: every
    /// terminal pairs on its own and keeps its own installs.
    pub host: String,
    /// Started with `--arcade`/`LANOMAT_ARCADE=1`: fullscreen, large type and
    /// the QR-first connect screen. The UI adds its `arcade` class from this.
    pub arcade: bool,
    /// The fullscreen is locked (`--kiosk`): Escape stays inside fullscreen.
    pub kiosk: bool,
}

/// This machine's name: `HOSTNAME` on Linux/macOS, `COMPUTERNAME` on
/// Windows, `PC` when neither is set or empty. The same value labels the
/// device on the server's approved-devices list, the header and the footer.
pub fn hostname() -> String {
    std::env::var("HOSTNAME")
        .or_else(|_| std::env::var("COMPUTERNAME"))
        .ok()
        .map(|name| name.trim().to_string())
        .filter(|name| !name.is_empty())
        .unwrap_or_else(|| "PC".into())
}

/// The launcher build the server currently publishes, from `props.release`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PublishedRelease {
    pub version: String,
    /// Empty when the page names no safe commit id.
    pub commit: String,
}

/// What the UI needs for its footer: the running build plus, when the server
/// publishes a different one, the version to point at.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct UpdateView {
    pub current: String,
    pub commit: String,
    /// The published version, only when `available` is true.
    pub latest: Option<String>,
    pub available: bool,
    /// The server's `/launcher` page, when a server address was known.
    pub page: Option<String>,
}

/// Read `props.release` from the HTML of the public `/launcher` page.
///
/// Inertia v2 puts the page JSON in `<script data-page="app"
/// type="application/json">…</script>`; older adapters used a `data-page`
/// attribute on `#app` with HTML-escaped quotes. Both are accepted. Any parse
/// problem is `None`, never a guess.
pub fn release_from_page(html: &str) -> Option<PublishedRelease> {
    page_payloads(html)
        .into_iter()
        .filter(|json| json.len() <= MAX_PAGE_JSON)
        .find_map(|json| {
            let decoded = unescape_html(json);
            let page: serde_json::Value = serde_json::from_str(&decoded).ok()?;

            release_from_page_json(&page)
        })
}

/// The footer view for one check. `release == None` means the page was
/// unreachable or said nothing usable; `available` stays false then.
pub fn update_view(release: Option<PublishedRelease>, page: Option<String>) -> UpdateView {
    let commit = normalized_commit(COMMIT);
    let available = release
        .as_ref()
        .is_some_and(|release| !is_same_build(&commit, release));

    UpdateView {
        current: VERSION.to_string(),
        commit,
        latest: if available {
            release.map(|release| release.version)
        } else {
            None
        },
        available,
        page,
    }
}

fn release_from_page_json(page: &serde_json::Value) -> Option<PublishedRelease> {
    let release = page.get("props")?.get("release")?;

    if release.is_null() {
        return None;
    }

    let version = release.get("version")?.as_str()?.trim();

    if !is_safe_version(version) {
        return None;
    }

    let commit = release
        .get("commit")
        .and_then(serde_json::Value::as_str)
        .map(str::trim)
        .unwrap_or_default();

    Some(PublishedRelease {
        version: version.to_string(),
        commit: if is_safe_commit(commit) {
            commit.to_string()
        } else {
            String::new()
        },
    })
}

/// Every `data-page` payload in the document, script bodies first (Inertia v2)
/// and attributes second (legacy).
fn page_payloads(html: &str) -> Vec<&str> {
    let mut payloads = script_pages(html);

    payloads.extend(attribute_pages(html));

    payloads
}

fn script_pages(html: &str) -> Vec<&str> {
    let mut pages = Vec::new();
    let mut rest = html;

    while let Some(index) = rest.find("<script") {
        let after = &rest[index..];
        let Some(tag_end) = after.find('>') else {
            break;
        };

        if after[..tag_end].contains("data-page") {
            let body = &after[tag_end + 1..];

            if let Some(end) = body.find("</script>") {
                pages.push(&body[..end]);
            }
        }

        rest = &after[tag_end + 1..];
    }

    pages
}

fn attribute_pages(html: &str) -> Vec<&str> {
    const MARKER: &str = "data-page=\"";

    let mut pages = Vec::new();
    let mut rest = html;

    while let Some(index) = rest.find(MARKER) {
        let body = &rest[index + MARKER.len()..];
        let Some(end) = body.find('"') else {
            break;
        };

        pages.push(&body[..end]);
        rest = &body[end + 1..];
    }

    pages
}

/// Undo the entities `htmlspecialchars`/`json_encode` put into a `data-page`
/// attribute. `&amp;` goes last so an escaped entity is not decoded twice.
fn unescape_html(raw: &str) -> String {
    raw.replace("&quot;", "\"")
        .replace("&#34;", "\"")
        .replace("&#039;", "'")
        .replace("&#39;", "'")
        .replace("&#x27;", "'")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&amp;", "&")
}

/// The manifest's version rule (`LauncherBuilds::VERSION` in the server).
fn is_safe_version(value: &str) -> bool {
    let mut chars = value.chars();
    let Some(first) = chars.next() else {
        return false;
    };

    value.len() <= 64
        && first.is_ascii_alphanumeric()
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-' | '+'))
}

/// The manifest's commit rule (`LauncherBuilds::COMMIT` in the server).
fn is_safe_commit(value: &str) -> bool {
    (4..=64).contains(&value.len()) && value.chars().all(|c| c.is_ascii_alphanumeric())
}

fn normalized_commit(commit: &str) -> String {
    let trimmed = commit.trim();

    if trimmed.is_empty() {
        "unknown".to_string()
    } else {
        trimmed.to_string()
    }
}

/// Same build when the version strings match or the commits are the same id
/// (one may be abbreviated). The launcher's Cargo version is `0.1.0` while the
/// manifest carries the release version, so the commit is the stronger signal.
fn is_same_build(local_commit: &str, release: &PublishedRelease) -> bool {
    release.version == VERSION || same_commit(local_commit, &release.commit)
}

fn same_commit(local: &str, remote: &str) -> bool {
    fn usable(value: &str) -> bool {
        !value.is_empty() && value != "unknown"
    }

    let local = local.trim().to_ascii_lowercase();
    let remote = remote.trim().to_ascii_lowercase();

    usable(&local) && usable(&remote) && (local.starts_with(&remote) || remote.starts_with(&local))
}

pub fn view(config_dir: &Path, mode: ArcadeMode) -> EnvironmentView {
    EnvironmentView {
        version: VERSION.to_string(),
        commit: if COMMIT.is_empty() {
            "unknown".to_string()
        } else {
            COMMIT.to_string()
        },
        os: std::env::consts::OS.to_string(),
        arch: std::env::consts::ARCH.to_string(),
        config_dir: config_dir.to_string_lossy().to_string(),
        host: hostname(),
        arcade: mode.arcade,
        kiosk: mode.kiosk,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn describes_the_build_and_the_host() {
        let view = view(Path::new("/tmp/lanomat-launcher"), ArcadeMode::default());

        assert_eq!(view.version, env!("CARGO_PKG_VERSION"));
        assert_eq!(view.os, std::env::consts::OS);
        assert_eq!(view.arch, std::env::consts::ARCH);
        assert_eq!(view.config_dir, "/tmp/lanomat-launcher");
        assert!(!view.version.is_empty());
        assert!(!view.commit.is_empty());
        assert!(!view.host.is_empty());
        assert!(!view.arcade);
        assert!(!view.kiosk);
    }

    #[test]
    fn the_environment_carries_the_arcade_flags_for_the_ui() {
        let mode = ArcadeMode {
            arcade: true,
            kiosk: true,
        };
        let view = view(Path::new("/tmp/lanomat-launcher"), mode);
        let json = serde_json::to_value(&view).unwrap();

        assert!(view.arcade);
        assert!(view.kiosk);
        assert_eq!(json["arcade"], true);
        assert_eq!(json["kiosk"], true);
        assert_eq!(json["host"], view.host);
    }

    fn page(props: &str) -> String {
        format!(
            r#"<html><head></head><body><script data-page="app" type="application/json">{{"component":"Launcher/Index","props":{props},"url":"/launcher"}}</script><div id="app"></div></body></html>"#
        )
    }

    #[test]
    fn reads_the_published_release_from_the_inertia_page() {
        let html = page(
            r#"{"release":{"version":"m12-463-g0a3b6ff","commit":"0a3b6ff12345","builtAt":"2026-11-21T10:00:00Z"}}"#,
        );

        assert_eq!(
            release_from_page(&html),
            Some(PublishedRelease {
                version: "m12-463-g0a3b6ff".into(),
                commit: "0a3b6ff12345".into(),
            })
        );
    }

    #[test]
    fn reads_the_escaped_legacy_data_page_attribute() {
        let html = r#"<div id="app" data-page="{&quot;component&quot;:&quot;Launcher/Index&quot;,&quot;props&quot;:{&quot;release&quot;:{&quot;version&quot;:&quot;2026.11.21&quot;,&quot;commit&quot;:&quot;abcdef123456&quot;}}}"></div>"#;

        assert_eq!(
            release_from_page(html),
            Some(PublishedRelease {
                version: "2026.11.21".into(),
                commit: "abcdef123456".into(),
            })
        );
    }

    #[test]
    fn an_unpublished_or_unexpected_page_stays_quiet() {
        assert_eq!(release_from_page(&page(r#"{"release":null}"#)), None);
        assert_eq!(release_from_page(&page(r#"{"release":{}}"#)), None);
        assert_eq!(release_from_page("<html><body>nope</body></html>"), None);
        assert_eq!(
            release_from_page(r#"<script data-page="app">{not json}</script>"#),
            None
        );
    }

    #[test]
    fn an_unsafe_version_is_not_trusted() {
        let spaced = page(r#"{"release":{"version":"1.0 beta","commit":"abcdef123456"}}"#);
        let markup = page(r#"{"release":{"version":"1.0<beta","commit":"abcdef123456"}}"#);

        assert_eq!(release_from_page(&spaced), None);
        assert_eq!(release_from_page(&markup), None);
    }

    #[test]
    fn the_same_version_is_not_an_update() {
        let same_everything = update_view(
            Some(PublishedRelease {
                version: VERSION.to_string(),
                commit: COMMIT.to_string(),
            }),
            Some("https://lan.example/launcher".into()),
        );

        assert!(!same_everything.available);
        assert_eq!(same_everything.latest, None);
        assert_eq!(same_everything.current, VERSION);
        assert_eq!(same_everything.commit, COMMIT);

        if normalized_commit(COMMIT) != "unknown" {
            let same_commit_other_version = update_view(
                Some(PublishedRelease {
                    version: "m12-463-g0a3b6ff".into(),
                    commit: COMMIT.to_string(),
                }),
                None,
            );

            assert!(!same_commit_other_version.available);
        }
    }

    #[test]
    fn a_shared_commit_means_the_same_build() {
        assert!(same_commit("abcdef123456", "abcdef123456"));
        assert!(same_commit("abcdef123456", "abcdef1"));
        assert!(!same_commit("abcdef123456", "123456abcdef"));
        assert!(!same_commit("unknown", "abcdef123456"));
        assert!(!same_commit("", "abcdef123456"));
    }

    #[test]
    fn a_different_build_offers_the_published_version() {
        let local = normalized_commit(COMMIT);
        let remote_commit = if local.starts_with("0a3b6ff") {
            "1b4c7aa23456"
        } else {
            "0a3b6ff12345"
        };
        let release = PublishedRelease {
            version: "m12-463-g0a3b6ff".into(),
            commit: remote_commit.into(),
        };
        let update = update_view(Some(release), Some("https://lan.example/launcher".into()));

        assert!(update.available);
        assert_eq!(update.latest.as_deref(), Some("m12-463-g0a3b6ff"));
        assert_eq!(update.page.as_deref(), Some("https://lan.example/launcher"));
    }

    #[test]
    fn a_missing_page_is_never_an_update() {
        let update = update_view(None, Some("https://lan.example/launcher".into()));

        assert!(!update.available);
        assert_eq!(update.latest, None);
        assert!(!update.commit.is_empty());
    }
}
