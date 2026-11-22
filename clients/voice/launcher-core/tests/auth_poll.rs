//! The device-login state machine: interval, `slow_down`, expiry and the
//! absolute token expiry — all driven by an injected clock, with no sleep in
//! the core.

mod common;

use std::time::{Duration, Instant};

use common::{base_url, device_start_body, endpoint, mock_json, token_body};
use launcher_core::api::ApiClient;
use launcher_core::auth::{DeviceLogin, LoginError, LoginPoll};
use serde_json::json;
use wiremock::matchers::{body_json, method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

const EPOCH: u64 = 1_700_000_000; // 2023-11-14T22:13:20Z

async fn started(server: &MockServer) -> (ApiClient, DeviceLogin, Instant) {
    mock_json(
        server,
        "POST",
        &endpoint("/api/mcp/device"),
        200,
        device_start_body(),
    )
    .await;
    let api = ApiClient::new(&base_url(server)).unwrap();
    let now = Instant::now();
    let login = DeviceLogin::start_at(&api, "LANoMAT Launcher on lan-pc", now, EPOCH)
        .await
        .unwrap();

    (api, login, now)
}

async fn mount_token(server: &MockServer, response: ResponseTemplate) {
    server.reset().await;
    Mock::given(method("POST"))
        .and(path(endpoint("/api/mcp/token")))
        .and(body_json(json!({ "device_code": common::DEVICE_CODE })))
        .respond_with(response)
        .mount(server)
        .await;
}

#[tokio::test]
async fn start_uses_the_server_interval_and_the_code_ttl() {
    let server = MockServer::start().await;
    let (_api, login, now) = started(&server).await;

    assert_eq!(login.interval(), Duration::from_secs(5));
    assert_eq!(login.expires_in(now), Duration::from_secs(600));
    assert!(!login.is_expired(now));
    assert_eq!(
        login.expires_in(now + Duration::from_secs(100)),
        Duration::from_secs(500)
    );
    assert!(login.is_expired(now + Duration::from_secs(600)));
    assert_eq!(
        login.expires_in(now + Duration::from_secs(3_600)),
        Duration::from_secs(0)
    );
    // The device code is a credential even in Debug output.
    assert!(!format!("{:?}", login.device).contains(common::DEVICE_CODE));
}

#[tokio::test]
async fn pending_keeps_the_interval_and_slow_down_adds_five_seconds() {
    let server = MockServer::start().await;
    let (api, mut login, now) = started(&server).await;

    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({ "error": "authorization_pending" })),
    )
    .await;
    assert!(matches!(
        login.poll(&api, now).await.unwrap(),
        LoginPoll::Pending
    ));
    assert_eq!(login.interval(), Duration::from_secs(5));

    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({ "error": "slow_down", "interval": 10 })),
    )
    .await;
    match login.poll(&api, now).await.unwrap() {
        LoginPoll::SlowDown { interval } => assert_eq!(interval, Duration::from_secs(10)),
        other => panic!("expected SlowDown, got {other:?}"),
    }

    // The 429 from the throttle is the same state for the client.
    mount_token(&server, ResponseTemplate::new(429)).await;
    match login.poll(&api, now).await.unwrap() {
        LoginPoll::SlowDown { interval } => assert_eq!(interval, Duration::from_secs(15)),
        other => panic!("expected SlowDown, got {other:?}"),
    }

    // RFC 8628 increments never reset, so a pending poll keeps the raised value.
    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({ "error": "authorization_pending" })),
    )
    .await;
    login.poll(&api, now).await.unwrap();
    assert_eq!(login.interval(), Duration::from_secs(15));
}

#[tokio::test]
async fn a_token_becomes_an_absolute_rfc3339_expiry() {
    let server = MockServer::start().await;
    let (api, mut login, now) = started(&server).await;

    mount_token(
        &server,
        ResponseTemplate::new(200).set_body_json(token_body()),
    )
    .await;

    let poll = login.poll(&api, now).await.unwrap();
    // The bearer token is a credential: Debug must redact it.
    assert!(!format!("{poll:?}").contains(common::ACCESS_TOKEN));

    let (token, expires_at, expires_in) = match poll {
        LoginPoll::Done {
            token,
            expires_at,
            expires_in,
        } => (token, expires_at, expires_in),
        other => panic!("expected Done, got {other:?}"),
    };

    assert_eq!(token, common::ACCESS_TOKEN);
    assert_eq!(expires_in, 2_592_000);
    // 2023-11-14T22:13:20Z + 30 days.
    assert_eq!(expires_at, "2023-12-14T22:13:20Z");
    assert_eq!(
        launcher_core::auth::parse_rfc3339(&expires_at),
        Some(EPOCH + 2_592_000)
    );
}

#[tokio::test]
async fn the_expiry_follows_the_injected_clock_not_the_wall_clock() {
    let server = MockServer::start().await;
    let (api, mut login, now) = started(&server).await;

    mount_token(
        &server,
        ResponseTemplate::new(200).set_body_json(json!({
            "access_token": "t",
            "token_type": "Bearer",
            "expires_in": 3_600
        })),
    )
    .await;

    match login
        .poll(&api, now + Duration::from_secs(120))
        .await
        .unwrap()
    {
        LoginPoll::Done { expires_at, .. } => {
            assert_eq!(expires_at, "2023-11-14T23:15:20Z");
        }
        other => panic!("expected Done, got {other:?}"),
    }
}

#[tokio::test]
async fn denied_invalid_and_expired_end_the_login() {
    let server = MockServer::start().await;
    let (api, mut login, now) = started(&server).await;

    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({
            "error": "access_denied",
            "error_description": "the user clicked no"
        })),
    )
    .await;
    match login.poll(&api, now).await.unwrap_err() {
        LoginError::Denied(reason) => assert_eq!(reason, "the user clicked no"),
        other => panic!("expected Denied, got {other}"),
    }

    let (_api2, mut login2, now2) = started(&server).await;
    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({ "error": "invalid_grant" })),
    )
    .await;
    match login2.poll(&api, now2).await.unwrap_err() {
        LoginError::Invalid(reason) => assert_eq!(reason, "invalid_grant"),
        other => panic!("expected Invalid, got {other}"),
    }

    let (_api3, mut login3, now3) = started(&server).await;
    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({ "error": "expired_token" })),
    )
    .await;
    assert!(matches!(
        login3.poll(&api, now3).await,
        Err(LoginError::Expired)
    ));
}

#[tokio::test]
async fn the_local_deadline_expires_without_any_request() {
    let server = MockServer::start().await;
    let (api, mut login, now) = started(&server).await;
    server.reset().await;

    let error = login
        .poll(&api, now + Duration::from_secs(600))
        .await
        .unwrap_err();

    assert!(matches!(error, LoginError::Expired));
    assert!(matches!(
        error.to_string().as_str(),
        "the login code expired"
    ));
    assert!(server.received_requests().await.unwrap().is_empty());
}

#[tokio::test]
async fn polling_never_sleeps_inside_the_core() {
    let server = MockServer::start().await;
    let (api, mut login, now) = started(&server).await;
    mount_token(
        &server,
        ResponseTemplate::new(400).set_body_json(json!({ "error": "authorization_pending" })),
    )
    .await;

    let began = Instant::now();
    for _ in 0..5 {
        assert!(matches!(
            login.poll(&api, now).await.unwrap(),
            LoginPoll::Pending
        ));
    }

    // Five round trips to a local mock, not 5 × interval seconds.
    assert!(
        began.elapsed() < Duration::from_secs(2),
        "poll blocked for {:?}: the core must never sleep",
        began.elapsed()
    );
}
