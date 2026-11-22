//! Local acceptance helper: install UT2004 from a running LANoMAT server.
//!
//! LANOMAT_URL=http://127.0.0.1:8000 \
//! LANOMAT_TOKEN=lmcp_… \
//! LANOMAT_TARGET=/tmp/ut2004 \
//!   cargo run --example install_local

use std::path::PathBuf;
use std::sync::Arc;

use launcher_core::api::ApiClient;
use launcher_core::download::{origin_of, CancelToken};
use launcher_core::install::{InstallEngine, InstallOptions, InstallProgress};
use launcher_core::types::Os;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let url = std::env::var("LANOMAT_URL").unwrap_or_else(|_| "http://127.0.0.1:8000".into());
    let token = std::env::var("LANOMAT_TOKEN").expect("LANOMAT_TOKEN");
    let target =
        PathBuf::from(std::env::var("LANOMAT_TARGET").unwrap_or_else(|_| "/tmp/ut2004".into()));
    let api = ApiClient::new(&url)?.with_token(&token);
    let plan = api.plan("unreal-tournament-2004", Os::current()).await?;
    // The token only ever travels to the paired server, never to a file host
    // named by the plan.
    let bearer_origin = origin_of(api.base_url());

    let progress = Arc::new(|update: InstallProgress| {
        println!(
            "{} {:>3}% {}",
            update.message,
            update.percent.unwrap_or(0.0),
            update
                .file
                .as_deref()
                .map(|file| format!("({file})"))
                .unwrap_or_default()
        );
    });

    let outcome = InstallEngine::install(InstallOptions {
        plan,
        bearer: Some(token),
        bearer_origin,
        target_dir: Some(target),
        cancel: CancelToken::new(),
        on_progress: progress,
    })
    .await?;

    println!("{outcome:?}");
    Ok(())
}
