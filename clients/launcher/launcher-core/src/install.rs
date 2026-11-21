//! The UT2004 pilot installer: plan -> downloads -> ISO/archive -> patch ->
//! verify. The result is a launchable flat install, nothing else.
//!
//! The retail path is deliberately explicit: the DVD ISO supplies the payload
//! folders, the OldUnreal 3374 patch supplies the complete `System/` tree. A
//! future portable `archive` requirement short-circuits the ISO path.

use std::path::{Path, PathBuf};
use std::sync::Arc;

use serde::{Deserialize, Serialize};

use crate::archive::{extract_iso, extract_tar_bz2, extract_zip};
use crate::download::{download_many, CancelToken, DownloadProgress, DownloadTask};
use crate::types::{PlanFile, RecipePlan};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum InstallPhase {
    Planning,
    Downloading,
    Extracting,
    Patching,
    Verifying,
    Ready,
    Cancelled,
    Error,
}

#[derive(Debug, Clone, Serialize)]
pub struct InstallProgress {
    pub phase: InstallPhase,
    pub message: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub file: Option<String>,
    pub bytes_done: u64,
    pub bytes_total: u64,
    pub percent: Option<f64>,
    pub bytes_per_second: u64,
    pub eta_secs: Option<u64>,
}

impl InstallProgress {
    pub fn phase(phase: InstallPhase, message: impl Into<String>) -> Self {
        Self {
            phase,
            message: message.into(),
            file: None,
            bytes_done: 0,
            bytes_total: 0,
            percent: None,
            bytes_per_second: 0,
            eta_secs: None,
        }
    }

    pub fn download(file: &str, progress: &DownloadProgress, overall_done: u64, overall_total: u64) -> Self {
        let percent = if overall_total > 0 {
            Some((overall_done as f64 / overall_total as f64 * 100.0).clamp(0.0, 100.0))
        } else {
            progress
                .total
                .filter(|total| *total > 0)
                .map(|total| (progress.received as f64 / total as f64 * 100.0).clamp(0.0, 100.0))
        };
        let eta_secs = if progress.bytes_per_second > 0 {
            overall_total
                .saturating_sub(overall_done)
                .checked_div(progress.bytes_per_second)
        } else {
            None
        };

        Self {
            phase: InstallPhase::Downloading,
            message: format!("{} wird geladen", file),
            file: Some(file.to_string()),
            bytes_done: overall_done,
            bytes_total: overall_total,
            percent,
            bytes_per_second: progress.bytes_per_second,
            eta_secs,
        }
    }
}

