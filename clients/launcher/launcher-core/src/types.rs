//! Wire types shared by the launcher API, the install engine and the UI.
//!
//! They mirror the documented agent API (`POST /api/mcp/device`,
//! `POST /api/mcp/token`, `GET /api/v1/me`, `GET /api/v1/recipes`,
//! `POST /api/v1/recipes/{slug}/plan`). Unknown fields are ignored so a newer
//! server never breaks an older launcher.

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
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DeviceStart {
    pub device_code: String,
    pub user_code: String,
    pub verification_uri: String,
    #[serde(default)]
    pub verification_uri_complete: Option<String>,
    pub expires_in: u64,
    pub interval: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TokenGrant {
    pub access_token: String,
    #[serde(default)]
    pub token_type: String,
    pub expires_in: u64,
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
    pub id: serde_json::Value,
    pub name: String,
    #[serde(default)]
    pub role: Option<String>,
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
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PlanFile {
    #[serde(default)]
    pub requirement: String,
    pub status: String,
    #[serde(default)]
    pub label: Option<String>,
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

impl RecipePlan {
    pub fn matched(&self, requirement: &str) -> Option<&PlanFile> {
        self.files.iter().find(|file| {
            file.requirement == requirement
                && file.status == "matched"
                && (file.download_url.is_some() || file.fallback_url.is_some())
        })
    }

    pub fn missing_required(&self) -> Vec<String> {
        self.files
            .iter()
            .filter(|file| file.required && file.status == "missing")
            .map(|file| {
                file.label
                    .clone()
                    .or_else(|| file.name.clone())
                    .unwrap_or_else(|| file.requirement.clone())
            })
            .collect()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

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
}
