//! Keeps `docs/commands.md` (the contract the UI is built against) and the code in step.

use std::collections::BTreeSet;

use super::*;

const DOC: &str = include_str!("../../docs/commands.md");

#[test]
fn commands_and_events_match_the_document() {
    // `### `name`` headings are the documented commands (payload shapes have capitals or spaces).
    let documented: BTreeSet<&str> = DOC
        .lines()
        .filter_map(|l| l.strip_prefix("### `")?.strip_suffix('`'))
        .filter(|n| !n.contains(' ') && !n.chars().any(|c| c.is_uppercase()))
        .collect();
    let registered: BTreeSet<&str> = COMMAND_NAMES.iter().copied().collect();
    assert_eq!(
        registered.len(),
        COMMAND_NAMES.len(),
        "a command is listed twice"
    );
    let undocumented: Vec<_> = registered.difference(&documented).collect();
    let phantom: Vec<_> = documented.difference(&registered).collect();
    assert!(
        undocumented.is_empty(),
        "not in docs/commands.md: {undocumented:?}"
    );
    assert!(
        phantom.is_empty(),
        "documented but not registered: {phantom:?}"
    );

    for event in [
        EVENT_ROOM,
        EVENT_SHORTCUT,
        "settings-changed",
        features::EVT_CHAT,
        features::EVT_TRANSFER,
        features::EVT_NOTIFY,
        features::EVT_QUIET,
        features::EVT_AUTH,
    ] {
        assert!(
            DOC.contains(&format!("`{event}`")),
            "event {event} is not documented"
        );
    }
}

#[test]
fn the_capability_grants_no_plugin_to_the_webview() {
    // Notifications, clipboard and dialogs are used from Rust only, so no plugin permission is
    // granted to the webview (least privilege): core defaults and window dragging only.
    let caps = include_str!("../capabilities/default.json");
    for forbidden in [
        "notification:",
        "clipboard-manager:",
        "dialog:",
        "opener:",
        "shell:",
        "fs:",
        "http:",
    ] {
        assert!(!caps.contains(forbidden), "capability grants {forbidden}");
    }
}

#[test]
fn the_content_security_policy_still_allows_no_network_from_the_page() {
    let conf = include_str!("../tauri.conf.json");
    assert!(conf.contains("connect-src ipc: http://ipc.localhost"));
    assert!(!conf.contains("'unsafe-eval'") && !conf.contains("'unsafe-inline'"));
}
