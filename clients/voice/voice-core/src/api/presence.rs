//! People: where is everybody, and follow them (module Presence). The participants of an event
//! who share their location, each with the voice room and the game they are in right now, and
//! the join targets to go where one of them is. DTOs are camelCase as on the server and
//! serialise back the same way. Everything is tolerant: unknown fields are ignored, a missing or
//! `null` value becomes the default and everything that can plausibly be absent is an `Option`
//! (the real server has returned unexpected nulls before).

use serde::{Deserialize, Deserializer, Serialize};
use serde_json::Value;

use super::account::nn;
use super::{seg, ApiClient};
use crate::error::Result;

/// A room reference that the server may spell as a number or as a string.
fn text_or_number<'de, D>(d: D) -> std::result::Result<Option<String>, D::Error>
where
    D: Deserializer<'de>,
{
    Ok(match Option::<Value>::deserialize(d)? {
        Some(Value::String(s)) if !s.is_empty() => Some(s),
        Some(Value::Number(n)) => Some(n.to_string()),
        _ => None,
    })
}

/// A numeric id that the server may spell as a number or as a numeric string.
fn id_opt<'de, D>(d: D) -> std::result::Result<Option<u64>, D::Error>
where
    D: Deserializer<'de>,
{
    Ok(match Option::<Value>::deserialize(d)? {
        Some(Value::Number(n)) => n.as_u64(),
        Some(Value::String(s)) => s.trim().parse().ok(),
        _ => None,
    })
}

/// One voice room a person is in (at most one entry per provider).
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct PersonVoice {
    /// `discord`, `mumble`, `teamspeak` or `webvoice` (anything else is shown as it is).
    #[serde(deserialize_with = "nn")]
    pub provider: String,
    pub room_label: Option<String>,
    /// The provider's room id; `null` for a room only its roster may join.
    #[serde(deserialize_with = "text_or_number")]
    pub room_ref: Option<String>,
    /// `match`, `team`, `general` or `other`.
    #[serde(deserialize_with = "nn")]
    pub kind: String,
    pub since: Option<String>,
}

/// The game a person is in. The server details are only set for a server the viewer may see.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct PersonGame {
    /// `steam` or `query`.
    #[serde(deserialize_with = "nn")]
    pub source: String,
    #[serde(deserialize_with = "nn")]
    pub game: String,
    pub server_title: Option<String>,
    pub address: Option<String>,
    #[serde(deserialize_with = "id_opt")]
    pub port: Option<u64>,
    pub join_url: Option<String>,
    #[serde(deserialize_with = "id_opt")]
    pub link_id: Option<u64>,
    pub since: Option<String>,
    /// Follow answer only: the text to copy.
    pub copy_text: Option<String>,
}

/// One row of the people list.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct Person {
    #[serde(deserialize_with = "nn")]
    pub id: u64,
    #[serde(deserialize_with = "nn")]
    pub name: String,
    pub avatar_url: Option<String>,
    pub team: Option<String>,
    #[serde(deserialize_with = "nn")]
    pub is_me: bool,
    #[serde(deserialize_with = "nn")]
    pub is_teammate: bool,
    #[serde(deserialize_with = "nn")]
    pub is_friend: bool,
    #[serde(deserialize_with = "nn")]
    pub voice: Vec<PersonVoice>,
    pub game: Option<PersonGame>,
    pub last_seen_at: Option<String>,
}

/// `GET events/{event}/people`: my own entry (always, also when I opted out of sharing) and the
/// people who share their location.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct People {
    pub me: Option<Person>,
    #[serde(deserialize_with = "nn")]
    pub data: Vec<Person>,
    /// Paging info, passed through as it comes.
    pub meta: Value,
}

/// Filters of the people list.
#[derive(Debug, Clone, Default)]
pub struct PeopleQuery {
    /// Only my teammates.
    pub team: bool,
    /// Only my friends.
    pub friends: bool,
    /// Name contains.
    pub q: Option<String>,
    pub page: Option<u32>,
    pub per_page: Option<u32>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FollowUser {
    #[serde(deserialize_with = "nn")]
    pub id: u64,
    #[serde(deserialize_with = "nn")]
    pub name: String,
}

/// How to join a voice room: web voice carries `roomId` (join it with `join_room`), the other
/// providers carry a link (`url`).
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct FollowJoin {
    pub url: Option<String>,
    #[serde(deserialize_with = "id_opt")]
    pub room_id: Option<u64>,
    pub token_path: Option<String>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct FollowVoice {
    #[serde(deserialize_with = "nn")]
    pub provider: String,
    pub room_label: Option<String>,
    #[serde(deserialize_with = "nn")]
    pub kind: String,
    #[serde(deserialize_with = "nn")]
    pub joinable: bool,
    pub join: Option<FollowJoin>,
}

/// `GET events/{event}/people/{user}/follow`: where I can go to be where that person is.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct FollowTargets {
    #[serde(deserialize_with = "nn")]
    pub user: FollowUser,
    /// A provider the person is on and I am already connected to; also first in `voice`.
    pub preferred_provider: Option<String>,
    #[serde(deserialize_with = "nn")]
    pub voice: Vec<FollowVoice>,
    pub game: Option<PersonGame>,
}

impl ApiClient {
    /// `GET events/{event}/people` with the filters that are set.
    pub async fn people(&self, event: &str, query: &PeopleQuery) -> Result<People> {
        let mut params: Vec<(&str, String)> = Vec::new();
        if query.team {
            params.push(("team", "1".into()));
        }
        if query.friends {
            params.push(("friends", "1".into()));
        }
        if let Some(q) = query.q.as_deref().map(str::trim).filter(|q| !q.is_empty()) {
            params.push(("q", q.chars().take(100).collect()));
        }
        if let Some(p) = query.page {
            params.push(("page", p.max(1).to_string()));
        }
        if let Some(n) = query.per_page {
            params.push(("per_page", n.clamp(1, 100).to_string()));
        }
        self.get_v1(&format!("events/{}/people", seg(event)?), &params)
            .await
    }

