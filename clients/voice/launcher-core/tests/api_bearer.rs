//! The bearer half of the API: `/api/v1/me`, `/recipes`, `/plan` and the 401
//! classification the shell needs to fall back to pairing.

mod common;

use common::{
    base_url, bearer_of, endpoint, me_body, mock_json, plan_body, recipes_body, ACCESS_TOKEN,
};
use launcher_core::api::{ApiClient, ApiError};
use launcher_core::types::Os;
use serde_json::{json, Value};
use wiremock::matchers::{body_json, method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

#[tokio::test]
async fn me_sends_the_bearer_and_parses_the_documented_user_and_server() {
    let server = MockServer::start().await;
    mock_json(&server, "GET", &endpoint("/api/v1/me"), 200, me_body()).await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let me = api.me().await.unwrap();

    assert_eq!(me.user.id, json!(7));
    assert_eq!(me.user.name, "Ada");
    assert_eq!(me.user.role.as_deref(), Some("participant"));
    assert!(!me.user.is_orga);
    assert_eq!(me.server.name, "LANoMAT");
    assert_eq!(me.server.version, "1.4.0");
    // Unknown keys (agent, mcp_url, api_version) are ignored.
    assert_eq!(me.modules, vec!["GameRecipes", "Files", "Tournaments"]);

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].url.path(), endpoint("/api/v1/me"));
    assert_eq!(
        bearer_of(&requests[0]).as_deref(),
        Some("Bearer launcher-secret-token")
    );
}

#[tokio::test]
async fn me_accepts_a_string_user_id_from_a_different_backend() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/me"),
        200,
        json!({
            "user": { "id": "01HX-USER", "name": "Ada", "role": "orga", "is_orga": true },
            "server": { "name": "LANoMAT", "url": "https://lan.test/", "version": "1.4.0" },
            "modules": []
        }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let me = api.me().await.unwrap();

    assert_eq!(me.user.id, json!("01HX-USER"));
    assert!(me.user.is_orga);
}

#[tokio::test]
async fn recipes_parses_the_data_envelope_and_ignores_extra_fields() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/recipes"),
        200,
        recipes_body(),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let recipes = api.recipes().await.unwrap();

    assert_eq!(recipes.len(), 2);
    assert_eq!(recipes[0].slug, "unreal-tournament-2004");
    assert_eq!(recipes[0].version.as_deref(), Some("3374"));
    assert_eq!(recipes[0].platforms, vec!["windows", "linux"]);
    assert_eq!(recipes[1].summary, None);
    assert!(recipes[1].platforms.is_empty());

    let requests = server.received_requests().await.unwrap();
    assert_eq!(
        bearer_of(&requests[0]).as_deref(),
        Some("Bearer launcher-secret-token")
    );
}

#[tokio::test]
async fn recipes_tolerates_an_empty_envelope() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/recipes"),
        200,
        json!({ "data": [] }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);

    assert!(api.recipes().await.unwrap().is_empty());
}

#[tokio::test]
async fn plan_posts_os_and_parses_the_documented_plan() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path(endpoint(
            "/api/v1/recipes/unreal-tournament-2004/plan",
        )))
        .and(body_json(json!({ "os": "linux", "dry_run": false })))
        .respond_with(ResponseTemplate::new(200).set_body_json(plan_body()))
        .expect(1)
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let plan = api.plan("unreal-tournament-2004", Os::Linux).await.unwrap();

    assert_eq!(plan.recipe.slug, "unreal-tournament-2004");
    assert_eq!(plan.recipe.version.as_deref(), Some("3374"));
    assert_eq!(
        plan.recipe.launch_target.as_deref(),
        Some("System/ut2004-bin-amd64")
    );
    assert_eq!(plan.recipe.launch_targets.len(), 1);
    assert_eq!(
        plan.recipe.launch_targets[0].target.as_deref(),
        Some("System/ut2004-bin-amd64")
    );
    assert_eq!(plan.os, "linux");
    assert_eq!(plan.target_dir, "~/Games/unreal-tournament-2004");
    assert_eq!(plan.steps.len(), 1);
    assert_eq!(plan.missing, vec!["OldUnreal 3374 patch"]);

    let iso = plan.matched("iso").expect("the LAN ISO matches");
    assert_eq!(iso.name.as_deref(), Some("ut2004.iso"));
    assert_eq!(iso.size_bytes, Some(5_368_709_120));
    assert_eq!(
        iso.download_url.as_deref(),
        Some("https://lan.test/api/v1/files/42/download")
    );
    assert!(plan.matched("patch").is_none());
    assert_eq!(plan.missing_required(), vec!["OldUnreal 3374 patch"]);
    assert_eq!(plan.avatars.len(), 1);
    assert!(plan.avatars[0].available_on_lan);

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].method.as_str(), "POST");
    assert_eq!(
        bearer_of(&requests[0]).as_deref(),
        Some("Bearer launcher-secret-token")
    );
}

