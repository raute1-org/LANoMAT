//! The UT2004 pilot installer: plan -> downloads -> ISO/archive -> patch ->
//! verify. The result is a launchable flat install, nothing else.
//!
//! The retail path is deliberately explicit: the DVD ISO supplies the payload
//! folders, the OldUnreal 3374 patch supplies the complete `System/` tree. A
//! portable `archive` requirement short-circuits the ISO path with no further
//! launcher change.

use std::path::{Path, PathBuf};
use std::sync::Arc;

use serde::{Deserialize, Serialize};

use crate::archive::{
    detect_iso, detect_tar_bz2, detect_zip, extract_iso, extract_tar_bz2, extract_zip,
};
use crate::download::{download_many, CancelToken, DownloadProgress, DownloadTask};
use crate::types::{Os, PlanFile, RecipePlan};

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

    pub fn download(
        file: &str,
        progress: &DownloadProgress,
        overall_done: u64,
        overall_total: u64,
    ) -> Self {
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
    /// Origin (`scheme://host[:port]`) of the server this launcher is paired
    /// with, e.g. `origin_of(api.base_url())`.
    ///
    /// The token is scoped to exactly this origin. It is deliberately *not*
    /// derived from a plan file's `download_url`: a plan may point at a file
    /// host, CDN or mirror on another origin, and the token must never follow
    /// the plan to it. `None` means no request ever carries the token.
    pub bearer_origin: Option<String>,
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

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum ArchiveKind {
    Zip,
    TarBz2,
    Iso,
}

pub struct InstallEngine;

impl InstallEngine {
    pub async fn install(options: InstallOptions) -> Result<InstallOutcome, InstallError> {
        let progress = |update: InstallProgress| (options.on_progress)(update);
        progress(InstallProgress::phase(
            InstallPhase::Planning,
            "Installationsplan wird geladen",
        ));

        if options.cancel.is_cancelled() {
            return Err(InstallError::Cancelled);
        }

        let plan = &options.plan;
        let missing = missing_files(plan);

        if !missing.is_empty() {
            return Err(InstallError::MissingFiles(missing.join(", ")));
        }

        let base = base_file(plan).ok_or(InstallError::NoBaseFile)?;
        let patch = patch_file(plan)?;

        let target = options
            .target_dir
            .clone()
            .unwrap_or_else(|| expand_home(&plan.target_dir));
        let downloads_dir = plan
            .downloads_dir
            .as_deref()
            .map(expand_home)
            .unwrap_or_else(|| target.join(".lanomat-downloads"));

        std::fs::create_dir_all(&downloads_dir).map_err(|source| InstallError::Io {
            path: downloads_dir.clone(),
            source,
        })?;

        let mut tasks = Vec::new();
        tasks.push(download_task(
            base,
            &downloads_dir,
            options.bearer.clone(),
            options.bearer_origin.clone(),
        )?);
        if let Some(patch) = patch {
            tasks.push(download_task(
                patch,
                &downloads_dir,
                options.bearer.clone(),
                options.bearer_origin.clone(),
            )?);
        }

        let total: u64 = tasks.iter().filter_map(|task| task.size_bytes).sum();
        let mut done = 0u64;
        let paths = match download_many(&tasks, &options.cancel, |index, update| {
            if index > 0 {
                done = tasks
                    .iter()
                    .take(index)
                    .filter_map(|task| task.size_bytes)
                    .sum();
            }
            let overall = done + update.received;
            progress(InstallProgress::download(
                &update.filename,
                &update,
                overall,
                total,
            ));
        })
        .await
        {
            Ok(paths) => paths,
            // A cancel while a request is in flight is the install's cancel,
            // not a transient download error the UI should offer a retry for.
            Err(crate::download::DownloadError::Cancelled) => return Err(InstallError::Cancelled),
            Err(error) => return Err(InstallError::Download(error)),
        };

        let base_path = &paths[0];
        let patch_path = paths.get(1);

        std::fs::create_dir_all(&target).map_err(|source| InstallError::Io {
            path: target.clone(),
            source,
        })?;

        progress(InstallProgress::phase(
            InstallPhase::Extracting,
            "Spieldateien werden entpackt",
        ));
        let base_kind = archive_kind(base_path).ok_or_else(|| {
            InstallError::Verification(format!("unknown base archive {}", base_path.display()))
        })?;
        let used_archive = base_kind != ArchiveKind::Iso;
        let mut files_extracted = extract_base(base_path, base_kind, &target)?;

        if let Some(patch_path) = patch_path {
            progress(InstallProgress::phase(
                InstallPhase::Patching,
                "OldUnreal-Patch wird eingespielt",
            ));

            if detect_zip(patch_path) {
                files_extracted += extract_zip(patch_path, &target)?;
            } else if detect_tar_bz2(patch_path) {
                files_extracted += extract_tar_bz2(patch_path, &target)?;
            } else {
                return Err(InstallError::Verification(format!(
                    "unknown patch archive {}",
                    patch_path.display()
                )));
            }
        }

        progress(InstallProgress::phase(
            InstallPhase::Verifying,
            "Installation wird geprüft",
        ));
        let verified = verify_install(&target)?;
        mark_executable(&target)?;

        progress(InstallProgress::phase(
            InstallPhase::Ready,
            "Fertig — bereit zum Start",
        ));

        Ok(InstallOutcome {
            target_dir: target.to_string_lossy().to_string(),
            used_archive,
            files_extracted,
            verified,
        })
    }

    /// Start the flat game from `System/`. Returns the executable that was
    /// started. Nothing is started during verification.
    pub fn launch(target_dir: &Path) -> Result<PathBuf, InstallError> {
        let system = target_dir.join("System");
        let candidates = launch_candidates(&system);

        let exe = candidates
            .into_iter()
            .find(|path| path.is_file())
            .ok_or_else(|| InstallError::Verification(format!("{} fehlt", launch_name())))?;

        let mut command = std::process::Command::new(&exe);
        command.current_dir(&system);
        command
            .spawn()
            .map_err(|error| InstallError::Launch(error.to_string()))?;

        Ok(exe)
    }
}

fn launch_candidates(system: &Path) -> Vec<PathBuf> {
    if cfg!(windows) {
        vec![system.join("UT2004.exe")]
    } else {
        vec![
            system.join("ut2004-bin-amd64"),
            system.join("UT2004"),
            system.join("ut2004"),
        ]
    }
}

fn launch_name() -> &'static str {
    if cfg!(windows) {
        "System/UT2004.exe"
    } else {
        "System/ut2004-bin-amd64 or System/UT2004"
    }
}

