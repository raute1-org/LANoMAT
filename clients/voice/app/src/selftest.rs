//! Headless self-test of the command layer through the real IPC (cargo feature `selftest`, never
//! part of a release build). After start the app evaluates a script in the main webview that
//! calls the commands exactly as the UI does (`window.__TAURI__.core.invoke(name, {camelCase})`)
//! and reports lines back through `selftest_report`; `selftest_done` ends the run. That proves
//! the registration, the argument names and the serialised shapes, which calling the Rust
//! functions directly would not.
//!
//! Environment: `LANOMAT_SELFTEST_JS` (path of the script), `LANOMAT_SELFTEST_PARAMS` (JSON that
//! the script sees as `window.__SELFTEST`), `LANOMAT_SELFTEST_OUT` (file that collects the
//! report lines).

use std::io::Write;
use std::time::Duration;

use tauri::{AppHandle, Manager};

/// Appends one report line (`PASS ...` or `FAIL ...`) to `LANOMAT_SELFTEST_OUT` and stderr.
#[tauri::command]
pub fn selftest_report(line: String) {
    eprintln!("selftest: {line}");
    if let Ok(path) = std::env::var("LANOMAT_SELFTEST_OUT") {
        if let Ok(mut f) = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
        {
            let _ = writeln!(f, "{line}");
        }
    }
}

/// Runs the script named by `LANOMAT_SELFTEST_HOOK` with `args` (a screenshot or a stand-in
/// scenario switch, which a script in the webview cannot do itself) and returns its stdout. The
/// webview keeps painting meanwhile. Test-only like everything in this module.
#[tauri::command]
pub async fn selftest_hook(args: String) -> String {
    let Ok(script) = std::env::var("LANOMAT_SELFTEST_HOOK") else {
        return "no hook".into();
    };
    tokio::task::spawn_blocking(move || {
        std::process::Command::new("sh")
            .arg(script)
            .args(args.split_whitespace())
            .output()
            .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string())
            .unwrap_or_else(|e| format!("hook failed: {e}"))
    })
    .await
    .unwrap_or_default()
}

/// Ends the run; a non-zero `failures` makes the process exit with 1.
#[tauri::command]
pub fn selftest_done(app: AppHandle, failures: i32) {
    app.exit(if failures == 0 { 0 } else { 1 });
}

pub fn arm(app: &AppHandle) {
    let Ok(js_path) = std::env::var("LANOMAT_SELFTEST_JS") else {
        return;
    };
    let params = std::env::var("LANOMAT_SELFTEST_PARAMS").unwrap_or_else(|_| "{}".into());
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(Duration::from_millis(2500)).await;
        let script = match std::fs::read_to_string(&js_path) {
            Ok(s) => s,
            Err(e) => {
                selftest_report(format!("FAIL setup cannot read the script: {e}"));
                app.exit(1);
                return;
            }
        };
        if let Some(w) = app.get_webview_window("main") {
            let _ = w.eval(format!("window.__SELFTEST = {params};\n{script}"));
        } else {
            selftest_report("FAIL setup no main window".into());
            app.exit(1);
        }
    });
}