#[derive(Debug, thiserror::Error)]
pub enum InstallError {
    #[error("missing LAN files: {0}")]
    MissingFiles(String),
    #[error("the recipe has no base file (ISO or portable archive)")]
    NoBaseFile,
    #[error(transparent)]
    Download(#[from] crate::download::DownloadError),
    #[error(transparent)]
    Archive(#[from] crate::archive::ArchiveError),
    #[error("could not prepare {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("the install is incomplete: {0}")]
    Verification(String),
    #[error("cancelled")]
    Cancelled,
    #[error("launch failed: {0}")]
    Launch(String),
}

#[derive(Debug, Clone, Serialize)]
pub struct InstallOutcome {
    pub target_dir: String,
    pub used_archive: bool,
    pub files_extracted: usize,
    pub verified: Vec<String>,
}

#[derive(Clone)]
pub struct InstallOptions {
    pub plan: RecipePlan,
    pub bearer: Option<String>,
    /// Overrides `plan.target_dir` for a repair/portable install.
    pub target_dir: Option<PathBuf>,
    pub cancel: CancelToken,
    pub on_progress: Arc<dyn Fn(InstallProgress) + Send + Sync>,
}

impl std::fmt::Debug for InstallOptions {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("InstallOptions")
            .field("slug", &self.plan.recipe.slug)
            .field("target_dir", &self.target_dir)
            .finish()
    }
}

pub struct InstallEngine;

impl InstallEngine {
    pub async fn install(options: InstallOptions) -> Result<InstallOutcome, InstallError> {
        let progress = |update: InstallProgress| (options.on_progress)(update);
        progress(InstallProgress::phase(InstallPhase::Planning, "Installationsplan wird geladen"));

        if options.cancel.is_cancelled() {
            return Err(InstallError::Cancelled);
        }

        let missing = options.plan.missing_required();
        let base = base_file(&options.plan).ok_or_else(|| {
            if missing.is_empty() {
                InstallError::NoBaseFile
            } else {
                InstallError::MissingFiles(missing.join(", "))
            }
        })?;
        let patch = options.plan.matched("patch");

        let target = options
            .target_dir
            .clone()
            .unwrap_or_else(|| expand_home(&options.plan.target_dir));
        let downloads_dir = options
            .plan
            .downloads_dir
            .as_deref()
            .map(expand_home)
            .unwrap_or_else(|| target.join(".lanomat-downloads"));

        std::fs::create_dir_all(&downloads_dir).map_err(|source| InstallError::Io {
            path: downloads_dir.clone(),
            source,
        })?;

        let mut tasks = Vec::new();
        tasks.push(download_task(&options.plan, base, &downloads_dir, options.bearer.clone())?);
        if let Some(patch) = patch {
            tasks.push(download_task(&options.plan, patch, &downloads_dir, options.bearer.clone())?);
        }

        let total: u64 = tasks.iter().filter_map(|task| task.size_bytes).sum();
        let mut done = 0u64;
        let paths = download_many(&tasks, &options.cancel, |index, update| {
            if index > 0 {
                done = tasks.iter().take(index).filter_map(|task| task.size_bytes).sum();
            }
            let overall = done + update.received;
            progress(InstallProgress::download(&update.filename, &update, overall, total));
        })
        .await?;

        let base_path = &paths[0];
        let patch_path = paths.get(1);

        std::fs::create_dir_all(&target).map_err(|source| InstallError::Io {
            path: target.clone(),
            source,
        })?;

        progress(InstallProgress::phase(InstallPhase::Extracting, "Spieldateien werden entpackt"));
        let used_archive = is_zip(base_path);
        let mut files_extracted = if used_archive {
            extract_zip(base_path, &target)?
        } else if is_iso(base_path) {
            extract_iso(base_path, &target)?
        } else {
            return Err(InstallError::Verification(format!(
                "unknown base archive {}",
                base_path.display()
            )));
        };

        if let Some(patch_path) = patch_path {
            progress(InstallProgress::phase(InstallPhase::Patching, "OldUnreal-Patch wird eingespielt"));

            if is_zip(patch_path) {
                files_extracted += extract_zip(patch_path, &target)?;
            } else if is_tar_bz2(patch_path) {
                files_extracted += extract_tar_bz2(patch_path, &target)?;
            } else {
                return Err(InstallError::Verification(format!(
                    "unknown patch archive {}",
                    patch_path.display()
                )));
            }
        }

        progress(InstallProgress::phase(InstallPhase::Verifying, "Installation wird geprüft"));
        let verified = verify_install(&target)?;
        mark_executable(&target)?;

        progress(InstallProgress::phase(InstallPhase::Ready, "Fertig — bereit zum Start"));

        Ok(InstallOutcome {
            target_dir: target.to_string_lossy().to_string(),
            used_archive,
            files_extracted,
            verified,
        })
    }