fn is_base_requirement(requirement: &str) -> bool {
    matches!(requirement, "archive" | "base" | "iso")
}

/// A portable base is a ready-to-copy game folder, not the retail disc: it is
/// already patched, so the patch is optional. The ISO path still needs it.
fn is_portable_base(file: &PlanFile) -> bool {
    matches!(file.requirement.as_str(), "archive" | "base")
}

/// A file the launcher can fetch itself (`matched` or `external` with a URL)
/// for `os`. The plan may carry a second requirement with the same role for
/// the other platform; the `os` field keeps them apart.
fn downloadable_for<'a>(plan: &'a RecipePlan, requirement: &str, os: Os) -> Option<&'a PlanFile> {
    plan.downloadable_for(requirement, os)
}

/// The downloadable base file: a portable archive (`archive`/`base`) wins over
/// the retail ISO. A `matched` LAN file and an external-only file are both
/// usable — the Windows-only portable folder never becomes a Linux base.
pub fn base_file(plan: &RecipePlan) -> Option<&PlanFile> {
    base_file_for(plan, plan.target_os())
}

/// [`base_file`] for an explicit OS, regardless of the OS the plan names.
pub fn base_file_for(plan: &RecipePlan, os: Os) -> Option<&PlanFile> {
    ["archive", "base", "iso"]
        .iter()
        .find_map(|requirement| downloadable_for(plan, requirement, os))
}

