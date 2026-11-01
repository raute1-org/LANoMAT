//! Account features (modules Identity and Friends): the own profile, linked accounts and
//! friends. JSON keys are snake_case as on the server. Everything is tolerant: unknown fields are
//! ignored, a missing or `null` value becomes the default (`""`, `false`, `0`, `[]`) and every
//! value that can plausibly be absent is an `Option`.

use reqwest::{Method, Url};
use serde::{Deserialize, Deserializer, Serialize};
use serde_json::{json, Map, Value};

use super::{seg, ApiClient};
use crate::error::{Error, Result};

/// `null` where a plain value is expected (the real server did that once for a tournament
/// `game`) becomes the default instead of failing the whole answer.
pub(super) fn nn<'de, D, T>(d: D) -> std::result::Result<T, D::Error>
where
    D: Deserializer<'de>,
    T: Default + Deserialize<'de>,
{
    Ok(Option::<T>::deserialize(d)?.unwrap_or_default())
}

/// The own profile (`GET profile`). The avatar is the Discord one and read-only.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct Profile {
    #[serde(deserialize_with = "nn")]
    pub id: u64,
    #[serde(deserialize_with = "nn")]
    pub name: String,
    pub email: Option<String>,
    #[serde(deserialize_with = "nn")]
    pub email_verified: bool,
    pub avatar_url: Option<String>,
    pub bio: Option<String>,
    pub steam_url: Option<String>,
    pub stream_url: Option<String>,
    /// `#rrggbb`.
    pub profile_color: Option<String>,
    #[serde(deserialize_with = "nn")]
    pub has_verified_steam_link: bool,
    pub verified_steam_nickname: Option<String>,
    /// Whether the person shows where they are (voice room and game) to the other participants
    /// of an event. `None` when the server does not report it (older server).
    pub share_location: Option<bool>,
    /// The fields this person may change.
    #[serde(deserialize_with = "nn")]
    pub editable: Vec<String>,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct ProfileEnvelope {
    #[serde(deserialize_with = "nn")]
    profile: Profile,
}

/// `null` clears a nullable field, an omitted key keeps it: `None` omitted, `Some(None)` null.
fn nullable<'de, D>(d: D) -> std::result::Result<Option<Option<String>>, D::Error>
where
    D: Deserializer<'de>,
{
    Option::<String>::deserialize(d).map(Some)
}

/// A change of the profile. `name` and `email` can only be replaced (never cleared); the other
/// fields take a value, or `null` to clear them; a field that is left out stays as it is.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct ProfileUpdate {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub name: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub email: Option<String>,
    #[serde(deserialize_with = "nullable", skip_serializing_if = "Option::is_none")]
    pub bio: Option<Option<String>>,
    #[serde(deserialize_with = "nullable", skip_serializing_if = "Option::is_none")]
    pub steam_url: Option<Option<String>>,
    #[serde(deserialize_with = "nullable", skip_serializing_if = "Option::is_none")]
    pub stream_url: Option<Option<String>>,
    #[serde(deserialize_with = "nullable", skip_serializing_if = "Option::is_none")]
    pub profile_color: Option<Option<String>>,
    /// The location sharing switch (a boolean, never `null`).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub share_location: Option<bool>,
}

impl ProfileUpdate {
    fn is_empty(&self) -> bool {
        self.name.is_none()
            && self.email.is_none()
            && self.bio.is_none()
            && self.steam_url.is_none()
            && self.stream_url.is_none()
            && self.profile_color.is_none()
            && self.share_location.is_none()
    }

    fn body(&self) -> Value {
        // Serialising plain data cannot fail.
        serde_json::to_value(self).unwrap_or_else(|_| Value::Object(Map::new()))
    }
}

