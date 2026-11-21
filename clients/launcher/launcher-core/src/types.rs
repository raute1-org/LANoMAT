//! Wire types shared by the launcher API, the install engine and the UI.
//!
//! They mirror the documented agent API (`POST /api/mcp/device`,
//! `POST /api/mcp/token`, `GET /api/v1/me`, `GET /api/v1/recipes`,
//! `POST /api/v1/recipes/{slug}/plan`). Unknown fields are ignored so a newer
//! server never breaks an older launcher.
//!
//! `Debug` is hand-written for the two structs that carry a credential, so a
//! `tracing::debug!("{device:?}")` can never put a device code or a bearer token
//! into the log file.

use std::fmt;

use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Os {
    Windows,
    Linux,
    Macos,
}

impl Os {
    pub fn current() -> Self {
        match std::env::consts::OS {
            "windows" => Self::Windows,
            "macos" => Self::Macos,
            _ => Self::Linux,
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Self::Windows => "windows",
            Self::Linux => "linux",
            Self::Macos => "macos",
        }
    }

    /// Parse a wire value (`windows`, `linux`, `macos`) the way the server
    /// spells it; the short forms match [`Os::current`]'s environment names.
    pub fn from_wire(value: &str) -> Option<Self> {
        match value.trim().to_ascii_lowercase().as_str() {
            "windows" | "win" | "win32" | "win64" => Some(Self::Windows),
            "linux" => Some(Self::Linux),
            "macos" | "mac" | "darwin" => Some(Self::Macos),
            _ => None,
        }
    }
}

/// RFC 8628's `interval` is optional in the wild; five seconds is the spec's
/// recommended default.
fn default_interval() -> u64 {
    5
}

/// Ten minutes, the server's own code TTL.
fn default_expires_in() -> u64 {
    600
}

#[derive(Clone, Serialize, Deserialize)]
pub struct DeviceStart {
    pub device_code: String,
    pub user_code: String,
    pub verification_uri: String,
    #[serde(default)]
    pub verification_uri_complete: Option<String>,
    #[serde(default = "default_expires_in")]
    pub expires_in: u64,
    #[serde(default = "default_interval")]
    pub interval: u64,
}

impl fmt::Debug for DeviceStart {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("DeviceStart")
            // The device code is a credential: never print it.
            .field("device_code", &"<redacted>")
            .field("user_code", &self.user_code)
            .field("verification_uri", &self.verification_uri)
            .field("expires_in", &self.expires_in)
            .field("interval", &self.interval)
            .finish()
    }
}

#[derive(Clone, Serialize, Deserialize)]
pub struct TokenGrant {
    pub access_token: String,
    #[serde(default)]
    pub token_type: String,
    #[serde(default)]
    pub expires_in: u64,
}