fn file_label(file: &PlanFile) -> String {
    file.label
        .clone()
        .or_else(|| file.name.clone())
        .unwrap_or_else(|| file.requirement.clone())
}

/// Required files that still block an install.
///
/// A matched base file satisfies every base requirement, so a portable
/// `archive` installs even when the plan lists the ISO as required/missing.
/// Because a portable base is already patched, its patch requirement does not
/// block either; the ISO path still requires the patch. Labels are reported
/// once even when several entries would name the same file. Files authored for
/// another OS are not part of this plan, so they never block it.
pub fn missing_files(plan: &RecipePlan) -> Vec<String> {
    let base = base_file(plan);
    let base_matched = base.is_some();
    let portable_base = base.is_some_and(is_portable_base);
    let os = plan.target_os();

    let mut labels = Vec::new();

    for file in plan
        .files
        .iter()
        .filter(|file| file.required && file.status == "missing" && file.fits_os(os))
        .filter(|file| !(base_matched && is_base_requirement(&file.requirement)))
        .filter(|file| !(portable_base && file.requirement == "patch"))
    {
        let label = file_label(file);

        if !labels.contains(&label) {
            labels.push(label);
        }
    }

    labels
}

/// A matched patch must be downloaded and applied. If the plan declares a
/// required patch that has no reachable URL, the install fails instead of
/// silently producing a game without the 3374 binaries — on the ISO path. A
/// portable `archive`/`base` already carries 3374, so a missing or optional
/// patch does not block it. A patch for another OS is neither applied nor
/// reported missing.
fn patch_file(plan: &RecipePlan) -> Result<Option<&PlanFile>, InstallError> {
    let os = plan.target_os();

    if let Some(patch) = downloadable_for(plan, "patch", os) {
        return Ok(Some(patch));
    }

    if base_file_for(plan, os).is_some_and(is_portable_base) {
        return Ok(None);
    }

    let mut labels = Vec::new();

    for file in plan
        .files
        .iter()
        .filter(|file| file.requirement == "patch" && file.required && file.fits_os(os))
    {
        let label = file_label(file);

        if !labels.contains(&label) {
            labels.push(label);
        }
    }

    if labels.is_empty() {
        Ok(None)
    } else {
        Err(InstallError::MissingFiles(labels.join(", ")))
    }
}

/// Build the download task for one plan file.
///
/// `bearer_origin` is the origin of the server the launcher paired with, never
/// the origin of `file.download_url`: a plan may name a download host, mirror
/// or CDN on a different origin, and the token must not follow the plan there.
/// An `external` file never gets the token at all; a fallback/mirror URL on
/// another origin is filtered by the download engine itself.
pub fn download_task(
    file: &PlanFile,
    dest: &Path,
    bearer: Option<String>,
    bearer_origin: Option<String>,
) -> Result<DownloadTask, InstallError> {
    let url = file
        .download_url
        .clone()
        .or_else(|| file.fallback_url.clone())
        .ok_or_else(|| InstallError::Verification(format!("{} has no URL", file.requirement)))?;
    let filename = file
        .name
        .clone()
        .or_else(|| {
            file.download_url
                .as_deref()
                .and_then(|url| url.rsplit('/').next())
                .map(str::to_string)
        })
        .or_else(|| file.label.clone())
        .unwrap_or_else(|| file.requirement.clone());

    // The token is scoped to the paired LANoMAT origin. An external file never
    // gets it, whatever its source field says; every other file still only
    // receives it when its effective URL happens to be on that paired origin.
    let is_external = file.status == "external" || file.source.as_deref() == Some("external");
    let bearer_origin = if is_external { None } else { bearer_origin };

    Ok(DownloadTask {
        url,
        filename,
        dest_dir: dest.to_path_buf(),
        sha256: file.sha256.clone(),
        size_bytes: file.size_bytes,
        bearer,
        bearer_origin,
    })
}

fn archive_kind(path: &Path) -> Option<ArchiveKind> {
    if detect_iso(path) {
        Some(ArchiveKind::Iso)
    } else if detect_zip(path) {
        Some(ArchiveKind::Zip)
    } else if detect_tar_bz2(path) {
        Some(ArchiveKind::TarBz2)
    } else {
        None
    }
}