    /// `GET events/{event}/people/{user}/follow`.
    pub async fn follow_person(&self, event: &str, user_id: u64) -> Result<FollowTargets> {
        self.get_v1(
            &format!("events/{}/people/{user_id}/follow", seg(event)?),
            &[],
        )
        .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::Error;
    use serde_json::json;
    use wiremock::matchers::{method, path, query_param};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    const V1: &str = "/api/voice-client/v1";

    fn client(server: &MockServer) -> ApiClient {
        ApiClient::new(&server.uri()).unwrap().with_token("t")
    }

    // The documented shapes (docs/infra/native-voice-client-api.md, "People").
    fn people_json() -> Value {
        json!({
          "me": {"id": 7, "name": "Tester", "avatarUrl": null, "team": "Alpha Crew", "isMe": true,
                 "isTeammate": false, "isFriend": false, "voice": [], "game": null, "lastSeenAt": null},
          "data": [
            {"id": 12, "name": "Ada", "avatarUrl": null, "team": "Alpha Crew", "isMe": false,
             "isTeammate": true, "isFriend": false,
             "voice": [{"provider": "webvoice", "roomLabel": "Team Alpha", "roomRef": "41", "kind": "match",
                        "since": "2026-11-04T20:01:00+01:00"}],
             "game": {"source": "steam", "game": "Counter-Strike 2", "serverTitle": "Match 12",
                      "address": "203.0.113.5", "port": 27015, "joinUrl": "steam://connect/203.0.113.5:27015",
                      "linkId": 7, "since": "2026-11-04T20:03:00+01:00"},
             "lastSeenAt": "2026-11-04T20:03:30+01:00"},
            {"id": 13, "name": "Ben", "avatarUrl": "https://cdn.example.test/b.png", "team": null,
             "isMe": false, "isTeammate": false, "isFriend": true,
             "voice": [{"provider": "discord", "roomLabel": "Allgemein", "roomRef": null, "kind": "other",
                        "since": null}],
             "game": {"source": "steam", "game": "Minecraft"}, "lastSeenAt": null}
          ],
          "meta": {"current_page": 1, "per_page": 50, "total": 2},
          "future_field": [1, 2]
        })
    }

    #[tokio::test]
    async fn reads_the_documented_people_answer() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/lan-2026/people")))
            .respond_with(ResponseTemplate::new(200).set_body_json(people_json()))
            .mount(&server)
            .await;
        let p = client(&server)
            .people("lan-2026", &PeopleQuery::default())
            .await
            .unwrap();
        assert_eq!(p.me.as_ref().map(|m| m.id), Some(7));
        assert_eq!(p.data.len(), 2);
        let ada = &p.data[0];
        assert!(ada.is_teammate && !ada.is_friend);
        assert_eq!(ada.voice[0].room_ref.as_deref(), Some("41"));
        let game = ada.game.as_ref().unwrap();
        assert_eq!(game.port, Some(27015));
        assert_eq!(game.link_id, Some(7));
        let ben = &p.data[1];
        assert_eq!(ben.voice[0].room_ref, None);
        // Another match's server: the game name only.
        let g = ben.game.as_ref().unwrap();
        assert_eq!(g.game, "Minecraft");
        assert!(g.join_url.is_none() && g.address.is_none() && g.port.is_none());
        assert_eq!(p.meta["total"], 2);
        // It serialises back with the server's camelCase keys.
        let back = serde_json::to_value(&p).unwrap();
        assert_eq!(back["data"][0]["isTeammate"], true);
        assert_eq!(back["data"][0]["voice"][0]["roomLabel"], "Team Alpha");
    }

