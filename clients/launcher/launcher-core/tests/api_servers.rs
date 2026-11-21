//! `GET /api/v1/servers`: the aggregated, typed server browser the chat uses.
//!
//! The payload is the participant web page's projection, camelCase on the
//! wire, with no player names. These tests pin that contract and the shell's
//! join-URL allowlist.

mod common;

use common::{base_url, bearer_of, endpoint, mock_json, servers_body, ACCESS_TOKEN};
use launcher_core::api::{ApiClient, ApiError};
use launcher_core::types::GameServer;
use serde_json::json;
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

#[tokio::test]
async fn servers_parses_the_aggregate_with_the_bearer() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/servers"),
        200,
        servers_body(),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let list = api.servers().await.unwrap();

    assert_eq!(list.events.len(), 1);
    assert_eq!(list.events[0].slug, "lan-2026");
    assert_eq!(list.events[0].status, "live");

    assert_eq!(list.servers.len(), 2);

    let event_server = &list.servers[0];
    assert_eq!(event_server.id, json!(11));
    assert_eq!(event_server.title, "Survival");
    assert_eq!(event_server.game.as_deref(), Some("Minecraft"));
    assert_eq!(event_server.description.as_deref(), Some("Whitelist off"));
    assert_eq!(event_server.address.as_deref(), Some("203.0.113.9"));
    assert_eq!(event_server.port, Some(25565));
    assert_eq!(
        event_server.connect_string.as_deref(),
        Some("203.0.113.9:25565")
    );
    assert!(!event_server.connect_is_link);
    assert_eq!(event_server.status, "ready");
    assert!(event_server.joinable());
    assert!(!event_server.permanent);
    assert_eq!(
        event_server.event.as_ref().map(|event| event.slug.as_str()),
        Some("lan-2026")
    );
    let query = event_server.query.as_ref().unwrap();
    assert_eq!(query.server_name, "Survival");
    assert_eq!(query.map, "world");
    assert_eq!(query.players, 3);
    assert_eq!(query.max_players, 20);
    // A plain address:port is copy data, not a link to open.
    assert!(event_server.join_url().is_none());

    let permanent = &list.servers[1];
    assert!(permanent.permanent);
    assert!(permanent.event.is_none());
    assert_eq!(permanent.status, "offline");
    assert!(!permanent.joinable());
    // An offline permanent server has no "Beitreten"; the card links its page.
    assert!(permanent.join_url().is_none());
    assert_eq!(permanent.source.as_deref(), Some("external"));

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].url.path(), endpoint("/api/v1/servers"));
    assert_eq!(
        bearer_of(&requests[0]).as_deref(),
        Some("Bearer launcher-secret-token")
    );
}

#[tokio::test]
async fn servers_tolerates_missing_lists_and_unknown_fields() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/servers"),
        200,
        json!({ "future_field": true }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let list = api.servers().await.unwrap();

    assert!(list.events.is_empty());
    assert!(list.servers.is_empty());
}

#[tokio::test]
async fn servers_keeps_an_offline_permanent_server_without_a_query() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/servers"),
        200,
        json!({
            "events": [],
            "servers": [{
                "id": "12",
                "title": "Dauerwelt",
                "status": "offline",
                "permanent": true,
                "query": null,
                "joinOptions": []
            }]
        }),
    )
    .await;

    let list = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN)
        .servers()
        .await
        .unwrap();

    let permanent = &list.servers[0];
    // String ids from a different backend stay opaque.
    assert_eq!(permanent.id, json!("12"));
    assert!(permanent.query.is_none());
    assert!(permanent.game.is_none());
    assert!(permanent.join_options.is_empty());
    assert!(permanent.join_url().is_none());
}

#[tokio::test]
async fn a_rejected_token_is_an_auth_error_and_touches_no_other_route() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/servers"),
        401,
        json!({ "message": "Unauthenticated." }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token("expired-token");
    let error = api.servers().await.unwrap_err();

    assert!(error.is_auth(), "{error}");
    assert_eq!(error.status(), Some(401));
    assert!(!error.to_string().contains("expired-token"));

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].method.as_str(), "GET");
    assert_eq!(requests[0].url.path(), endpoint("/api/v1/servers"));
}

#[tokio::test]
async fn a_non_json_success_body_is_a_decode_error() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path(endpoint("/api/v1/servers")))
        .respond_with(ResponseTemplate::new(200).set_body_string("<html>captive portal</html>"))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let error = api.servers().await.unwrap_err();

    assert!(matches!(error, ApiError::Decode(_)), "{error}");
}

#[test]
fn a_server_join_url_only_accepts_known_schemes() {
    let mut server = GameServer {
        status: "ready".into(),
        connect_is_link: true,
        connect_string: Some("steam://connect/203.0.113.5:27015".into()),
        ..Default::default()
    };

    assert_eq!(server.join_url(), Some("steam://connect/203.0.113.5:27015"));

    server.connect_string = Some("file:///etc/passwd".into());
    assert!(server.join_url().is_none());

    server.connect_is_link = false;
    server.join_options = vec![launcher_core::types::ServerJoinOption {
        key: "native".into(),
        label: "Nativ".into(),
        url: "ut2004://203.0.113.5:7777".into(),
        hint: String::new(),
    }];
    assert_eq!(server.join_url(), Some("ut2004://203.0.113.5:7777"));
}