    /// Start the flat game. Returns the executable that was started.
    pub fn launch(target_dir: &Path) -> Result<PathBuf, InstallError> {
        let system = target_dir.join("System");
        let candidates = if cfg!(windows) {
            vec![system.join("UT2004.exe")]
        } else {
            vec![system.join("ut2004-bin-amd64"), system.join("ut2004")]
        };

        let exe = candidates
            .into_iter()
            .find(|path| path.is_file())
            .ok_or_else(|| InstallError::Verification("System/UT2004.exe fehlt".into()))?;

        let mut command = std::process::Command::new(&exe);
        command.current_dir(&system);
        command.spawn().map_err(|error| InstallError::Launch(error.to_string()))?;

        Ok(exe)
    }
}

fn base_file(plan: &RecipePlan) -> Option<&PlanFile> {
    plan.matched("archive")
        .or_else(|| plan.matched("base"))
        .or_else(|| plan.matched("iso"))
}

fn download_task(
    plan: &RecipePlan,
    file: &PlanFile,
    dest: &Path,
    bearer: Option<String>,
) -> Result<DownloadTask, InstallError> {
    let url = file
        .download_url
        .clone()
        .or_else(|| file.fallback_url.clone())
        .ok_or_else(|| InstallError::Verification(format!("{} has no URL", file.requirement)))?;
    let filename = file
        .name
        .clone()
        .or_else(|| file.download_url.as_deref().and_then(|url| url.rsplit('/').next()).map(str::to_string))
        .or_else(|| file.label.clone())
        .unwrap_or_else(|| file.requirement.clone());

    let _ = plan;

    Ok(DownloadTask {
        url,
        filename,
        dest_dir: dest.to_path_buf(),
        sha256: file.sha256.clone(),
        size_bytes: file.size_bytes,
        bearer,
    })
}

fn is_iso(path: &Path) -> bool {
    path.extension().is_some_and(|ext| ext.eq_ignore_ascii_case("iso"))
}

fn is_zip(path: &Path) -> bool {
    path.extension().is_some_and(|ext| ext.eq_ignore_ascii_case("zip"))
}

fn is_tar_bz2(path: &Path) -> bool {
    let name = path.to_string_lossy().to_ascii_lowercase();
    name.ends_with(".tar.bz2") || name.ends_with(".tbz2")
}

fn expand_home(value: &str) -> PathBuf {
    if let Some(rest) = value.strip_prefix("~/") {
        if let Some(home) = std::env::var_os("HOME").or_else(|| std::env::var_os("USERPROFILE")) {
            return PathBuf::from(home).join(rest);
        }
    }

    PathBuf::from(value)
}

fn verify_install(target: &Path) -> Result<Vec<String>, InstallError> {
    let required: &[&str] = if cfg!(windows) {
        &["System/UT2004.exe", "System/Build.ini", "System/D3D11Drv.dll"]
    } else {
        &["System/ut2004-bin-amd64", "System/Build.ini"]
    };
    let mut verified = Vec::new();

    for file in required {
        let path = target.join(file);
        if !path.is_file() {
            return Err(InstallError::Verification((*file).to_string()));
        }
        verified.push((*file).to_string());
    }

    Ok(verified)
}

fn mark_executable(target: &Path) -> Result<(), InstallError> {
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        for name in ["System/ut2004-bin-amd64", "System/ut2004"] {
            let path = target.join(name);
            if path.is_file() {
                let mut permissions = std::fs::metadata(&path)
                    .map_err(|source| InstallError::Io { path: path.clone(), source })?
                    .permissions();
                permissions.set_mode(permissions.mode() | 0o755);
                let _ = std::fs::set_permissions(&path, permissions);
            }
        }
    }

    let _ = target;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn plan(files: serde_json::Value) -> RecipePlan {
        serde_json::from_value(serde_json::json!({
            "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
            "target_dir": "~/Games/ut2004",
            "files": files,
        }))
        .unwrap()
    }

    #[test]
    fn a_matched_iso_is_the_base_file() {
        let plan = plan(serde_json::json!([
            {"requirement": "iso", "status": "matched", "download_url": "https://lan/api/v1/files/1/download", "name": "ut2004.iso"},
            {"requirement": "patch", "status": "matched", "download_url": "https://lan/api/v1/files/2/download", "name": "patch.zip"}
        ]));
        let base = base_file(&plan).unwrap();
        assert_eq!(base.requirement, "iso");
    }

    #[test]
    fn a_portable_archive_wins_over_the_iso() {
        let plan = plan(serde_json::json!([
            {"requirement": "iso", "status": "matched", "download_url": "https://lan/1.iso", "name": "ut2004.iso"},
            {"requirement": "archive", "status": "matched", "download_url": "https://lan/2.zip", "name": "ut2004-portable.zip"}
        ]));
        assert_eq!(base_file(&plan).unwrap().requirement, "archive");
    }

    #[test]
    fn missing_required_files_are_reported_together() {
        let plan = plan(serde_json::json!([
            {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
            {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
        ]));
        assert_eq!(plan.missing_required(), vec!["UT2004 DVD", "OldUnreal Patch"]);
    }
}
