//! Server-URL normalisation: the value a player types or pastes must become a
//! base whose `join("api/...")` lands on the documented route, prefix intact.

mod common;

use common::{endpoint, me_body};
use launcher_core::api::{ApiClient, ApiError};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

#[test]
fn a_path_prefix_is_kept_and_a_query_string_is_dropped() {
    let client = ApiClient::new("https://lan.test/lanomat?event=raute1#top").unwrap();
    assert_eq!(client.base_url(), "https://lan.test/lanomat/");

    // Exactly one trailing slash, whatever the input had.
    assert_eq!(
        ApiClient::new("https://lan.test/lanomat/")
            .unwrap()
            .base_url(),
        "https://lan.test/lanomat/"
    );
    assert_eq!(
        ApiClient::new("https://lan.test/lanomat//")
            .unwrap()
            .base_url(),
        "https://lan.test/lanomat/"
    );
    assert_eq!(
        ApiClient::new("https://lan.test/").unwrap().base_url(),
        "https://lan.test/"
    );
    assert_eq!(
        ApiClient::new("https://lan.test").unwrap().base_url(),
        "https://lan.test/"
    );
    assert_eq!(
        ApiClient::new("https://lan.test//").unwrap().base_url(),
        "https://lan.test/"
    );
}

#[test]
fn whitespace_is_trimmed_and_hosts_must_be_http_or_https() {
    assert_eq!(
        ApiClient::new("  http://127.0.0.1:8080/lanomat  ")
            .unwrap()
            .base_url(),
        "http://127.0.0.1:8080/lanomat/"
    );

    for bad in [
        "",
        "   ",
        "lan.test",
        "not a url",
        "file:///etc/passwd",
        "ftp://lan.test",
        "https://",
    ] {
        let error = ApiClient::new(bad)
            .err()
            .unwrap_or_else(|| panic!("{bad:?} was accepted"));
        assert!(matches!(error, ApiError::Url(_)), "{bad:?} -> {error}");
    }
}

#[test]
fn a_pasted_connect_link_keeps_only_the_origin_and_path() {
    // Players paste the browser URL they were on, query string included.
    let client = ApiClient::new("https://lan.raute1.org/mcp/connect?code=ABCD-EFGH#code").unwrap();
    assert_eq!(client.base_url(), "https://lan.raute1.org/mcp/connect/");
}

#[tokio::test]
async fn requests_go_to_the_prefixed_api_route_without_a_query() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path(endpoint("/api/v1/me")))
        .respond_with(ResponseTemplate::new(200).set_body_json(me_body()))
        .expect(1)
        .mount(&server)
        .await;

    let pasted = format!("{}/lanomat/?ignored=1#fragment", server.uri());
    let api = ApiClient::new(&pasted).unwrap();
    assert_eq!(api.base_url(), format!("{}/lanomat/", server.uri()));
    api.with_token("t").me().await.unwrap();

    let requests = server.received_requests().await.unwrap();
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].url.path(), endpoint("/api/v1/me"));
    assert_eq!(requests[0].url.query(), None);
}

#[tokio::test]
async fn a_relative_url_that_looks_like_a_double_slash_does_not_become_a_host() {
    // `//evil.test` is a protocol-relative URL: it must not slip through as the
    // host of the LAN server, or a bearer token would go to another machine.
    for bad in ["//evil.test/lanomat", "lan.test/lanomat"] {
        assert!(
            ApiClient::new(bad).is_err(),
            "{bad} was accepted as a base URL"
        );
    }
}

#[test]
fn a_url_with_credentials_is_refused() {
    for url in [
        "https://user:secret@lan.test",
        "https://user@lan.test/lanomat",
    ] {
        assert!(
            matches!(ApiClient::new(url), Err(ApiError::Url(_))),
            "{url} must be refused"
        );
    }
}
