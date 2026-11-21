//! LANoMAT launcher core: device-flow login, install plan, downloads and the
//! UT2004 install engine. No UI, no Tauri; the desktop shell only renders the
//! state and progress this crate produces.

pub mod api;
pub mod archive;
pub mod auth;
pub mod download;
pub mod install;
pub mod state;
pub mod types;

pub use install::{InstallEngine, InstallOptions, InstallProgress, InstallPhase, InstallOutcome};
pub use types::{PlanFile, RecipePlan, RecipeSummary, Os};