    #[tokio::test]
    async fn nulls_missing_fields_and_odd_types_never_fail_a_read() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/lan-2026/people")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "me": null,
              "data": [
                {"id": 1, "name": null, "isMe": null, "isTeammate": null, "voice": null, "game": null},
                {"id": 2, "name": "Cy", "voice": [{"provider": null, "roomRef": 41, "kind": null},
                                                    {"provider": "someday", "roomRef": ""}],
                 "game": {"source": null, "game": null, "port": null, "linkId": "7"}}
              ],
              "meta": null
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/empty/people")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"data": null})))
            .mount(&server)
            .await;
        let c = client(&server);
        let p = c.people("lan-2026", &PeopleQuery::default()).await.unwrap();
        assert!(p.me.is_none());
        assert_eq!(p.data[0].name, "");
        assert!(p.data[0].voice.is_empty() && p.data[0].game.is_none());
        assert_eq!(p.data[1].voice[0].room_ref.as_deref(), Some("41"));
        assert_eq!(p.data[1].voice[1].room_ref, None);
        assert_eq!(p.data[1].voice[1].provider, "someday");
        assert_eq!(p.data[1].game.as_ref().unwrap().link_id, Some(7));
        let e = c.people("empty", &PeopleQuery::default()).await.unwrap();
        assert!(e.data.is_empty() && e.me.is_none());
    }

    #[tokio::test]
    async fn the_filters_become_query_parameters() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/lan-2026/people")))
            .and(query_param("team", "1"))
            .and(query_param("friends", "1"))
            .and(query_param("q", "ad"))
            .and(query_param("per_page", "100"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"data": []})))
            .expect(1)
            .mount(&server)
            .await;
        client(&server)
            .people(
                "lan-2026",
                &PeopleQuery {
                    team: true,
                    friends: true,
                    q: Some("  ad ".into()),
                    page: None,
                    per_page: Some(500),
                },
            )
            .await
            .unwrap();
    }

    #[tokio::test]
    async fn an_event_slug_cannot_escape_the_path() {
        let server = MockServer::start().await;
        let c = client(&server);
        assert!(matches!(
            c.people("../x", &PeopleQuery::default()).await,
            Err(Error::Usage(_))
        ));
        assert!(matches!(
            c.follow_person("a/b", 1).await,
            Err(Error::Usage(_))
        ));
    }

    #[tokio::test]
    async fn reads_the_documented_follow_targets() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/lan-2026/people/12/follow")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "user": {"id": 12, "name": "Ada"}, "preferredProvider": "discord",
              "voice": [
                {"provider": "discord", "roomLabel": "Allgemein", "kind": "other", "joinable": true,
                 "join": {"url": "https://discord.com/channels/1/2"}},
                {"provider": "webvoice", "roomLabel": "Lounge", "kind": "general", "joinable": true,
                 "join": {"roomId": 41, "tokenPath": "/api/voice-client/v1/rooms/41/token"}},
                {"provider": "mumble", "roomLabel": "Lobby", "kind": "other", "joinable": true,
                 "join": {"url": "mumble://Tester@voice.example/Lobby"}},
                {"provider": "teamspeak", "roomLabel": "Lobby", "kind": "other", "joinable": false, "join": null}],
              "game": {"source": "steam", "game": "Counter-Strike 2", "serverTitle": "Match 12",
                       "address": "203.0.113.5", "port": 27015, "joinUrl": "steam://connect/203.0.113.5:27015",
                       "linkId": 7, "since": "2026-11-04T20:03:00+01:00", "copyText": "203.0.113.5:27015"}
            })))
            .mount(&server)
            .await;
        let f = client(&server).follow_person("lan-2026", 12).await.unwrap();
        assert_eq!(f.user.name, "Ada");
        assert_eq!(f.preferred_provider.as_deref(), Some("discord"));
        assert_eq!(f.voice.len(), 4);
        assert_eq!(f.voice[1].join.as_ref().unwrap().room_id, Some(41));
        assert!(!f.voice[3].joinable && f.voice[3].join.is_none());
        assert_eq!(
            f.game.as_ref().and_then(|g| g.copy_text.as_deref()),
            Some("203.0.113.5:27015")
        );
    }

    #[tokio::test]
    async fn follow_nulls_and_the_no_oracle_404_are_handled() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/lan-2026/people/3/follow")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "user": null, "preferredProvider": null, "voice": null, "game": null})))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path(format!("{V1}/events/lan-2026/people/99/follow")))
            .respond_with(
                ResponseTemplate::new(404).set_body_json(json!({"message": "Nicht gefunden."})),
            )
            .mount(&server)
            .await;
        let c = client(&server);
        let f = c.follow_person("lan-2026", 3).await.unwrap();
        assert!(f.voice.is_empty() && f.game.is_none() && f.preferred_provider.is_none());
        match c.follow_person("lan-2026", 99).await {
            Err(Error::NotFound(m)) => assert_eq!(m, "Nicht gefunden."),
            other => panic!("{other:?}"),
        }
    }
}