fn extract_base(path: &Path, kind: ArchiveKind, target: &Path) -> Result<usize, InstallError> {
    Ok(match kind {
        ArchiveKind::Zip => extract_zip(path, target)?,
        ArchiveKind::TarBz2 => extract_tar_bz2(path, target)?,
        ArchiveKind::Iso => extract_iso(path, target)?,
    })
}

fn expand_home(value: &str) -> PathBuf {
    if let Some(rest) = value.strip_prefix("~/") {
        if let Some(home) = std::env::var_os("HOME").or_else(|| std::env::var_os("USERPROFILE")) {
            return PathBuf::from(home).join(rest);
        }
    }

    PathBuf::from(value)
}

/// The files a complete install must contain, grouped by "at least one of".
///
/// Linux accepts either the `ut2004-bin-amd64` symlink shipped by the
/// OldUnreal patch or the `UT2004` ELF it points at. Both are the same binary;
/// an archive that could not carry the symlink (or a platform without link
/// support, where extraction copies the file) must still verify and launch.
pub fn required_file_groups(os: Os) -> &'static [&'static [&'static str]] {
    match os {
        Os::Windows => &[
            &["System/UT2004.exe"],
            &["System/Build.ini"],
            &["System/D3D11Drv.dll"],
        ],
        Os::Linux | Os::Macos => &[
            &["System/ut2004-bin-amd64", "System/UT2004"],
            &["System/Build.ini"],
        ],
    }
}

/// Flat list of every accepted file, for callers that only need names (the
/// shell's install check). Alternatives of one group both appear; the
/// verifier uses [`required_file_groups`] and needs only one of them.
pub fn required_files(os: Os) -> &'static [&'static str] {
    match os {
        Os::Windows => &[
            "System/UT2004.exe",
            "System/Build.ini",
            "System/D3D11Drv.dll",
        ],
        Os::Linux | Os::Macos => &[
            "System/Build.ini",
            "System/ut2004-bin-amd64",
            "System/UT2004",
        ],
    }
}

/// Verify the install for the current platform.
pub fn verify_install(target: &Path) -> Result<Vec<String>, InstallError> {
    verify_install_for(target, Os::current())
}

/// Verify the install for a given platform (Windows layouts are testable on a
/// Linux CI machine without executing anything).
pub fn verify_install_for(target: &Path, os: Os) -> Result<Vec<String>, InstallError> {
    let mut verified = Vec::new();

    for group in required_file_groups(os) {
        let found = group
            .iter()
            .find(|file| target.join(file).is_file())
            .ok_or_else(|| InstallError::Verification(group.join(" or ")))?;
        verified.push((*found).to_string());
    }

    Ok(verified)
}

