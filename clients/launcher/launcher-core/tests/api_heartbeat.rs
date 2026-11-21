//! The fleet heartbeat: `POST /api/v1/terminals/heartbeat`.
//!
//! The server upserts one `launcher_terminals` row per bearer token, so the
//! test asserts three things the monitor depends on: the documented payload
//! (names, no id), that the bearer token is what identifies the terminal, and
//! that a validation answer (422) is *not* classified as a dead pairing — the
//! launcher keeps working and retries on the next tick.

mod common;

use common::{base_url, bearer_of, endpoint, ACCESS_TOKEN};
use launcher_core::api::{ApiClient, ApiError, TerminalHeartbeat, TerminalState};
use launcher_core::types::Os;
use serde_json::json;
use wiremock::matchers::{body_json, header, method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

fn ack() -> serde_json::Value {
    json!({
        "ok": true,
        "terminal_id": 7,
        "server_time": "2026-11-21T12:00:00+00:00"
    })
}

fn playing_terminal() -> TerminalHeartbeat {
    TerminalHeartbeat::new("Arcade 3", "0.1.0")
        .with_platform(Os::Linux, "x86_64")
        .state(TerminalState::Playing)
        .current_game(Some("unreal-tournament-2004"))
        .installed(vec![
            "Unreal-Tournament-2004".to_string(),
            "ut2004".to_string(),
            "ut2004".to_string(),
        ])
        .gamepad(true)
}

#[tokio::test]
async fn heartbeat_posts_the_documented_payload_with_the_bearer_token() {
    let server = MockServer::start().await;

    Mock::given(method("POST"))
        .and(path(endpoint("/api/v1/terminals/heartbeat")))
        .and(header("authorization", format!("Bearer {ACCESS_TOKEN}")))
        .and(body_json(json!({
            "name": "Arcade 3",
            "os": "linux",
            "arch": "x86_64",
            "version": "0.1.0",
            "state": "playing",
            "current_game_slug": "unreal-tournament-2004",
            "installed": ["unreal-tournament-2004", "ut2004"],
            "gamepad": true
        })))
        .respond_with(ResponseTemplate::new(200).set_body_json(ack()))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let ack = api.heartbeat(&playing_terminal()).await.unwrap();

    assert!(ack.ok);
    assert_eq!(ack.terminal_id, json!(7));
    assert_eq!(
        ack.server_time.as_deref(),
        Some("2026-11-21T12:00:00+00:00")
    );

    let requests = server.received_requests().await.unwrap();

    assert_eq!(requests.len(), 1);
    assert_eq!(
        requests[0].url.path(),
        endpoint("/api/v1/terminals/heartbeat")
    );
    assert_eq!(
        bearer_of(&requests[0]).as_deref(),
        Some("Bearer launcher-secret-token")
    );
}

#[tokio::test]
async fn heartbeat_parses_an_answer_without_an_id_and_ignores_extra_fields() {
    let server = MockServer::start().await;

    Mock::given(method("POST"))
        .and(path(endpoint("/api/v1/terminals/heartbeat")))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "ok": true,
            "terminal_id": "01HX-TERMINAL",
            "offline_after_seconds": 90,
            "heartbeat_seconds": 30
        })))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let ack = api
        .heartbeat(&TerminalHeartbeat::new("lan-pc-07", "0.1.0"))
        .await
        .unwrap();

    assert!(ack.ok);
    assert_eq!(ack.terminal_id, json!("01HX-TERMINAL"));
    assert_eq!(ack.server_time, None);
}

#[tokio::test]
async fn a_validation_answer_is_not_a_dead_pairing() {
    let server = MockServer::start().await;

    // Laravel's 422: field errors, not an auth answer. The launcher must log
    // and retry, never drop the stored token over it.
    Mock::given(method("POST"))
        .and(path(endpoint("/api/v1/terminals/heartbeat")))
        .respond_with(ResponseTemplate::new(422).set_body_json(json!({
            "message": "Der Name ist erforderlich.",
            "errors": { "name": ["Der Name ist erforderlich."] }
        })))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let error = api
        .heartbeat(&TerminalHeartbeat::new("", "0.1.0"))
        .await
        .unwrap_err();

    assert!(
        matches!(error, ApiError::Http { status: 422, .. }),
        "{error}"
    );
    assert!(!error.is_auth());
    assert_eq!(error.status(), Some(422));
    assert!(!error.to_string().contains(ACCESS_TOKEN));
}

