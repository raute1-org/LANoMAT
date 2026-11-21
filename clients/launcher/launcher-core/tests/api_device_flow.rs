//! `POST /api/mcp/device` and `POST /api/mcp/token`: the RFC 8628 shape and the
//! poll-event mapping. No real server: wiremock answers, nothing sleeps.

mod common;

use common::{
    base_url, device_start_body, endpoint, mock_json, token_body, ACCESS_TOKEN, DEVICE_CODE,
    USER_CODE,
};
use launcher_core::api::{ApiClient, ApiError, PollEvent};
use serde_json::{json, Value};
use wiremock::matchers::{body_json, method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

#[tokio::test]
async fn start_device_posts_the_device_name_and_reads_the_rfc8628_body() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path(endpoint("/api/mcp/device")))
        .and(body_json(
            json!({ "device_name": "LANoMAT Launcher on lan-pc" }),
        ))
        .respond_with(ResponseTemplate::new(200).set_body_json(device_start_body()))
        .expect(1)
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server)).unwrap();
    let device = api
        .start_device("LANoMAT Launcher on lan-pc")
        .await
        .unwrap();

    assert_eq!(device.device_code, DEVICE_CODE);
    assert_eq!(device.user_code, USER_CODE);
    assert_eq!(device.verification_uri, "https://lan.test/mcp/connect");
    assert_eq!(
        device.verification_uri_complete.as_deref(),
        Some("https://lan.test/mcp/connect?code=ABCD-EFGH")
    );
    assert_eq!(device.expires_in, 600);
    assert_eq!(device.interval, 5);

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].method.as_str(), "POST");
    // Pairing is unauthenticated: a stale bearer must never leak into it.
    assert!(common::bearer_of(&requests[0]).is_none());

    // The device code is a credential.
    assert!(!format!("{device:?}").contains(DEVICE_CODE));
}

#[tokio::test]
async fn start_device_reports_a_rejected_request() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "POST",
        &endpoint("/api/mcp/device"),
        400,
        json!({ "error": "invalid_request" }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server)).unwrap();
    let error = api.start_device("x").await.unwrap_err();

    assert!(
        matches!(error, ApiError::Http { status: 400, .. }),
        "{error}"
    );
    assert!(!error.is_auth());
    assert_eq!(error.status(), Some(400));
}

#[tokio::test]
async fn poll_token_maps_every_documented_state() {
    let server = MockServer::start().await;
    let api = ApiClient::new(&base_url(&server)).unwrap();

    async fn poll(server: &MockServer, api: &ApiClient, status: u16, body: Value) -> PollEvent {
        server.reset().await;
        Mock::given(method("POST"))
            .and(path(endpoint("/api/mcp/token")))
            .and(body_json(json!({ "device_code": DEVICE_CODE })))
            .respond_with(ResponseTemplate::new(status).set_body_json(body))
            .mount(server)
            .await;

        api.poll_token(DEVICE_CODE).await.unwrap()
    }

    let granted = poll(&server, &api, 200, token_body()).await;
    match &granted {
        PollEvent::Token(grant) => {
            assert_eq!(grant.access_token, ACCESS_TOKEN);
            assert_eq!(grant.token_type, "Bearer");
            assert_eq!(grant.expires_in, 2_592_000);
            assert!(
                !format!("{grant:?}").contains(ACCESS_TOKEN),
                "token leaked into Debug"
            );
        }
        other => panic!("expected Token, got {other:?}"),
    }
    assert!(
        !format!("{granted:?}").contains(ACCESS_TOKEN),
        "token leaked into Debug"
    );

    assert!(matches!(
        poll(
            &server,
            &api,
            400,
            json!({ "error": "authorization_pending" })
        )
        .await,
        PollEvent::Pending
    ));

    assert!(matches!(
        poll(
            &server,
            &api,
            400,
            json!({ "error": "slow_down", "interval": 10 })
        )
        .await,
        PollEvent::SlowDown
    ));

    // The route/proxy throttle answers 429 with no RFC body at all.
    server.reset().await;
    Mock::given(method("POST"))
        .and(path(endpoint("/api/mcp/token")))
        .respond_with(ResponseTemplate::new(429))
        .mount(&server)
        .await;
    assert!(matches!(
        api.poll_token(DEVICE_CODE).await.unwrap(),
        PollEvent::SlowDown
    ));

    assert!(matches!(
        poll(&server, &api, 400, json!({ "error": "expired_token" })).await,
        PollEvent::Expired
    ));

    match poll(
        &server,
        &api,
        400,
        json!({ "error": "access_denied", "error_description": "the user said no" }),
    )
    .await
    {
        PollEvent::Denied(reason) => assert_eq!(reason, "the user said no"),
        other => panic!("expected Denied, got {other:?}"),
    }

    // A bare `access_denied` still yields a readable reason.
    match poll(&server, &api, 400, json!({ "error": "access_denied" })).await {
        PollEvent::Denied(reason) => assert_eq!(reason, "access_denied"),
        other => panic!("expected Denied, got {other:?}"),
    }

    // RFC `invalid_grant` (unknown device code) is a hard failure, not pending.
    match poll(&server, &api, 400, json!({ "error": "invalid_grant" })).await {
        PollEvent::Invalid(reason) => assert_eq!(reason, "invalid_grant"),
        other => panic!("expected Invalid, got {other:?}"),
    }

    // A newer server may add error codes: report them, do not guess.
    match poll(&server, &api, 400, json!({ "error": "some_future_code" })).await {
        PollEvent::Invalid(reason) => assert_eq!(reason, "some_future_code"),
        other => panic!("expected Invalid, got {other:?}"),
    }

    // No error code at all is still not "pending": the caller must re-pair.
    match poll(&server, &api, 400, json!({})).await {
        PollEvent::Invalid(reason) => assert_eq!(reason, "invalid_grant"),
        other => panic!("expected Invalid, got {other:?}"),
    }
}

#[tokio::test]
async fn poll_token_keeps_the_protocol_path_and_sends_no_bearer() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "POST",
        &endpoint("/api/mcp/token"),
        200,
        token_body(),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token("stale-token");
    api.poll_token(DEVICE_CODE).await.unwrap();

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].url.path(), endpoint("/api/mcp/token"));
    assert!(common::bearer_of(&requests[0]).is_none());
    assert_eq!(
        serde_json::from_slice::<Value>(&requests[0].body).unwrap(),
        json!({ "device_code": DEVICE_CODE })
    );
}