/// One linked account (`discord` is the read-only login identity).
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct Connection {
    #[serde(deserialize_with = "nn")]
    pub provider: String,
    #[serde(deserialize_with = "nn")]
    pub label: String,
    /// `connected`, `not_connected` or `needs_reauth`.
    #[serde(deserialize_with = "nn")]
    pub status: String,
    pub external_name: Option<String>,
    /// The deployment has credentials for this provider.
    #[serde(deserialize_with = "nn")]
    pub available: bool,
    #[serde(deserialize_with = "nn")]
    pub can_link: bool,
    #[serde(deserialize_with = "nn")]
    pub can_unlink: bool,
    #[serde(deserialize_with = "nn")]
    pub read_only: bool,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct ConnectionsEnvelope {
    #[serde(deserialize_with = "nn")]
    connections: Vec<Connection>,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct ConnectionEnvelope {
    #[serde(deserialize_with = "nn")]
    connection: Connection,
}

/// A single-use link into the browser to connect a provider.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct LinkUrl {
    #[serde(deserialize_with = "nn")]
    pub provider: String,
    #[serde(deserialize_with = "nn")]
    pub url: String,
    pub expires_at: Option<String>,
}

/// A person as the friends lists show them: display fields only.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FriendUser {
    #[serde(deserialize_with = "nn")]
    pub id: u64,
    #[serde(deserialize_with = "nn")]
    pub name: String,
    pub avatar_url: Option<String>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FriendRequest {
    /// The request id (accept, decline, cancel).
    #[serde(deserialize_with = "nn")]
    pub id: u64,
    #[serde(deserialize_with = "nn")]
    pub user: FriendUser,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FriendRequests {
    #[serde(deserialize_with = "nn")]
    pub incoming: Vec<FriendRequest>,
    #[serde(deserialize_with = "nn")]
    pub outgoing: Vec<FriendRequest>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FriendSuggestion {
    #[serde(deserialize_with = "nn")]
    pub id: u64,
    #[serde(deserialize_with = "nn")]
    pub name: String,
    pub avatar_url: Option<String>,
    /// Number of shared events, teams, tournaments and Steam friends.
    #[serde(deserialize_with = "nn")]
    pub shared: u64,
    /// `shared_event`, `shared_team`, `shared_tournament`, `shared_steam_friend`.
    #[serde(deserialize_with = "nn")]
    pub reasons: Vec<String>,
    /// The German copy of `reasons`.
    #[serde(deserialize_with = "nn")]
    pub reason_labels: Vec<String>,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct FriendsEnvelope {
    #[serde(deserialize_with = "nn")]
    friends: Vec<FriendUser>,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct SuggestionsEnvelope {
    #[serde(deserialize_with = "nn")]
    suggestions: Vec<FriendSuggestion>,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct BlockedEnvelope {
    #[serde(deserialize_with = "nn")]
    blocked: Vec<FriendUser>,
}

#[derive(Debug, Default, Deserialize)]
#[serde(default)]
struct StatusEnvelope {
    #[serde(deserialize_with = "nn")]
    status: String,
}

/// Prefix of the browser path a link-url hands out.
const LINK_PATH: &str = "settings/connections/link/";

/// Checks that `url` is the single-use link of this server: https, same origin as `base_url`,
/// no credentials, no fragment, a path below `<base path>settings/connections/link/` with a
/// token, and spelled exactly as the URL parser would write it (no whitespace, no encoded dot
/// segments). Returns the URL on success; anything else is [`Error::Usage`].
pub fn validate_connection_link_url(base_url: &str, url: &str) -> Result<String> {
    validate_link_url(base_url, url, false)
}

/// The same check for the headless UI smoke (`selftest` builds only): a stand-in server on
/// loopback speaks plain http, so that one origin may use `http`. Nothing else is relaxed.
pub fn validate_connection_link_url_loopback_http(base_url: &str, url: &str) -> Result<String> {
    validate_link_url(base_url, url, true)
}

fn validate_link_url(base_url: &str, url: &str, loopback_http: bool) -> Result<String> {
    let no = || Error::Usage("Dieser Link ist nicht erlaubt.".into());
    let base = Url::parse(base_url).map_err(|_| no())?;
    let parsed = Url::parse(url).map_err(|_| no())?;
    let prefix = format!("{}/{LINK_PATH}", base.path().trim_end_matches('/'));
    let token = parsed.path().strip_prefix(&prefix).unwrap_or("");
    let scheme_ok = |u: &Url| {
        u.scheme() == "https"
            || (loopback_http
                && u.scheme() == "http"
                && matches!(u.host_str(), Some("127.0.0.1" | "localhost")))
    };
    let ok = scheme_ok(&parsed)
        && scheme_ok(&base)
        && parsed.origin() == base.origin()
        && parsed.username().is_empty()
        && parsed.password().is_none()
        && parsed.fragment().is_none()
        && !token.is_empty()
        && !token.contains('/')
        && token != "."
        && token != ".."
        && parsed.as_str() == url;
    if ok {
        Ok(parsed.into())
    } else {
        Err(no())
    }
}

impl ApiClient {
    /// `GET profile`.
    pub async fn profile(&self) -> Result<Profile> {
        let e: ProfileEnvelope = self.get_v1("profile", &[]).await?;
        Ok(e.profile)
    }

    /// `PATCH profile`: changes only the fields that are set (validated by the server, `422`).
    pub async fn patch_profile(&self, update: &ProfileUpdate) -> Result<Profile> {
        if update.is_empty() {
            return Err(Error::Usage("Es gibt nichts zu ändern.".into()));
        }
        let e: ProfileEnvelope = self
            .send_v1(Method::PATCH, "profile", Some(update.body()))
            .await?;
        Ok(e.profile)
    }

    /// `PUT profile`: the browser's form, `name` and `email` are required.
    pub async fn put_profile(&self, update: &ProfileUpdate) -> Result<Profile> {
        let e: ProfileEnvelope = self
            .send_v1(Method::PUT, "profile", Some(update.body()))
            .await?;
        Ok(e.profile)
    }

    /// `GET connections`.
    pub async fn connections(&self) -> Result<Vec<Connection>> {
        let e: ConnectionsEnvelope = self.get_v1("connections", &[]).await?;
        Ok(e.connections)
    }

    /// `DELETE connections/{provider}`: unlinks the own account, answers the new state.
    pub async fn unlink_connection(&self, provider: &str) -> Result<Connection> {
        let e: ConnectionEnvelope = self
            .send_v1(
                Method::DELETE,
                &format!("connections/{}", seg(provider)?),
                None,
            )
            .await?;
        Ok(e.connection)
    }

    /// `POST connections/{provider}/link-url`: a single-use browser link (10 minutes).
    pub async fn connection_link_url(&self, provider: &str) -> Result<LinkUrl> {
        self.send_v1(
            Method::POST,
            &format!("connections/{}/link-url", seg(provider)?),
            None,
        )
        .await
    }

    /// `GET friends`.
    pub async fn friends(&self) -> Result<Vec<FriendUser>> {
        let e: FriendsEnvelope = self.get_v1("friends", &[]).await?;
        Ok(e.friends)
    }

    /// `GET friends/requests`.
    pub async fn friend_requests(&self) -> Result<FriendRequests> {
        self.get_v1("friends/requests", &[]).await
    }

    /// `GET friends/suggestions`.
    pub async fn friend_suggestions(&self) -> Result<Vec<FriendSuggestion>> {
        let e: SuggestionsEnvelope = self.get_v1("friends/suggestions", &[]).await?;
        Ok(e.suggestions)
    }

    /// `GET friends/blocked`.
    pub async fn blocked_users(&self) -> Result<Vec<FriendUser>> {
        let e: BlockedEnvelope = self.get_v1("friends/blocked", &[]).await?;
        Ok(e.blocked)
    }

    /// `POST friends/requests {user_id}`: `pending`, or `accepted` when the other side had already
    /// asked. No request id comes back: re-read [`ApiClient::friend_requests`].
    pub async fn send_friend_request(&self, user_id: u64) -> Result<String> {
        let e: StatusEnvelope = self
            .send_v1(
                Method::POST,
                "friends/requests",
                Some(json!({"user_id": user_id})),
            )
            .await?;
        Ok(e.status)
    }

    /// `POST friends/requests/{id}/accept`: answers `accepted`.
    pub async fn accept_friend_request(&self, request_id: u64) -> Result<String> {
        let e: StatusEnvelope = self
            .send_v1(
                Method::POST,
                &format!("friends/requests/{request_id}/accept"),
                None,
            )
            .await?;
        Ok(e.status)
    }

    /// `POST friends/requests/{id}/decline`: answers `declined`.
    pub async fn decline_friend_request(&self, request_id: u64) -> Result<String> {
        let e: StatusEnvelope = self
            .send_v1(
                Method::POST,
                &format!("friends/requests/{request_id}/decline"),
                None,
            )
            .await?;
        Ok(e.status)
    }

    /// `DELETE friends/requests/{id}`: cancels an own outgoing request.
    pub async fn cancel_friend_request(&self, request_id: u64) -> Result<()> {
        self.send_v1_empty(Method::DELETE, &format!("friends/requests/{request_id}"))
            .await
    }

    /// `DELETE friends/{user}`: removes a friend (also when there was none).
    pub async fn remove_friend(&self, user_id: u64) -> Result<()> {
        self.send_v1_empty(Method::DELETE, &format!("friends/{user_id}"))
            .await
    }

    /// `POST users/{user}/block`: tears down friendship and requests in both directions.
    pub async fn block_user(&self, user_id: u64) -> Result<()> {
        self.send_v1_empty(Method::POST, &format!("users/{user_id}/block"))
            .await
    }

    /// `DELETE users/{user}/block`.
    pub async fn unblock_user(&self, user_id: u64) -> Result<()> {
        self.send_v1_empty(Method::DELETE, &format!("users/{user_id}/block"))
            .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{body_json, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    const V1: &str = "/api/voice-client/v1";

    fn client(server: &MockServer) -> ApiClient {
        ApiClient::new(&server.uri()).unwrap().with_token("t")
    }

    fn profile_json() -> Value {
        json!({"profile": {"id": 7, "name": "Ada", "email": "ada@example.test", "email_verified": true,
         "avatar_url": "https://cdn.example.test/a.png", "bio": "hi", "steam_url": null, "stream_url": null,
         "profile_color": "#aabbcc", "has_verified_steam_link": true, "verified_steam_nickname": "ada_s",
         "editable": ["name", "email", "bio", "steam_url", "stream_url", "profile_color"],
         "future_field": {"x": 1}}})
    }

    #[tokio::test]
    async fn reads_the_profile() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/profile")))
            .respond_with(ResponseTemplate::new(200).set_body_json(profile_json()))
            .mount(&server)
            .await;
        let p = client(&server).profile().await.unwrap();
        assert_eq!((p.id, p.name.as_str()), (7, "Ada"));
        assert!(p.email_verified && p.has_verified_steam_link);
        assert_eq!(p.steam_url, None);
        assert_eq!(p.profile_color.as_deref(), Some("#aabbcc"));
        assert_eq!(p.editable.len(), 6);
    }

    #[tokio::test]
    async fn nulls_and_missing_fields_never_fail_a_read() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/profile")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"profile": {
                "id": 1, "name": null, "email": null, "email_verified": null, "editable": null}})))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/connections")))
            .respond_with(
                ResponseTemplate::new(200).set_body_json(json!({"connections": [
                {"provider": "steam", "label": null, "status": null, "can_link": null}]})),
            )
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/friends/requests")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"incoming": null})))
            .mount(&server)
            .await;
        let c = client(&server);
        let p = c.profile().await.unwrap();
        assert_eq!((p.name.as_str(), p.email.as_deref()), ("", None));
        assert!(!p.email_verified && p.editable.is_empty());
        let list = c.connections().await.unwrap();
        assert_eq!(list[0].provider, "steam");
        assert!(!list[0].can_link && list[0].status.is_empty());
        let r = c.friend_requests().await.unwrap();
        assert!(r.incoming.is_empty() && r.outgoing.is_empty());
    }

    #[test]
    fn plain_http_is_only_ever_allowed_on_loopback_in_the_smoke_variant() {
        let base = "http://127.0.0.1:8095";
        let ok = "http://127.0.0.1:8095/settings/connections/link/demo-1";
        // The real check never lets http through, loopback or not.
        assert!(validate_connection_link_url(base, ok).is_err());
        assert_eq!(
            validate_connection_link_url_loopback_http(base, ok).unwrap(),
            ok
        );
        for (b, u) in [
            // another host than loopback
            (
                "http://203.0.113.5:8095",
                "http://203.0.113.5:8095/settings/connections/link/x",
            ),
            // a different origin
            (base, "http://127.0.0.1:9999/settings/connections/link/x"),
            (base, "http://localhost:8095/settings/connections/link/x"),
            // the other rules stay
            (base, "http://127.0.0.1:8095/settings/connections/link/x/y"),
            (base, "http://127.0.0.1:8095/settings/connections/link/x#f"),
            (
                base,
                "http://u:p@127.0.0.1:8095/settings/connections/link/x",
            ),
            (base, "http://127.0.0.1:8095/other/x"),
        ] {
            assert!(
                validate_connection_link_url_loopback_http(b, u).is_err(),
                "{u}"
            );
        }
    }

    #[tokio::test]
    async fn share_location_is_read_and_patched_as_a_boolean() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/profile")))
            .respond_with(ResponseTemplate::new(200).set_body_json(
                json!({"profile": {"id": 7, "name": "Ada", "share_location": false,
                 "editable": ["name", "share_location"]}}),
            ))
            .mount(&server)
            .await;
        Mock::given(method("PATCH"))
            .and(path(format!("{V1}/profile")))
            .and(body_json(json!({"share_location": true})))
            .respond_with(ResponseTemplate::new(200).set_body_json(
                json!({"profile": {"id": 7, "name": "Ada", "share_location": true}}),
            ))
            .expect(1)
            .mount(&server)
            .await;
        let c = client(&server);
        assert_eq!(c.profile().await.unwrap().share_location, Some(false));
        // An older server does not report it at all.
        let old: Profile = serde_json::from_value(json!({"id": 1, "name": "x"})).unwrap();
        assert_eq!(old.share_location, None);
        let update: ProfileUpdate =
            serde_json::from_value(json!({"share_location": true, "role": "admin"})).unwrap();
        let p = c.patch_profile(&update).await.unwrap();
        assert_eq!(p.share_location, Some(true));
        // A switch that is null is "not sent", so it can never clear anything.
        let none: ProfileUpdate = serde_json::from_value(json!({"share_location": null})).unwrap();
        assert!(c.patch_profile(&none).await.is_err());
    }

    #[tokio::test]
    async fn patch_sends_only_the_changed_fields_and_null_clears() {
        let server = MockServer::start().await;
        Mock::given(method("PATCH"))
            .and(path(format!("{V1}/profile")))
            .and(body_json(json!({"bio": null, "profile_color": "#112233"})))
            .respond_with(ResponseTemplate::new(200).set_body_json(profile_json()))
            .mount(&server)
            .await;
        let update: ProfileUpdate = serde_json::from_value(
            json!({"bio": null, "role": "admin", "profile_color": "#112233"}),
        )
        .unwrap();
        assert_eq!(update.bio, Some(None));
        assert_eq!(update.steam_url, None);
        client(&server).patch_profile(&update).await.unwrap();
    }

    #[tokio::test]
    async fn an_empty_update_is_refused_locally() {
        let server = MockServer::start().await;
        let e = client(&server)
            .patch_profile(&ProfileUpdate::default())
            .await
            .unwrap_err();
        assert!(matches!(e, Error::Usage(_)));
    }

    #[tokio::test]
    async fn put_sends_the_form() {
        let server = MockServer::start().await;
        Mock::given(method("PUT"))
            .and(path(format!("{V1}/profile")))
            .and(body_json(
                json!({"name": "Ada", "email": "ada@example.test"}),
            ))
            .respond_with(ResponseTemplate::new(200).set_body_json(profile_json()))
            .mount(&server)
            .await;
        let u = ProfileUpdate {
            name: Some("Ada".into()),
            email: Some("ada@example.test".into()),
            ..Default::default()
        };
        client(&server).put_profile(&u).await.unwrap();
    }

    #[tokio::test]
    async fn profile_validation_errors_carry_the_field_errors() {
        let server = MockServer::start().await;
        Mock::given(method("PATCH"))
            .and(path(format!("{V1}/profile")))
            .respond_with(ResponseTemplate::new(422).set_body_json(json!({
                "message": "Die E-Mail ist ungültig.", "errors": {"email": ["Die E-Mail ist ungültig."]}})))
            .mount(&server)
            .await;
        let u = ProfileUpdate {
            email: Some("x".into()),
            ..Default::default()
        };
        match client(&server).patch_profile(&u).await.unwrap_err() {
            Error::Validation { fields, .. } => assert_eq!(fields["email"].len(), 1),
            e => panic!("{e:?}"),
        }
    }

    #[tokio::test]
    async fn connections_list_unlink_and_link_url() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/connections")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"connections": [
                {"provider": "discord", "label": "Discord", "status": "connected", "external_name": "Ada",
                 "available": true, "can_link": false, "can_unlink": false, "read_only": true},
                {"provider": "twitch", "label": "Twitch", "status": "needs_reauth", "external_name": "streamer1",
                 "available": true, "can_link": true, "can_unlink": true, "read_only": false},
                {"provider": "steam", "label": "Steam", "status": "not_connected", "external_name": null,
                 "available": false, "can_link": false, "can_unlink": false, "read_only": false}
            ]})))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path(format!("{V1}/connections/twitch")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"connection": {
                "provider": "twitch", "label": "Twitch", "status": "not_connected", "external_name": null,
                "available": true, "can_link": true, "can_unlink": false, "read_only": false}})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/connections/twitch/link-url")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
                "provider": "twitch", "url": "https://lan.example.test/settings/connections/link/abc",
                "expires_at": "2026-11-01T12:10:00+00:00"})))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path(format!("{V1}/connections/discord")))
            .respond_with(
                ResponseTemplate::new(404).set_body_json(json!({"message": "Unbekannt."})),
            )
            .mount(&server)
            .await;
        let c = client(&server);
        let list = c.connections().await.unwrap();
        assert_eq!(list.len(), 3);
        assert!(list[0].read_only && !list[0].can_unlink);
        assert_eq!(list[1].status, "needs_reauth");
        assert_eq!(list[2].external_name, None);
        let after = c.unlink_connection("twitch").await.unwrap();
        assert_eq!(after.status, "not_connected");
        let link = c.connection_link_url("twitch").await.unwrap();
        assert!(link.url.ends_with("/link/abc"));
        assert!(link.expires_at.is_some());
        assert!(matches!(
            c.unlink_connection("discord").await.unwrap_err(),
            Error::NotFound(_)
        ));
        assert!(matches!(
            c.connection_link_url("../x").await.unwrap_err(),
            Error::Usage(_)
        ));
    }

    #[tokio::test]
    async fn friend_lists() {
        let server = MockServer::start().await;
        let u = json!({"id": 9, "name": "Bob", "avatar_url": null});
        for (route, body) in [
            ("friends", json!({"friends": [u]})),
            (
                "friends/requests",
                json!({"incoming": [{"id": 4, "user": u}], "outgoing": []}),
            ),
            (
                "friends/suggestions",
                json!({"suggestions": [{"id": 11, "name": "Cy", "avatar_url": "https://x.test/c.png",
                  "shared": 3, "reasons": ["shared_event", "shared_team"],
                  "reason_labels": ["Gleiches Event", "Gleiches Team"]}]}),
            ),
            ("friends/blocked", json!({"blocked": [u]})),
        ] {
            Mock::given(method("GET"))
                .and(path(format!("{V1}/{route}")))
                .respond_with(ResponseTemplate::new(200).set_body_json(body))
                .mount(&server)
                .await;
        }
        let c = client(&server);
        let f = c.friends().await.unwrap();
        assert_eq!((f[0].id, f[0].avatar_url.clone()), (9, None));
        let r = c.friend_requests().await.unwrap();
        assert_eq!(
            (r.incoming[0].id, r.incoming[0].user.name.as_str()),
            (4, "Bob")
        );
        assert!(r.outgoing.is_empty());
        let s = c.friend_suggestions().await.unwrap();
        assert_eq!(s[0].shared, 3);
        assert_eq!(s[0].reason_labels[1], "Gleiches Team");
        assert_eq!(c.blocked_users().await.unwrap().len(), 1);
    }

    #[tokio::test]
    async fn friend_actions() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/friends/requests")))
            .and(body_json(json!({"user_id": 9})))
            .respond_with(ResponseTemplate::new(201).set_body_json(json!({"status": "pending"})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/friends/requests/4/accept")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"status": "accepted"})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/friends/requests/5/decline")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"status": "declined"})))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path(format!("{V1}/friends/requests/6")))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path(format!("{V1}/friends/9")))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/users/9/block")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"blocked": true})))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path(format!("{V1}/users/9/block")))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
        let c = client(&server);
        assert_eq!(c.send_friend_request(9).await.unwrap(), "pending");
        assert_eq!(c.accept_friend_request(4).await.unwrap(), "accepted");
        assert_eq!(c.decline_friend_request(5).await.unwrap(), "declined");
        c.cancel_friend_request(6).await.unwrap();
        c.remove_friend(9).await.unwrap();
        c.block_user(9).await.unwrap();
        c.unblock_user(9).await.unwrap();
    }

    #[tokio::test]
    async fn refused_friend_rules_and_errors_map_like_the_other_modules() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/friends/requests")))
            .respond_with(ResponseTemplate::new(422).set_body_json(
                json!({"code": "already_friends", "message": "Ihr seid schon befreundet."}),
            ))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/friends/requests/4/accept")))
            .respond_with(ResponseTemplate::new(403).set_body_json(json!({"message": "Nein."})))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path(format!("{V1}/friends/requests/8")))
            .respond_with(ResponseTemplate::new(404).set_body_json(json!({"message": "Weg."})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(format!("{V1}/users/9/block")))
            .respond_with(ResponseTemplate::new(429).insert_header("Retry-After", "7"))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/friends")))
            .respond_with(ResponseTemplate::new(401).set_body_json(json!({"message": "x"})))
            .mount(&server)
            .await;
        let c = client(&server);
        match c.send_friend_request(9).await.unwrap_err() {
            Error::Validation { code, message, .. } => {
                assert_eq!(code.as_deref(), Some("already_friends"));
                assert!(message.starts_with("Ihr seid"));
            }
            e => panic!("{e:?}"),
        }
        assert!(matches!(
            c.accept_friend_request(4).await.unwrap_err(),
            Error::Forbidden(_)
        ));
        assert!(matches!(
            c.cancel_friend_request(8).await.unwrap_err(),
            Error::NotFound(_)
        ));
        assert!(matches!(
            c.block_user(9).await.unwrap_err(),
            Error::RateLimited {
                retry_after_secs: Some(7)
            }
        ));
        assert!(matches!(
            c.friends().await.unwrap_err(),
            Error::Unauthorized
        ));
    }

    #[test]
    fn link_url_validation_is_strict() {
        let base = "https://lan.example.test/";
        let ok = "https://lan.example.test/settings/connections/link/AbC123_x-y";
        assert_eq!(validate_connection_link_url(base, ok).unwrap(), ok);
        for bad in [
            "",
            "http://lan.example.test/settings/connections/link/abc",
            "https://evil.example/settings/connections/link/abc",
            "https://lan.example.test.evil.example/settings/connections/link/abc",
            "https://lan.example.test:8443/settings/connections/link/abc",
            "https://user@lan.example.test/settings/connections/link/abc",
            "https://lan.example.test@evil.example/settings/connections/link/abc",
            "https://lan.example.test/settings/connections/link/",
            "https://lan.example.test/settings/connections/link/a/b",
            "https://lan.example.test/settings/connections/link/abc#x",
            "https://lan.example.test/settings/connections/link/../../login",
            "https://lan.example.test/settings/connections/link/%2e%2e/x",
            "https://lan.example.test/settings/connections/other/abc",
            "https://lan.example.test/talk/connect",
            " https://lan.example.test/settings/connections/link/abc",
            "https://lan.example.test/settings/connections/link/abc\n",
            "javascript:alert(1)",
            "steam://connect/1.2.3.4:27015",
            "file:///etc/passwd",
        ] {
            assert!(validate_connection_link_url(base, bad).is_err(), "{bad:?}");
        }
        // A plain-http login can never hand out an acceptable link.
        assert!(validate_connection_link_url(
            "http://lan.example.test/",
            "https://lan.example.test/settings/connections/link/abc"
        )
        .is_err());
        // A path prefix of the base is part of the allowed path.
        assert!(validate_connection_link_url(
            "https://lan.example.test/app/",
            "https://lan.example.test/app/settings/connections/link/abc"
        )
        .is_ok());
    }
}
