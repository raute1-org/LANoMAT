//! The UT2004 pilot installer: plan -> downloads -> ISO/archive/DMG -> patch ->
//! verify. The result is a launchable install, nothing else.
//!
//! The retail path is deliberately explicit: the DVD ISO (or a macOS DMG)
//! supplies the payload folders, the OldUnreal 3374 patch supplies the complete
//! `System/` tree. A portable `archive`/`base` requirement short-circuits the
//! ISO path: it is extracted as a flat game tree, with one wrapper folder
//! stripped, and must still carry real game data outside `System/`.

use std::path::{Component, Path, PathBuf};
use std::sync::Arc;

use serde::{Deserialize, Serialize};

use crate::archive::{
    detect_dmg, detect_iso, detect_tar_bz2, detect_zip, extract_dmg, extract_iso, extract_tar_bz2,
    extract_tar_bz2_base, extract_zip, extract_zip_base, extract_zip_entry, zip_single_iso_entry,
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
    Dmg,
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
        let generic = !is_ut2004_plan(plan);

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

        // The base and patch keep their roles; every other matched LAN file
        // (an ASI plugin, a loader DLL, a config) is an optional extra for the
        // generic path. Deduplicate by file name so two OS-specific base
        // requirements can never download the same archive twice.
        let mut planned: Vec<(&'static str, DownloadTask)> = Vec::new();
        let mut seen_names = std::collections::HashSet::new();

        let push_task = |role: &'static str,
                         file: &PlanFile,
                         planned: &mut Vec<(&'static str, DownloadTask)>,
                         seen: &mut std::collections::HashSet<String>|
         -> Result<(), InstallError> {
            let task = download_task(
                file,
                &downloads_dir,
                options.bearer.clone(),
                options.bearer_origin.clone(),
            )?;
            let key = task.filename.to_ascii_lowercase();

            if seen.insert(key) {
                planned.push((role, task));
            }

            Ok(())
        };

        push_task("base", base, &mut planned, &mut seen_names)?;

        if let Some(patch) = patch {
            push_task("patch", patch, &mut planned, &mut seen_names)?;
        }

        if generic {
            for extra in extra_plan_files(plan) {
                push_task("extra", extra, &mut planned, &mut seen_names)?;
            }
        }

        let patch_index = planned.iter().position(|(role, _)| *role == "patch");
        let extra_indices = planned
            .iter()
            .enumerate()
            .filter(|(_, (role, _))| *role == "extra")
            .map(|(index, _)| index)
            .collect::<Vec<_>>();

        let tasks: Vec<DownloadTask> = planned.into_iter().map(|(_, task)| task).collect();
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
        let patch_path = patch_index.map(|index| &paths[index]);
        let extra_paths = extra_indices
            .iter()
            .filter_map(|index| paths.get(*index))
            .collect::<Vec<_>>();

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
        let used_archive = matches!(base_kind, ArchiveKind::Zip | ArchiveKind::TarBz2);
        let mut files_extracted = extract_base(base_path, base_kind, &target, &downloads_dir)?;

        if generic {
            // A portable LAN archive often wraps the game in one titled folder
            // (`Need for Speed - Underground 2/SPEED.EXE`). The plan's verify
            // paths are the authority; hoist that wrapper before checking them.
            hoist_game_wrapper(&target, plan)?;
        } else {
            // A base that is only an installer (`setup.exe`) is not a game, even
            // when a patch supplies `System/`. Check the extracted base before the
            // patch so the patch can never make an installer look complete.
            ensure_game_payload(&target)?;
        }

        if let Some(patch_path) = patch_path {
            progress(InstallProgress::phase(
                InstallPhase::Patching,
                "Patch wird eingespielt",
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

        if generic {
            files_extracted += materialize_extra_files(&target, plan, &extra_paths)?;
            hoist_game_wrapper(&target, plan)?;
        }

        progress(InstallProgress::phase(
            InstallPhase::Verifying,
            "Installation wird geprüft",
        ));
        // Verify the layout for the platform the plan was built for, not the
        // machine running the test suite: a Windows base must stay checkable
        // on Linux CI and a macOS base must not fall back to the Linux layout.
        let verified = if generic {
            verify_plan(&target, plan)?
        } else {
            verify_install_for(&target, plan.target_os())?
        };

        if generic {
            mark_plan_executable(&target, plan)?;
        } else {
            mark_executable(&target)?;
        }

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

    /// Start the game for the current platform. Returns the executable (or the
    /// macOS app bundle) that was started. Nothing is started during
    /// verification.
    pub fn launch(target_dir: &Path) -> Result<PathBuf, InstallError> {
        Self::launch_for(target_dir, Os::current())
    }

    /// [`Self::launch`] for an explicit platform, so the macOS bundle path is
    /// testable without running on macOS.
    pub fn launch_for(target_dir: &Path, os: Os) -> Result<PathBuf, InstallError> {
        Self::launch_for_join(target_dir, os, None)
    }

    /// Start the game already connected to one server. UT2004 takes a
    /// `host:port` as its first argument, so the one-click join does not need
    /// the game's URL handler to be registered on a fresh install.
    pub fn launch_join(target_dir: &Path, address: &str) -> Result<PathBuf, InstallError> {
        Self::launch_for_join(target_dir, Os::current(), Some(address))
    }

    fn launch_for_join(
        target_dir: &Path,
        os: Os,
        join: Option<&str>,
    ) -> Result<PathBuf, InstallError> {
        match os {
            Os::Macos => launch_macos(target_dir, join),
            Os::Windows | Os::Linux => launch_system_binary(target_dir, os, join),
        }
    }

    /// Start a generic (non-UT2004) install from the plan's launch target for
    /// the plan's OS. UT2004 keeps its dedicated `System/` launcher.
    pub fn launch_plan(target_dir: &Path, plan: &RecipePlan) -> Result<PathBuf, InstallError> {
        if is_ut2004_plan(plan) {
            return Self::launch_for(target_dir, plan.target_os());
        }

        let os = plan.target_os();
        let command = launch_command_for(plan, os).ok_or_else(|| {
            InstallError::Launch(format!(
                "{} hat kein Startziel für {}",
                plan.recipe.slug,
                os.as_str()
            ))
        })?;

        Self::launch_command(target_dir, &command, os)
    }

    /// Start an already installed generic game from a stored launch command
    /// (the shell keeps it in the install ledger so launch works offline).
    pub fn launch_stored(target_dir: &Path, command: &str) -> Result<PathBuf, InstallError> {
        Self::launch_command(target_dir, command, Os::current())
    }

    /// Run one plan launch command. `{{target}}` is substituted; a Windows
    /// `.exe` is started directly, while Wine/Proton and shell-style Linux
    /// commands run through `sh -c` from the install folder.
    pub fn launch_command(
        target_dir: &Path,
        command: &str,
        os: Os,
    ) -> Result<PathBuf, InstallError> {
        launch_plan_command(target_dir, command, os)
    }
}

fn launch_system_binary(
    target_dir: &Path,
    os: Os,
    join: Option<&str>,
) -> Result<PathBuf, InstallError> {
    let system = target_dir.join("System");
    let candidates = launch_candidates(&system, os);

    let exe = candidates
        .into_iter()
        .find(|path| path.is_file())
        .ok_or_else(|| InstallError::Verification(format!("{} fehlt", launch_name(os))))?;

    spawn_game(&exe, join)?;

    Ok(exe)
}

/// Start a macOS install through LaunchServices (`open <app>`), which gives the
/// bundle its own working directory and resources. If `open` is unavailable or
/// fails, the bundle's own executable is started directly.
fn launch_macos(target_dir: &Path, join: Option<&str>) -> Result<PathBuf, InstallError> {
    if let Some(app) = macos_app_bundle(target_dir) {
        let mut open = std::process::Command::new("open");
        open.arg(&app);

        if let Some(address) = join {
            open.args(["--args", address]);
        }

        let open_error = match open.status() {
            Ok(status) if status.success() => return Ok(app),
            Ok(status) => format!("open exited with {status}"),
            Err(error) => format!("open: {error}"),
        };

        let binary = app.join("Contents/MacOS/UT2004");

        if binary.is_file() {
            spawn_game(&binary, join)?;
            return Ok(binary);
        }

        return Err(InstallError::Launch(format!(
            "could not start {}: {open_error}",
            app.display()
        )));
    }

    // A portable mac base may keep only the bundle's executable tree.
    let binary = target_dir.join("Contents/MacOS/UT2004");

    if binary.is_file() {
        spawn_game(&binary, join)?;
        return Ok(binary);
    }

    Err(InstallError::Verification(format!(
        "{} fehlt",
        launch_name(Os::Macos)
    )))
}

fn spawn_game(exe: &Path, join: Option<&str>) -> Result<(), InstallError> {
    let mut command = std::process::Command::new(exe);

    if let Some(directory) = exe.parent() {
        command.current_dir(directory);
    }

    if let Some(address) = join {
        command.arg(address);
    }

    command
        .spawn()
        .map(|_| ())
        .map_err(|error| InstallError::Launch(error.to_string()))
}

fn macos_app_bundle(target_dir: &Path) -> Option<PathBuf> {
    let named = target_dir.join("UT2004.app");

    if named.is_dir() {
        return Some(named);
    }

    let mut bundles = std::fs::read_dir(target_dir)
        .ok()?
        .flatten()
        .map(|entry| entry.path())
        .filter(|path| {
            path.is_dir()
                && path
                    .extension()
                    .is_some_and(|extension| extension.eq_ignore_ascii_case("app"))
        })
        .collect::<Vec<_>>();
    bundles.sort();

    bundles.into_iter().next()
}

fn launch_candidates(system: &Path, os: Os) -> Vec<PathBuf> {
    match os {
        Os::Windows => vec![system.join("UT2004.exe")],
        Os::Linux | Os::Macos => vec![
            system.join("ut2004-bin-amd64"),
            system.join("UT2004"),
            system.join("ut2004"),
        ],
    }
}

fn launch_name(os: Os) -> &'static str {
    match os {
        Os::Windows => "System/UT2004.exe",
        Os::Linux => "System/ut2004-bin-amd64 or System/UT2004",
        Os::Macos => "UT2004.app (Contents/MacOS/UT2004)",
    }
}

/// The pilot recipe keeps its dedicated UT2004 `System/` installer and
/// verifier. Every other recipe uses the plan's own verify steps and launch
/// target; there is no per-game code for it.
pub fn is_ut2004_plan(plan: &RecipePlan) -> bool {
    plan.recipe.slug == "unreal-tournament-2004"
}

/// Matched LAN files that are not the base or patch: an ASI plugin, a loader
/// DLL, a config. They are downloaded for the generic path so the plan's
/// `file_exists` checks can be satisfied from the LAN instead of the archive.
fn extra_plan_files(plan: &RecipePlan) -> Vec<&PlanFile> {
    let os = plan.target_os();

    plan.files
        .iter()
        .filter(|file| {
            matches!(
                file.requirement.as_str(),
                "mod" | "tool" | "config" | "content"
            )
        })
        .filter(|file| matches!(file.status.as_str(), "matched" | "external"))
        .filter(|file| file.download_url.is_some() || file.fallback_url.is_some())
        .filter(|file| file.fits_os(os))
        .collect()
}

/// The plan's launch command for one OS. An exact OS target wins over an
/// `any` target; the first one is the default (for example Wine over Proton).
pub fn launch_command_for(plan: &RecipePlan, os: Os) -> Option<String> {
    let exact = plan.recipe.launch_targets.iter().find(|target| {
        target
            .os
            .as_deref()
            .and_then(Os::from_wire)
            .is_some_and(|named| named == os)
    });

    let any = plan.recipe.launch_targets.iter().find(|target| {
        target
            .os
            .as_deref()
            .map(|value| value.is_empty() || value.eq_ignore_ascii_case("any"))
            .unwrap_or(true)
    });

    exact
        .or(any)
        .and_then(|target| target.command.clone())
        .or_else(|| plan.recipe.launch_target.clone())
}

fn launch_plan_command(target_dir: &Path, command: &str, os: Os) -> Result<PathBuf, InstallError> {
    let expanded = command.replace("{{target}}", &target_dir.to_string_lossy());
    let exe = find_exe_path(&expanded);

    match os {
        Os::Windows => {
            // The plan's Windows target is an `.exe` (possibly wrapped in
            // `Start-Process`): start the executable itself, not a shell.
            if let Some(path) = exe
                .as_deref()
                .and_then(|token| resolve_command_path(target_dir, token))
            {
                if path.is_file() {
                    spawn_from(&path, target_dir)?;
                    return Ok(path);
                }
            }

            let mut process = std::process::Command::new("powershell");
            process
                .args(["-NoProfile", "-ExecutionPolicy", "Bypass", "-Command"])
                .arg(&expanded)
                .current_dir(target_dir);
            process
                .spawn()
                .map_err(|error| InstallError::Launch(error.to_string()))?;

            Ok(PathBuf::from("powershell"))
        }
        Os::Linux | Os::Macos => {
            if needs_shell(&expanded) {
                let mut process = std::process::Command::new("sh");
                process.arg("-c").arg(&expanded).current_dir(target_dir);
                process
                    .spawn()
                    .map_err(|error| InstallError::Launch(error.to_string()))?;

                // For a Wine/Proton run the real process is the launcher
                // binary, not speed.exe; returning it keeps the heartbeat's
                // "playing" state alive until the game exits.
                if let Some(program) = shell_program(&expanded) {
                    let program_lower = program.to_ascii_lowercase();

                    if matches!(program_lower.as_str(), "wine" | "wine64" | "proton") {
                        if let Some(path) = which(&program) {
                            return Ok(path);
                        }
                    }
                }

                if let Some(path) = exe
                    .as_deref()
                    .and_then(|token| resolve_command_path(target_dir, token))
                {
                    return Ok(path);
                }

                if let Some(program) = shell_program(&expanded) {
                    if let Some(path) = which(&program) {
                        return Ok(path);
                    }

                    return Ok(PathBuf::from(program));
                }

                return Ok(target_dir.to_path_buf());
            }

            let token =
                first_command_word(&expanded).unwrap_or_else(|| expanded.trim().to_string());
            let path = resolve_command_path(target_dir, &token)
                .ok_or_else(|| InstallError::Verification(format!("Startziel fehlt: {token}")))?;

            spawn_from(&path, target_dir)?;

            Ok(path)
        }
    }
}

/// Start an executable with the game folder as working directory. NFSU2 reads
/// its data relative to `SPEED.EXE`, so the install root (not the binary's
/// parent in a nested tree) is the safe cwd for a generic plan.
fn spawn_from(exe: &Path, working_dir: &Path) -> Result<(), InstallError> {
    std::process::Command::new(exe)
        .current_dir(working_dir)
        .spawn()
        .map(|_| ())
        .map_err(|error| InstallError::Launch(error.to_string()))
}

fn needs_shell(command: &str) -> bool {
    let first = first_command_word(command)
        .unwrap_or_default()
        .to_ascii_lowercase();

    first == "wine"
        || first == "wine64"
        || first == "proton"
        || command.contains('=')
        || command
            .chars()
            .any(|c| matches!(c, '|' | '&' | ';' | '<' | '>' | '$' | '`' | '\n' | '\r'))
}

fn first_command_word(command: &str) -> Option<String> {
    let trimmed = command.trim();
    let first = trimmed.chars().next()?;

    if first == '"' || first == '\'' {
        let rest = &trimmed[first.len_utf8()..];
        let end = rest.find(first)?;

        return Some(rest[..end].to_string());
    }

    trimmed
        .split_whitespace()
        .next()
        .map(|word| word.trim_matches(['"', '\'']).to_string())
}

/// The executable after any leading `KEY=value` environment assignments.
fn shell_program(command: &str) -> Option<String> {
    command
        .split_whitespace()
        .map(|word| word.trim_matches(['"', '\'']))
        .find(|word| !word.contains('=') && !word.starts_with('-'))
        .map(str::to_string)
}

/// The first token of a launch command that names an `.exe`. Both
/// `C:\Games\NFSU2\speed.exe` and `-FilePath "…\speed.exe"` work.
fn find_exe_path(command: &str) -> Option<String> {
    command
        .split_whitespace()
        .map(|token| token.trim_matches(['"', '\'']))
        .find(|token| token.to_ascii_lowercase().ends_with(".exe"))
        .map(str::to_string)
}

fn resolve_command_path(target: &Path, token: &str) -> Option<PathBuf> {
    let path = Path::new(token);

    if path.is_absolute() {
        return resolve_case_insensitive(path);
    }

    resolve_case_insensitive(&target.join(path))
}

fn which(program: &str) -> Option<PathBuf> {
    let path = std::env::var_os("PATH")?;

    for directory in std::env::split_paths(&path) {
        for name in [program.to_string(), format!("{program}.exe")] {
            let candidate = directory.join(name);

            if candidate.is_file() {
                return Some(candidate);
            }
        }
    }

    None
}

/// Verify a generic plan from its `steps[].verify` entries. Optional steps do
/// not block the install; `file_exists` and `dir_exists` are the checks the
/// native path can prove without running the game.
pub fn verify_plan(target: &Path, plan: &RecipePlan) -> Result<Vec<String>, InstallError> {
    let os = plan.target_os();
    let mut verified = Vec::new();

    for step in &plan.steps {
        if step.optional {
            continue;
        }

        for check in &step.verify {
            if !check.fits_os(os) {
                continue;
            }

            let Some(raw) = check.path.as_deref() else {
                continue;
            };

            let Some(path) = resolve_plan_path(target, raw) else {
                return Err(InstallError::Verification(display_plan_path(raw)));
            };

            let found = match check.kind.as_str() {
                "file_exists" => path.is_file(),
                "dir_exists" => path.is_dir(),
                // A `command` check is for the agent; the launcher proves the
                // file layout without executing game code here.
                _ => true,
            };

            if !found {
                return Err(InstallError::Verification(display_plan_path(raw)));
            }

            let label = display_plan_path(raw);

            if !verified.contains(&label) {
                verified.push(label);
            }
        }
    }

    Ok(verified)
}

/// Copy or unpack matched extra files (ASI plugin, loader, config) into the
/// exact path the plan verifies. A file with no matching verify path lands in
/// `scripts/` when it is an ASI/INI and at the game root otherwise.
fn materialize_extra_files(
    target: &Path,
    plan: &RecipePlan,
    extras: &[&PathBuf],
) -> Result<usize, InstallError> {
    if extras.is_empty() {
        return Ok(0);
    }

    let expected = plan_expected_paths(target, plan);
    let mut written = 0;

    for extra in extras {
        if !extra.is_file() {
            continue;
        }

        if detect_zip(extra) {
            written += extract_zip(extra, target)?;
            continue;
        }

        if detect_tar_bz2(extra) {
            written += extract_tar_bz2(extra, target)?;
            continue;
        }

        let Some(name) = extra.file_name().and_then(|name| name.to_str()) else {
            continue;
        };
        let lower = name.to_ascii_lowercase();
        let destination = expected
            .iter()
            .find(|(expected_name, path)| {
                expected_name.eq_ignore_ascii_case(&lower)
                    && !path.exists()
                    && path.starts_with(target)
            })
            .map(|(_, path)| path.clone())
            .unwrap_or_else(|| {
                if lower.ends_with(".asi") || lower.ends_with(".ini") {
                    target.join("scripts").join(name)
                } else {
                    target.join(name)
                }
            });

        if let Some(parent) = destination.parent() {
            std::fs::create_dir_all(parent).map_err(|source| InstallError::Io {
                path: parent.to_path_buf(),
                source,
            })?;
        }

        std::fs::copy(extra, &destination).map_err(|source| InstallError::Io {
            path: destination.clone(),
            source,
        })?;
        written += 1;
    }

    Ok(written)
}

/// The basename -> target path pairs the plan expects, used to place an extra
/// file where `verify` looks for it (`scripts/net_u2.asi`, `dinput8.dll`).
fn plan_expected_paths(target: &Path, plan: &RecipePlan) -> Vec<(String, PathBuf)> {
    let mut expected = Vec::new();

    for step in &plan.steps {
        if step.optional {
            continue;
        }

        for check in &step.verify {
            if !check.fits_os(plan.target_os())
                || !matches!(check.kind.as_str(), "file_exists" | "dir_exists")
            {
                continue;
            }

            let Some(raw) = check.path.as_deref() else {
                continue;
            };
            let Some(relative) = relative_plan_path(raw) else {
                continue;
            };
            let Some(name) = relative.rsplit('/').next() else {
                continue;
            };
            let path = resolve_under(target, &relative).unwrap_or_else(|| target.join(&relative));

            expected.push((name.to_ascii_lowercase(), path));
        }
    }

    expected
}

/// Hoist one wrapper folder (`Need for Speed - Underground 2/…`) when every
/// plan verify path is missing at the root but present one level down.
fn hoist_game_wrapper(target: &Path, plan: &RecipePlan) -> Result<(), InstallError> {
    let checks = plan_relative_checks(plan);

    if checks.is_empty() {
        return Ok(());
    }

    let missing = checks
        .iter()
        .filter(|relative| resolve_under(target, relative).is_none())
        .collect::<Vec<_>>();

    if missing.is_empty() {
        return Ok(());
    }

    let candidates = std::fs::read_dir(target)
        .map_err(|source| InstallError::Io {
            path: target.to_path_buf(),
            source,
        })?
        .flatten()
        .map(|entry| entry.path())
        .filter(|path| path.is_dir())
        .collect::<Vec<_>>();

    for wrapper in candidates {
        let has_game = missing
            .iter()
            .any(|relative| resolve_under(&wrapper, relative).is_some());

        if !has_game {
            continue;
        }

        merge_move(&wrapper, target)?;
        let _ = std::fs::remove_dir(&wrapper);
        return Ok(());
    }

    Ok(())
}

fn merge_move(source: &Path, target: &Path) -> Result<(), InstallError> {
    let entries = std::fs::read_dir(source).map_err(|error| InstallError::Io {
        path: source.to_path_buf(),
        source: error,
    })?;

    for entry in entries.flatten() {
        let from = entry.path();
        let to = target.join(entry.file_name());
        let from_is_dir = std::fs::symlink_metadata(&from)
            .map(|metadata| metadata.is_dir())
            .unwrap_or(false);

        if to.exists() {
            if from_is_dir && to.is_dir() {
                merge_move(&from, &to)?;
            } else if to.is_file() {
                std::fs::remove_file(&to).map_err(|error| InstallError::Io {
                    path: to.clone(),
                    source: error,
                })?;
                std::fs::rename(&from, &to).map_err(|error| InstallError::Io {
                    path: to.clone(),
                    source: error,
                })?;
            }

            continue;
        }

        std::fs::rename(&from, &to).map_err(|error| InstallError::Io {
            path: to.clone(),
            source: error,
        })?;
    }

    Ok(())
}

fn plan_relative_checks(plan: &RecipePlan) -> Vec<String> {
    let os = plan.target_os();
    let mut relative = Vec::new();

    for step in &plan.steps {
        if step.optional {
            continue;
        }

        for check in &step.verify {
            if !check.fits_os(os) || !matches!(check.kind.as_str(), "file_exists" | "dir_exists") {
                continue;
            }

            if let Some(path) = check.path.as_deref().and_then(relative_plan_path) {
                relative.push(path);
            }
        }
    }

    relative
}

fn relative_plan_path(raw: &str) -> Option<String> {
    let normalized = raw.replace('\\', "/").replace("{{target}}", "");
    let relative = normalized.trim_start_matches('/');

    if relative.is_empty()
        || relative.starts_with("~/")
        || relative.split('/').any(|part| part == "..")
    {
        return None;
    }

    Some(relative.to_string())
}

fn display_plan_path(raw: &str) -> String {
    relative_plan_path(raw).unwrap_or_else(|| raw.to_string())
}

fn resolve_plan_path(target: &Path, raw: &str) -> Option<PathBuf> {
    let normalized = raw.replace('\\', "/");

    if normalized.starts_with("~/") {
        return resolve_case_insensitive(&expand_home(&normalized));
    }

    let without_target = normalized.replace("{{target}}", "");

    if without_target.starts_with('/') && normalized == without_target {
        return resolve_case_insensitive(Path::new(&without_target));
    }

    let relative = without_target.trim_start_matches('/');

    if relative.is_empty() || relative.split('/').any(|part| part == "..") {
        return None;
    }

    resolve_under(target, relative)
}

fn resolve_under(base: &Path, relative: &str) -> Option<PathBuf> {
    resolve_case_insensitive(&base.join(relative))
}

/// Resolve each path component case-insensitively. Windows installs are
/// checked from Linux CI, and the NFSU2 archive stores `SPEED.EXE` while the
/// recipe names `speed.exe`.
fn resolve_case_insensitive(path: &Path) -> Option<PathBuf> {
    let mut current = PathBuf::new();

    for component in path.components() {
        match component {
            Component::Prefix(_) | Component::RootDir | Component::CurDir => {
                current.push(component.as_os_str());
            }
            Component::ParentDir => return None,
            Component::Normal(name) => {
                let candidate = current.join(name);

                if candidate.exists() {
                    current = candidate;
                    continue;
                }

                let directory = if current.as_os_str().is_empty() {
                    Path::new(".")
                } else {
                    current.as_path()
                };
                let found = std::fs::read_dir(directory).ok()?.flatten().find(|entry| {
                    entry
                        .file_name()
                        .to_string_lossy()
                        .eq_ignore_ascii_case(&name.to_string_lossy())
                })?;
                current = found.path();
            }
        }
    }

    current.exists().then_some(current)
}

fn mark_plan_executable(target: &Path, plan: &RecipePlan) -> Result<(), InstallError> {
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        if let Some(command) = launch_command_for(plan, plan.target_os()) {
            let expanded = command.replace("{{target}}", &target.to_string_lossy());

            if let Some(path) =
                find_exe_path(&expanded).and_then(|token| resolve_command_path(target, &token))
            {
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
    }

    let _ = (target, plan);

    Ok(())
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
    } else if detect_dmg(path) {
        Some(ArchiveKind::Dmg)
    } else if detect_zip(path) {
        Some(ArchiveKind::Zip)
    } else if detect_tar_bz2(path) {
        Some(ArchiveKind::TarBz2)
    } else {
        None
    }
}

fn extract_base(
    path: &Path,
    kind: ArchiveKind,
    target: &Path,
    scratch: &Path,
) -> Result<usize, InstallError> {
    Ok(match kind {
        ArchiveKind::Zip => {
            if let Some(entry) = zip_single_iso_entry(path)? {
                // An installer wrapper (`setup.exe` + one ISO): extract the
                // image to a temporary file and use the retail path on it.
                let nested = nested_iso_path(scratch);
                let result: Result<usize, InstallError> = (|| {
                    extract_zip_entry(path, &entry, &nested)?;
                    Ok(extract_iso(&nested, target)?)
                })();
                let _ = std::fs::remove_file(&nested);
                result?
            } else {
                extract_zip_base(path, target)?
            }
        }
        ArchiveKind::TarBz2 => extract_tar_bz2_base(path, target)?,
        ArchiveKind::Iso => extract_iso(path, target)?,
        ArchiveKind::Dmg => extract_dmg(path, target)?,
    })
}

fn nested_iso_path(scratch: &Path) -> PathBuf {
    use std::time::{SystemTime, UNIX_EPOCH};

    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_nanos())
        .unwrap_or_default();

    scratch.join(format!(
        ".lanomat-nested-{}-{nanos}.iso",
        std::process::id()
    ))
}

/// Whether the extracted base carries at least one real game file outside
/// `System/` (a map or texture). The check is recursive because a macOS base
/// keeps `Maps/` inside `UT2004.app/Contents/Resources/`.
fn ensure_game_payload(target: &Path) -> Result<(), InstallError> {
    if has_game_payload(target) {
        return Ok(());
    }

    Err(InstallError::Verification(
        "Basis-Archiv enthält keine Spieldaten (Maps/Textures)".into(),
    ))
}

fn has_game_payload(directory: &Path) -> bool {
    let Ok(entries) = std::fs::read_dir(directory) else {
        return false;
    };

    for entry in entries.flatten() {
        let path = entry.path();
        let Ok(metadata) = std::fs::symlink_metadata(&path) else {
            continue;
        };

        if metadata.file_type().is_symlink() {
            continue;
        }

        if metadata.is_dir() {
            if entry
                .file_name()
                .to_string_lossy()
                .eq_ignore_ascii_case("System")
            {
                continue;
            }

            if has_game_payload(&path) {
                return true;
            }
        } else if metadata.is_file() && is_payload_file(&path) {
            return true;
        }
    }

    false
}

fn is_payload_file(path: &Path) -> bool {
    let extension = path
        .extension()
        .map(|extension| extension.to_string_lossy().to_ascii_lowercase())
        .unwrap_or_default();
    let folder = path
        .parent()
        .and_then(Path::file_name)
        .map(|name| name.to_string_lossy().to_ascii_lowercase())
        .unwrap_or_default();

    match folder.as_str() {
        "maps" => matches!(extension.as_str(), "ut2" | "ut3"),
        "textures" => extension == "utx",
        "sounds" => matches!(extension.as_str(), "uax" | "ogg"),
        "animations" => extension == "ukx",
        "staticmeshes" => extension == "usx",
        _ => false,
    }
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
        Os::Linux => &[
            &["System/ut2004-bin-amd64", "System/UT2004"],
            &["System/Build.ini"],
        ],
        // A macOS install is an app bundle: the executable lives in
        // `Contents/MacOS`, the system data in `Contents/Resources/System`.
        // `System/Build.ini` is accepted as the fallback for a flat portable
        // tree, but the Linux binary never satisfies the mac branch.
        Os::Macos => &[
            &["UT2004.app/Contents/MacOS/UT2004"],
            &[
                "UT2004.app/Contents/Resources/System/Packages.md5",
                "System/Packages.md5",
                "System/Build.ini",
            ],
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
        Os::Linux => &[
            "System/Build.ini",
            "System/ut2004-bin-amd64",
            "System/UT2004",
        ],
        Os::Macos => &[
            "UT2004.app/Contents/MacOS/UT2004",
            "UT2004.app/Contents/Resources/System/Packages.md5",
            "System/Packages.md5",
            "System/Build.ini",
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
            "UT2004.app/Contents/MacOS/UT2004",
            "Contents/MacOS/UT2004",
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