#[tokio::test]
async fn a_rejected_token_is_an_auth_failure() {
    let server = MockServer::start().await;

    Mock::given(method("POST"))
        .and(path(endpoint("/api/v1/terminals/heartbeat")))
        .respond_with(ResponseTemplate::new(401).set_body_json(json!({
            "message": "Unauthenticated."
        })))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let error = api
        .heartbeat(&TerminalHeartbeat::new("lan-pc-07", "0.1.0"))
        .await
        .unwrap_err();

    assert!(error.is_auth());
}

#[tokio::test]
async fn an_unpaired_client_sends_nothing() {
    let server = MockServer::start().await;
    let api = ApiClient::new(&base_url(&server)).unwrap();

    let error = api
        .heartbeat(&TerminalHeartbeat::new("lan-pc-07", "0.1.0"))
        .await
        .unwrap_err();

    assert!(matches!(error, ApiError::NotSignedIn));
    assert!(server.received_requests().await.unwrap().is_empty());
}

#[test]
fn the_payload_is_a_fixed_eight_field_document() {
    let value = serde_json::to_value(playing_terminal()).unwrap();
    let mut keys = value
        .as_object()
        .unwrap()
        .keys()
        .cloned()
        .collect::<Vec<_>>();
    keys.sort();

    assert_eq!(
        keys,
        vec![
            "arch",
            "current_game_slug",
            "gamepad",
            "installed",
            "name",
            "os",
            "state",
            "version",
        ]
    );
}

#[test]
fn every_state_serialises_as_the_server_spells_it() {
    for (state, wire) in [
        (TerminalState::Idle, "idle"),
        (TerminalState::Playing, "playing"),
        (TerminalState::Offline, "offline"),
    ] {
        assert_eq!(serde_json::to_value(state).unwrap(), json!(wire));
        assert_eq!(state.as_str(), wire);
    }

    let beat = TerminalHeartbeat::new("Arcade 3", "0.1.0")
        .with_platform(Os::Windows, "x86_64")
        .state(TerminalState::Offline);

    let value = serde_json::to_value(beat).unwrap();

    assert_eq!(value["state"], json!("offline"));
    assert_eq!(value["os"], json!("windows"));
    assert_eq!(value["current_game_slug"], serde_json::Value::Null);
    assert_eq!(value["installed"], json!([]));
}

#[test]
fn the_payload_is_sanitised_to_the_servers_validation_rules() {
    let beat = TerminalHeartbeat::new(&"n".repeat(80), &"v".repeat(40))
        .with_platform(Os::Linux, "x86 64/evil")
        .current_game(Some("Unreal Tournament"))
        .installed(vec![
            "UT2004".to_string(),
            "ut2004".to_string(),
            "../etc/passwd".to_string(),
            "".to_string(),
            "ridge-racer-type-4".to_string(),
        ]);

    assert_eq!(beat.name.chars().count(), 60);
    assert_eq!(beat.version.chars().count(), 32);
    // Every character outside the arch class is dropped, then the 16-char cap.
    assert_eq!(beat.arch, "x8664evil");
    // A space is not a slug character: the field is dropped, not the heartbeat.
    assert_eq!(beat.current_game_slug, None);
    assert_eq!(
        beat.installed,
        vec!["ut2004".to_string(), "ridge-racer-type-4".to_string()]
    );
}

#[test]
fn an_empty_name_or_version_still_yields_a_valid_heartbeat() {
    let beat = TerminalHeartbeat::new("   ", "");

    assert_eq!(beat.name, "PC");
    assert_eq!(beat.version, "unknown");
    assert_eq!(beat.os, Os::current().as_str());
    assert!(!beat.arch.is_empty());
    assert_eq!(beat.state, TerminalState::Idle);
    assert!(!beat.gamepad);
}

#[test]
fn a_slug_is_lowercased_and_bounded() {
    use launcher_core::api::clean_slug;

    assert_eq!(clean_slug("  UT2004 "), Some("ut2004".to_string()));
    assert_eq!(
        clean_slug("unreal-tournament-2004"),
        Some("unreal-tournament-2004".to_string())
    );
    assert_eq!(clean_slug("ut_2004.v2"), Some("ut_2004.v2".to_string()));
    assert_eq!(clean_slug("-leading-dash"), None);
    assert_eq!(clean_slug("with space"), None);
    assert_eq!(clean_slug("../etc/passwd"), None);
    assert_eq!(clean_slug(&"x".repeat(121)), None);
    assert_eq!(
        clean_slug(&"x".repeat(120)).map(|slug| slug.len()),
        Some(120)
    );
}