impl fmt::Debug for TokenGrant {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TokenGrant")
            .field("access_token", &"<redacted>")
            .field("token_type", &self.token_type)
            .field("expires_in", &self.expires_in)
            .finish()
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct MeResponse {
    pub user: MeUser,
    #[serde(default)]
    pub server: ServerInfo,
    #[serde(default)]
    pub modules: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct MeUser {
    /// Number or string, depending on the server's id column: keep it opaque.
    pub id: serde_json::Value,
    pub name: String,
    #[serde(default)]
    pub role: Option<String>,
    #[serde(default)]
    pub is_orga: bool,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ServerInfo {
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub url: String,
    #[serde(default)]
    pub version: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RecipeSummary {
    pub slug: String,
    pub title: String,
    #[serde(default)]
    pub summary: Option<String>,
    #[serde(default)]
    pub version: Option<String>,
    #[serde(default)]
    pub status: Option<String>,
    #[serde(default)]
    pub platforms: Vec<String>,
    /// The server says the recipe carries everything an install needs
    /// (requirements, steps and a launch target). `None` means an older server
    /// that never sent the field: unknown, which is not "no".
    #[serde(default)]
    pub installable: Option<bool>,
    /// The shell's verdict, not the server's: whether *this launcher build*
    /// can install the recipe. [`crate::api`] copies the server flag through,
    /// `list_games` overwrites it with what its install engine really drives.
    /// A recipe that is `installable` but not `launcher_supported` belongs on
    /// the game's `/games/{slug}` web page ("Bald im Launcher"), not behind an
    /// Install button that would fail.
    #[serde(default)]
    pub launcher_supported: bool,
    /// A browser recipe's entry point (e.g. `https://nfsu2.xyz/`). It has no
    /// install path at all; the library card says "Spielen" and opens this URL
    /// through `open_url`. `None` for an installable recipe.
    #[serde(default)]
    pub play_url: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RecipePlan {
    pub recipe: PlanRecipe,
    #[serde(default)]
    pub os: String,
    pub target_dir: String,
    #[serde(default)]
    pub downloads_dir: Option<String>,
    #[serde(default)]
    pub files: Vec<PlanFile>,
    #[serde(default)]
    pub missing: Vec<String>,
    #[serde(default)]
    pub steps: Vec<PlanStep>,
    #[serde(default)]
    pub avatars: Vec<PlanAvatar>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PlanRecipe {
    pub slug: String,
    pub title: String,
    #[serde(default)]
    pub version: Option<String>,
    #[serde(default)]
    pub launch_target: Option<String>,
    #[serde(default)]
    pub launch_targets: Vec<LaunchTarget>,
    #[serde(default)]
    pub play_url: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LaunchTarget {
    pub id: String,
    #[serde(default)]
    pub label: Option<String>,
    #[serde(default)]
    pub os: Option<String>,
    #[serde(default)]
    pub command: Option<String>,
    #[serde(default)]
    pub target: Option<String>,
    #[serde(default)]
    pub shell: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PlanStep {
    pub id: String,
    pub title: String,
    #[serde(default)]
    pub os: Option<String>,
    #[serde(default)]
    pub optional: bool,
    #[serde(default)]
    pub detail: Option<String>,
    #[serde(default)]
    pub commands: Vec<PlanCommand>,
    /// The plan's own post-install checks. A generic (non-UT2004) install
    /// verifies these `file_exists`/`dir_exists` entries instead of the
    /// pilot's hardcoded `System/` tree.
    #[serde(default)]
    pub verify: Vec<PlanVerification>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PlanCommand {
    #[serde(default)]
    pub shell: String,
    #[serde(default)]
    pub run: String,
}

/// One `steps[].verify` entry from the server's install plan. Older plans use
/// a bare path string (`"- verify: [System/UT2004.exe]"`), newer ones the
/// documented `{type, path}` object; both deserialize to this shape.
#[derive(Debug, Clone, Serialize)]
pub struct PlanVerification {
    /// Wire name is `type`; `kind` keeps the Rust field name usable.
    #[serde(rename = "type", default)]
    pub kind: String,
    #[serde(default)]
    pub path: Option<String>,
    #[serde(default)]
    pub command: Option<String>,
    #[serde(default)]
    pub os: Option<String>,
}

impl<'de> Deserialize<'de> for PlanVerification {
    fn deserialize<D>(deserializer: D) -> Result<Self, D::Error>
    where
        D: serde::Deserializer<'de>,
    {
        #[derive(Deserialize)]
        #[serde(untagged)]
        enum Wire {
            Path(String),
            Check(PlanVerificationCheck),
        }

        #[derive(Deserialize)]
        struct PlanVerificationCheck {
            #[serde(rename = "type", default)]
            kind: String,
            #[serde(default)]
            path: Option<String>,
            #[serde(default)]
            command: Option<String>,
            #[serde(default)]
            os: Option<String>,
        }

        Ok(match Wire::deserialize(deserializer)? {
            Wire::Path(path) => Self {
                kind: "file_exists".into(),
                path: Some(path),
                command: None,
                os: None,
            },
            Wire::Check(check) => Self {
                kind: if check.kind.is_empty() {
                    "file_exists".into()
                } else {
                    check.kind
                },
                path: check.path,
                command: check.command,
                os: check.os,
            },
        })
    }
}

impl PlanVerification {
    /// Whether this check belongs to `os`; absent/`any` checks run everywhere.
    pub fn fits_os(&self, os: Os) -> bool {
        match self.os.as_deref() {
            None | Some("") | Some("any") => true,
            Some(value) => Os::from_wire(value).is_none_or(|named| named == os),
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PlanFile {
    #[serde(default)]
    pub requirement: String,
    pub status: String,
    #[serde(default)]
    pub label: Option<String>,
    /// The platform this file is for (`windows`, `linux`, `macos`); absent or
    /// `any` means it fits every plan. The server already leaves files for the
    /// other OS out of the plan; the engine checks again so a stale or
    /// hand-written plan can never install the wrong platform's archive.
    #[serde(default)]
    pub os: Option<String>,
    #[serde(default)]
    pub name: Option<String>,
    #[serde(default)]
    pub required: bool,
    #[serde(default)]
    pub size_bytes: Option<u64>,
    #[serde(default)]
    pub sha256: Option<String>,
    #[serde(default)]
    pub download_url: Option<String>,
    #[serde(default)]
    pub fallback_url: Option<String>,
    #[serde(default)]
    pub source: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PlanAvatar {
    pub id: String,
    pub label: String,
    #[serde(default)]
    pub size_bytes: Option<u64>,
    #[serde(default)]
    pub sha256: Option<String>,
    #[serde(default)]
    pub download_url: Option<String>,
    #[serde(default)]
    pub available_on_lan: bool,
}

impl PlanFile {
    /// Whether this file may be installed on `os`. Only an explicit `os` that
    /// names another known platform excludes it; absent, empty, `any` and an
    /// unknown spelling all fit, so a typo never hides a required file.
    pub fn fits_os(&self, os: Os) -> bool {
        match self.os.as_deref() {
            None | Some("") => true,
            Some(value) => match Os::from_wire(value) {
                Some(named) => named == os,
                None => true,
            },
        }
    }
}

impl RecipePlan {
    /// The OS the plan was built for. A plan from an older server has no `os`;
    /// fall back to this machine, which is what that plan was intended for.
    pub fn target_os(&self) -> Os {
        Os::from_wire(&self.os).unwrap_or_else(Os::current)
    }

    /// The documented status values are `matched` (a file on the LAN host),
    /// `external` (the recipe only lists a public URL), `missing` and `manual`.
    pub fn matched(&self, requirement: &str) -> Option<&PlanFile> {
        self.files.iter().find(|file| {
            file.requirement == requirement
                && file.status == "matched"
                && (file.download_url.is_some() || file.fallback_url.is_some())
        })
    }

    /// Anything the launcher can fetch itself: a LAN file (`matched`) or an
    /// external URL (`external`). The ISO fallback path can be an external-only
    /// recipe, so installations must not depend on [`Self::matched`] alone.
    pub fn downloadable(&self, requirement: &str) -> Option<&PlanFile> {
        self.files.iter().find(|file| {
            file.requirement == requirement
                && matches!(file.status.as_str(), "matched" | "external")
                && (file.download_url.is_some() || file.fallback_url.is_some())
        })
    }

    /// [`Self::downloadable`] for one platform. The same role may appear twice
    /// (a Windows and a Linux patch); the `os` field selects the right one.
    pub fn downloadable_for(&self, requirement: &str, os: Os) -> Option<&PlanFile> {
        self.files.iter().find(|file| {
            file.requirement == requirement
                && matches!(file.status.as_str(), "matched" | "external")
                && (file.download_url.is_some() || file.fallback_url.is_some())
                && file.fits_os(os)
        })
    }

    pub fn missing_required(&self) -> Vec<String> {
        let os = self.target_os();

        self.files
            .iter()
            .filter(|file| file.required && file.status == "missing" && file.fits_os(os))
            .map(|file| {
                file.label
                    .clone()
                    .or_else(|| file.name.clone())
                    .unwrap_or_else(|| file.requirement.clone())
            })
            .collect()
    }
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ServerList {
    #[serde(default)]
    pub events: Vec<ServerEvent>,
    #[serde(default)]
    pub servers: Vec<GameServer>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ServerEvent {
    pub slug: String,
    pub name: String,
    #[serde(default)]
    pub status: String,
}

/// One game server of the aggregated browser. The camelCase fields mirror the
/// server's `ServerListProjection`; unknown fields are ignored, so a newer
/// server never breaks an older launcher.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct GameServer {
    #[serde(default)]
    pub id: serde_json::Value,
    #[serde(default)]
    pub title: String,
    #[serde(default)]
    pub game: Option<String>,
    /// The game's stable slug, used to map the server to its install recipe.
    #[serde(default)]
    pub game_slug: Option<String>,
    #[serde(default)]
    pub description: Option<String>,
    #[serde(default)]
    pub match_label: Option<String>,
    #[serde(default)]
    pub status: String,
    #[serde(default)]
    pub address: Option<String>,
    #[serde(default)]
    pub port: Option<u16>,
    #[serde(default)]
    pub connect_string: Option<String>,
    #[serde(default)]
    pub connect_is_link: bool,
    #[serde(default)]
    pub permanent: bool,
    #[serde(default)]
    pub event: Option<ServerEvent>,
    #[serde(default)]
    pub query: Option<ServerQuery>,
    #[serde(default)]
    pub join_options: Vec<ServerJoinOption>,
    #[serde(default)]
    pub source: Option<String>,
}

impl GameServer {
    /// The one URL a "Beitreten" action may open: the first per-game join
    /// option with an allowlisted scheme, else a connect string that is itself
    /// a deep link. A plain `address:port` is copy data, not a URL.
    pub fn join_url(&self) -> Option<&str> {
        if !self.joinable() {
            return None;
        }

        self.join_options
            .iter()
            .map(|option| option.url.as_str())
            .find(|url| is_allowed_join_url(url))
            .or_else(|| {
                self.connect_string
                    .as_deref()
                    .filter(|url| self.connect_is_link && is_allowed_join_url(url))
            })
    }

    /// Whether the server answers (or at least is worth offering) right now.
    pub fn joinable(&self) -> bool {
        matches!(self.status.as_str(), "ready" | "not_responding")
    }
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ServerQuery {
    #[serde(default)]
    pub server_name: String,
    #[serde(default)]
    pub map: String,
    #[serde(default)]
    pub players: u32,
    #[serde(default)]
    pub max_players: u32,
    #[serde(default)]
    pub checked_at: Option<String>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ServerJoinOption {
    #[serde(default)]
    pub key: String,
    #[serde(default)]
    pub label: String,
    #[serde(default)]
    pub url: String,
    #[serde(default)]
    pub hint: String,
}

/// The launcher may open these schemes itself; the shell enforces the same
/// allowlist in `open_url`.
pub fn is_allowed_join_url(url: &str) -> bool {
    let lower = url.trim().to_ascii_lowercase();

    ["https://", "http://", "steam://", "ut2004://"]
        .iter()
        .any(|scheme| lower.starts_with(scheme))
        && !url.chars().any(|c| c.is_whitespace() || c.is_control())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn join_url_uses_only_the_allowlisted_deep_links() {
        let mut server = GameServer {
            status: "ready".into(),
            connect_string: Some("203.0.113.5:27015".into()),
            connect_is_link: false,
            ..Default::default()
        };

        assert!(server.join_url().is_none());

        server.join_options = vec![ServerJoinOption {
            url: "steam://run/13230//203.0.113.5:27015".into(),
            ..Default::default()
        }];
        assert_eq!(
            server.join_url(),
            Some("steam://run/13230//203.0.113.5:27015")
        );

        server.join_options = vec![ServerJoinOption {
            url: "javascript:alert(1)".into(),
            ..Default::default()
        }];
        assert!(server.join_url().is_none());

        server.connect_string = Some("steam://connect/203.0.113.5:27015".into());
        server.connect_is_link = true;
        assert_eq!(server.join_url(), Some("steam://connect/203.0.113.5:27015"));

        server.connect_string = Some("steam://connect/203.0.113.5:27015 with space".into());
        assert!(server.join_url().is_none());

        server.connect_string = Some("steam://connect/203.0.113.5:27015".into());
        server.status = "offline".into();
        assert!(server.join_url().is_none());
    }

    #[test]
    fn matched_requires_a_downloadable_lan_or_external_file() {
        let mut plan: RecipePlan = serde_json::from_value(serde_json::json!({
            "recipe": {"slug": "ut2004", "title": "UT2004"},
            "target_dir": "C:\\Games\\UT2004",
            "files": [
                {"requirement": "iso", "status": "matched", "download_url": "https://lan/api/v1/files/1/download"},
                {"requirement": "patch", "status": "missing", "required": true, "label": "Patch"},
                {"requirement": "mod", "status": "manual", "name": "VR"}
            ]
        }))
        .unwrap();

        assert_eq!(plan.matched("iso").unwrap().size_bytes, None);
        assert!(plan.matched("patch").is_none());
        assert_eq!(plan.missing_required(), vec!["Patch".to_string()]);

        plan.files[0].download_url = None;
        plan.files[0].fallback_url = Some("https://example.test/ut2004.iso".into());
        assert!(plan.matched("iso").is_some());
    }

    #[test]
    fn missing_required_ignores_a_file_for_another_os() {
        let plan: RecipePlan = serde_json::from_value(serde_json::json!({
            "recipe": {"slug": "ut2004", "title": "UT2004"},
            "os": "linux",
            "target_dir": "~/Games/ut2004",
            "files": [
                {"requirement": "patch", "os": "windows", "status": "missing", "required": true, "label": "Windows patch"},
                {"requirement": "patch", "os": "linux", "status": "missing", "required": true, "label": "Linux patch"},
                {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
                {"requirement": "mod", "os": "amiga", "status": "matched", "label": "Typo mod"}
            ]
        }))
        .unwrap();

        assert_eq!(
            plan.missing_required(),
            vec!["Linux patch".to_string(), "UT2004 DVD".to_string()]
        );
        assert!(plan.files[0].fits_os(Os::Windows));
        assert!(!plan.files[0].fits_os(Os::Linux));
        // An OS-less file belongs to every plan.
        assert!(plan.files[2].fits_os(Os::Windows));
        assert!(plan.files[2].fits_os(Os::Linux));
        // An unknown spelling is a typo, not a platform: it never hides a file.
        assert!(plan.files[3].fits_os(Os::Windows));
        assert!(plan.files[3].fits_os(Os::Linux));
    }

    #[test]
    fn opaque_downloadable_files_are_matched_or_external_with_a_url() {
        let plan: RecipePlan = serde_json::from_value(serde_json::json!({
            "recipe": {"slug": "ut2004", "title": "UT2004"},
            "os": "linux",
            "target_dir": "~/Games/ut2004",
            "files": [
                {"requirement": "iso", "status": "external", "download_url": "https://example.test/ut2004.iso"},
                {"requirement": "patch", "os": "windows", "status": "external", "download_url": "https://example.test/win.zip"},
                {"requirement": "patch", "os": "linux", "status": "external", "download_url": "https://example.test/linux.tar.bz2"},
                {"requirement": "mod", "status": "manual", "url": "https://example.test/mod"}
            ]
        }))
        .unwrap();

        assert_eq!(plan.downloadable("iso").unwrap().status, "external");
        assert_eq!(
            plan.downloadable_for("patch", Os::Linux)
                .unwrap()
                .download_url
                .as_deref(),
            Some("https://example.test/linux.tar.bz2")
        );
        assert_eq!(
            plan.downloadable_for("patch", Os::Windows)
                .unwrap()
                .download_url
                .as_deref(),
            Some("https://example.test/win.zip")
        );
        assert!(plan.downloadable("mod").is_none());
    }

    #[test]
    fn a_summary_without_the_capability_flags_is_unknown_not_unsupported() {
        let summary: RecipeSummary = serde_json::from_value(serde_json::json!({
            "slug": "ut2004",
            "title": "UT2004"
        }))
        .unwrap();

        // An old server sends neither flag; "unknown" must not read as "no".
        assert_eq!(summary.installable, None);
        assert!(!summary.launcher_supported);

        let flagged: RecipeSummary = serde_json::from_value(serde_json::json!({
            "slug": "ut2004",
            "title": "UT2004",
            "installable": true,
            "launcher_supported": true
        }))
        .unwrap();

        assert_eq!(flagged.installable, Some(true));
        assert!(flagged.launcher_supported);

        let browser: RecipeSummary = serde_json::from_value(serde_json::json!({
            "slug": "need-for-speed-underground-2-browser",
            "title": "NFSU2 (Browser)",
            "installable": false,
            "launcher_supported": true,
            "play_url": "https://nfsu2.xyz/"
        }))
        .unwrap();

        assert_eq!(browser.play_url.as_deref(), Some("https://nfsu2.xyz/"));
        assert_eq!(browser.installable, Some(false));
    }
}