fn mark_executable(target: &Path) -> Result<(), InstallError> {
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        for name in [
            "System/ut2004-bin-amd64",
            "System/ut2004-bin",
            "System/UT2004",
            "System/ut2004",
        ] {
            let path = target.join(name);
            if path.is_file() {
                let mut permissions = std::fs::metadata(&path)
                    .map_err(|source| InstallError::Io {
                        path: path.clone(),
                        source,
                    })?
                    .permissions();
                permissions.set_mode(permissions.mode() | 0o111);
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
        plan_for("linux", files)
    }

    fn plan_for(os: &str, files: serde_json::Value) -> RecipePlan {
        serde_json::from_value(serde_json::json!({
            "recipe": {"slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004"},
            "os": os,
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
        assert_eq!(missing_files(&plan), vec!["UT2004 DVD", "OldUnreal Patch"]);
    }

    #[test]
    fn a_matched_archive_satisfies_a_missing_required_iso_and_patch() {
        let plan = plan(serde_json::json!([
            {"requirement": "iso", "status": "missing", "required": true, "label": "UT2004 DVD"},
            {"requirement": "archive", "status": "matched", "download_url": "https://lan/2.zip", "name": "ut2004-portable.zip"},
            {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
        ]));
        // The portable archive is already 3374: neither the ISO nor the patch blocks.
        assert!(missing_files(&plan).is_empty());
        assert!(patch_file(&plan).unwrap().is_none());
    }

    #[test]
    fn an_external_only_base_and_patch_are_downloadable() {
        let plan = plan(serde_json::json!([
            {"requirement": "iso", "status": "external", "required": true, "label": "UT2004 DVD", "download_url": "https://example.test/ut2004.iso", "source": "external"},
            {"requirement": "patch", "status": "external", "required": true, "label": "OldUnreal Patch", "download_url": "https://example.test/patch.tar.bz2", "source": "external"}
        ]));

        assert_eq!(base_file(&plan).unwrap().requirement, "iso");
        assert_eq!(patch_file(&plan).unwrap().unwrap().requirement, "patch");
        assert!(missing_files(&plan).is_empty());
    }

    #[test]
    fn an_external_iso_still_requires_a_patch() {
        let plan = plan(serde_json::json!([
            {"requirement": "iso", "status": "external", "required": true, "label": "UT2004 DVD", "download_url": "https://example.test/ut2004.iso", "source": "external"},
            {"requirement": "patch", "status": "missing", "required": true, "label": "OldUnreal Patch"}
        ]));

        assert_eq!(missing_files(&plan), vec!["OldUnreal Patch"]);
        assert!(patch_file(&plan).is_err());
    }

    fn os_specific_files() -> serde_json::Value {
        serde_json::json!([
            {"requirement": "iso", "status": "matched", "download_url": "https://lan/dvd.iso", "name": "dvd.iso"},
            {"requirement": "base", "os": "windows", "status": "matched", "download_url": "https://lan/portable.zip", "name": "portable.zip"},
            {"requirement": "patch", "os": "windows", "status": "matched", "download_url": "https://lan/patch-win.zip", "name": "patch-win.zip"},
            {"requirement": "patch", "os": "linux", "status": "matched", "download_url": "https://lan/patch-linux.tar.bz2", "name": "patch-linux.tar.bz2"}
        ])
    }

    #[test]
    fn a_linux_plan_takes_the_linux_patch_and_the_iso_base() {
        let plan = plan_for("linux", os_specific_files());

        assert_eq!(base_file(&plan).unwrap().requirement, "iso");
        let patch = patch_file(&plan).unwrap().unwrap();
        assert_eq!(patch.name.as_deref(), Some("patch-linux.tar.bz2"));
        assert!(missing_files(&plan).is_empty());
    }

    #[test]
    fn a_windows_plan_takes_the_windows_patch_and_the_portable_base() {
        let plan = plan_for("windows", os_specific_files());

        assert_eq!(base_file(&plan).unwrap().requirement, "base");
        let patch = patch_file(&plan).unwrap().unwrap();
        assert_eq!(patch.name.as_deref(), Some("patch-win.zip"));
        assert!(missing_files(&plan).is_empty());
    }

    #[test]
    fn a_file_for_another_os_is_never_reported_missing() {
        let files = serde_json::json!([
            {"requirement": "iso", "status": "matched", "download_url": "https://lan/dvd.iso", "name": "dvd.iso"},
            {"requirement": "patch", "os": "windows", "status": "missing", "required": true, "label": "Windows patch"},
            {"requirement": "patch", "os": "linux", "status": "missing", "required": true, "label": "Linux patch"}
        ]);

        assert_eq!(
            missing_files(&plan_for("linux", files.clone())),
            vec!["Linux patch"]
        );
        assert_eq!(
            missing_files(&plan_for("windows", files)),
            vec!["Windows patch"]
        );
    }

    #[test]
    fn an_os_less_file_fits_every_plan() {
        let files = serde_json::json!([
            {"requirement": "iso", "status": "matched", "download_url": "https://lan/dvd.iso", "name": "dvd.iso"}
        ]);

        assert!(base_file(&plan_for("linux", files.clone())).is_some());
        assert!(base_file(&plan_for("windows", files)).is_some());
    }
}
