use std::process::Command;

fn main() {
    tauri_build::build();

    println!("cargo:rustc-env=LANOMAT_LAUNCHER_COMMIT={}", commit());

    for name in ["LANOMAT_LAUNCHER_COMMIT", "GIT_COMMIT", "APP_COMMIT"] {
        println!("cargo:rerun-if-env-changed={name}");
    }
}

/// The build's commit, from the release job when it provides one, otherwise
/// from a checkout. A source build outside git reports `unknown` rather than
/// failing the build.
fn commit() -> String {
    for name in ["LANOMAT_LAUNCHER_COMMIT", "GIT_COMMIT", "APP_COMMIT"] {
        if let Ok(value) = std::env::var(name) {
            let value = value.trim();

            if !value.is_empty() {
                return value.to_string();
            }
        }
    }

    Command::new("git")
        .args(["rev-parse", "--short=12", "HEAD"])
        .output()
        .ok()
        .filter(|output| output.status.success())
        .map(|output| String::from_utf8_lossy(&output.stdout).trim().to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| "unknown".to_string())
}
