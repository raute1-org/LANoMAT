//! SPIKE, not for production (all-in-one client, phase 0, task 0.5).
//!
//! Receives `lanomat://install|launch|join/<slug>` links in the voice app, runs them through the
//! pure parser in [`crate::deeplink`] (copied from `clients/launcher/app/src/deeplink.rs`) and only
//! logs them and emits a `deep-link` event. Nothing is installed or started: the confirmation
//! flow is phase 3. Cold start (link as process argument) and warm start (second instance hands
//! its arguments over through `tauri-plugin-single-instance`) end in the same handler.

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_deep_link::DeepLinkExt;

use crate::deeplink::{parse_deep_link, DeepLink, DeepLinkEvent};

pub const EVENT_DEEP_LINK: &str = "deep-link";

/// The link a start-up argument list should queue: every unparseable entry is skipped and the
/// last valid one wins. Pure, so it is testable without a Tauri app.
pub fn select_start_up_link(urls: impl DoubleEndedIterator<Item = String>) -> Option<DeepLink> {
    urls.filter_map(|raw| parse_deep_link(&raw)).next_back()
}

pub fn focus_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

fn handle_raw(app: &AppHandle, raw: &str) {
    match parse_deep_link(raw) {
        Some(link) => {
            // The raw string is not logged: it may carry a join address.
            tracing::info!(
                target: "lanomat::deeplink",
                action = link.action.as_str(),
                slug = link.slug.as_str(),
                "deep link accepted (spike: logged only, nothing executed)"
            );
            let _ = app.emit(EVENT_DEEP_LINK, DeepLinkEvent::from(link));
            focus_main(app);
        }
        None => tracing::info!(
            target: "lanomat::deeplink",
            "ignored a link that is not lanomat://install|launch|join/<slug>"
        ),
    }
}

/// Registers the scheme (best effort, see below) and starts listening.
pub fn wire(app: &AppHandle) {
    // Windows installer and Linux packages register the scheme themselves (NSIS registry key from
    // tauri.conf.json `plugins.deep-link`, `.desktop` MimeType). `register_all` only matters for
    // a portable exe / source checkout and is best effort.
    #[cfg(any(windows, target_os = "linux"))]
    if let Err(error) = app.deep_link().register_all() {
        tracing::debug!(target: "lanomat::deeplink", %error, "could not register lanomat://");
    }

    // Cold start: the link the process was started with.
    if let Ok(Some(urls)) = app.deep_link().get_current() {
        if let Some(link) = select_start_up_link(urls.into_iter().map(|u| u.to_string())) {
            tracing::info!(
                target: "lanomat::deeplink",
                action = link.action.as_str(),
                slug = link.slug.as_str(),
                "start-up deep link (spike: logged only)"
            );
            let _ = app.emit(EVENT_DEEP_LINK, DeepLinkEvent::from(link));
        }
    }

    // Warm start: links from a second instance (single-instance feature `deep-link`) and, on
    // macOS, the OS open-url event.
    let handle = app.clone();
    app.deep_link().on_open_url(move |event| {
        for url in event.urls() {
            handle_raw(&handle, url.as_str());
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::deeplink::DeepLinkAction;

    #[test]
    fn the_last_valid_start_up_argument_wins() {
        let args = [
            "lanomat-voice-app",
            "--flag",
            "lanomat://install/ut2004",
            "lanomat://remove/ut2004",
            "lanomat://launch/ut2004",
            "https://evil.example/",
        ];
        let link = select_start_up_link(args.iter().map(|s| s.to_string())).unwrap();
        assert_eq!(link.action, DeepLinkAction::Launch);
        assert_eq!(link.slug, "ut2004");
    }

    #[test]
    fn no_valid_argument_means_no_link() {
        assert!(select_start_up_link(
            ["a", "lanomat://install/../x"]
                .iter()
                .map(|s| s.to_string())
        )
        .is_none());
    }
}