#[tokio::test]
async fn a_401_is_an_auth_error_with_the_server_message() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/me"),
        401,
        json!({ "message": "Unauthenticated." }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token("expired-token");
    let error = api.me().await.unwrap_err();

    assert!(error.is_auth(), "{error}");
    assert_eq!(error.status(), Some(401));
    assert!(error.to_string().contains("401"), "{error}");
    assert!(error.to_string().contains("Unauthenticated."), "{error}");
    assert!(!error.to_string().contains("expired-token"));
}

#[tokio::test]
async fn a_403_is_an_auth_error_and_a_500_is_not() {
    let server = MockServer::start().await;
    mock_json(
        &server,
        "GET",
        &endpoint("/api/v1/recipes"),
        403,
        json!({ "error": "forbidden" }),
    )
    .await;
    mock_json(
        &server,
        "POST",
        &endpoint("/api/v1/recipes/x/plan"),
        500,
        json!({ "message": "plan builder exploded" }),
    )
    .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);

    let forbidden = api.recipes().await.unwrap_err();
    assert!(forbidden.is_auth(), "{forbidden}");
    assert_eq!(forbidden.status(), Some(403));

    let broken = api.plan("x", Os::Linux).await.unwrap_err();
    assert!(!broken.is_auth(), "{broken}");
    assert!(
        matches!(broken, ApiError::Http { status: 500, .. }),
        "{broken}"
    );
    assert!(
        broken.to_string().contains("plan builder exploded"),
        "{broken}"
    );
}

#[tokio::test]
async fn without_a_token_the_client_fails_locally_and_touches_no_server() {
    let server = MockServer::start().await;
    mock_json(&server, "GET", &endpoint("/api/v1/me"), 200, me_body()).await;

    let api = ApiClient::new(&base_url(&server)).unwrap();
    let error = api.me().await.unwrap_err();

    assert!(matches!(error, ApiError::NotSignedIn));
    assert!(error.is_auth());
    assert_eq!(error.status(), Some(401));
    assert!(api.token().is_none());
    assert!(server.received_requests().await.unwrap().is_empty());
}

#[tokio::test]
async fn a_non_json_success_body_is_a_decode_error_not_a_panic() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path(endpoint("/api/v1/me")))
        .respond_with(ResponseTemplate::new(200).set_body_string("<html>captive portal</html>"))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let error = api.me().await.unwrap_err();

    assert!(matches!(error, ApiError::Decode(_)), "{error}");
    assert!(!error.is_auth());
    assert!(!error.to_string().contains(ACCESS_TOKEN));
}

#[tokio::test]
async fn error_bodies_are_read_without_echoing_the_request_body() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path(endpoint("/api/v1/recipes/x/plan")))
        .respond_with(ResponseTemplate::new(422).set_body_json(json!({
            "message": "The os field is invalid.",
            "errors": { "os": ["The selected os is invalid."] }
        })))
        .mount(&server)
        .await;

    let api = ApiClient::new(&base_url(&server))
        .unwrap()
        .with_token(ACCESS_TOKEN);
    let error = api.plan("x", Os::Windows).await.unwrap_err();

    assert!(
        matches!(error, ApiError::Http { status: 422, .. }),
        "{error}"
    );
    assert!(
        error.to_string().contains("The os field is invalid."),
        "{error}"
    );
    assert_eq!(
        serde_json::from_slice::<Value>(&server.received_requests().await.unwrap()[0].body)
            .unwrap(),
        json!({ "os": "windows", "dry_run": false })
    );
}
