//! Play API: the "now" feed, tournaments, my matches and game servers. DTOs are camelCase, exactly
//! as `docs/infra/native-voice-client-api.md` spells them, and serialise back the same way so the
//! UI sees the server's shapes.

use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use super::{seg, ApiClient};
use crate::error::Result;

/// One card of `GET events/{event}/now`. `data` depends on `kind` (see the API doc) and stays a
/// JSON value so a new card kind never breaks parsing.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct NowCard {
    pub kind: String,
    pub id: String,
    pub state: String,
    pub title: String,
    pub body: Option<String>,
    pub at: Option<String>,
    pub data: Value,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct NowFeed {
    pub cards: Vec<NowCard>,
    pub more: Vec<NowCard>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct MyEntry {
    pub id: u64,
    pub status: String,
    pub can_withdraw: bool,
    pub is_owner: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct TournamentCan {
    pub check_in: bool,
    pub withdraw: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct TournamentRegistration {
    pub required: bool,
    pub open: bool,
    pub url: Option<String>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct Tournament {
    pub id: u64,
    pub name: String,
    pub game: Option<String>,
    pub format: String,
    pub status: String,
    pub starts_at: Option<String>,
    pub checkin_opens_at: Option<String>,
    pub checkin_closes_at: Option<String>,
    pub entry_count: u32,
    pub max_entries: Option<u32>,
    pub team_size: u32,
    pub my_entry: Option<MyEntry>,
    pub can: TournamentCan,
    pub registration: TournamentRegistration,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct MatchTournamentRef {
    pub id: u64,
    pub name: String,
    pub game: Option<String>,
    pub status: String,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct Side {
    pub entry_id: u64,
    pub name: String,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct PendingReport {
    pub reported_by_me: bool,
    pub own_score: u32,
    pub opponent_score: u32,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct MatchCan {
    pub report: bool,
    pub confirm: bool,
    pub dispute: bool,
    pub withdraw_report: bool,
    pub check_in: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct Match {
    pub id: u64,
    /// `active`, `upcoming` or `finished`.
    pub phase: String,
    pub status: String,
    pub tournament: MatchTournamentRef,
    pub round: Option<u32>,
    pub bracket: Option<String>,
    pub scheduled_at: Option<String>,
    pub warmup_started_at: Option<String>,
    pub updated_at: Option<String>,
    pub me: Option<Side>,
    pub opponent: Option<Side>,
    /// "own : opponent", kept as JSON because the doc leaves the exact shape open.
    pub score: Value,
    pub won: Option<bool>,
    pub lock_version: u64,
    pub pending_report: Option<PendingReport>,
    pub voice_room_id: Option<u64>,
    pub server: Option<GameServer>,
    pub can: MatchCan,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct InstallHint {
    pub steam_url: Option<String>,
    pub share_url: Option<String>,
    pub version_note: Option<String>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct GameServer {
    pub id: u64,
    pub title: String,
    pub game: Option<String>,
    pub match_label: Option<String>,
    pub description: Option<String>,
    /// `provisioning`, `ready`, `not_responding` or `offline`.
    pub status: String,
    pub players: Option<u32>,
    pub max_players: Option<u32>,
    pub map: Option<String>,
    pub checked_at: Option<String>,
    pub address: Option<String>,
    pub port: Option<u16>,
    pub connect_string: Option<String>,
    pub connect_is_link: bool,
    pub install_hint: Option<InstallHint>,
    /// The `steam://connect/...` link, only while `ready`. Open it with `open_join_url`.
    pub join_url: Option<String>,
    /// What to copy, only while `ready` or `not_responding`.
    pub copy_text: Option<String>,
}

#[derive(Deserialize)]
struct TournamentsBody {
    #[serde(default)]
    tournaments: Vec<Tournament>,
}
#[derive(Deserialize)]
struct MatchesBody {
    #[serde(default)]
    matches: Vec<Match>,
}
#[derive(Deserialize)]
struct ServersBody {
    #[serde(default)]
    servers: Vec<GameServer>,
}
#[derive(Deserialize)]
struct TournamentBody {
    tournament: Tournament,
}
#[derive(Deserialize)]
struct MatchBody {
    #[serde(rename = "match")]
    the_match: Match,
}

impl ApiClient {
    /// `GET events/{event}/now`.
    pub async fn now(&self, event: &str) -> Result<NowFeed> {
        self.get_v1(&format!("events/{}/now", seg(event)?), &[])
            .await
    }

    /// `GET events/{event}/tournaments`.
    pub async fn tournaments(&self, event: &str) -> Result<Vec<Tournament>> {
        let b: TournamentsBody = self
            .get_v1(&format!("events/{}/tournaments", seg(event)?), &[])
            .await?;
        Ok(b.tournaments)
    }

    /// `GET events/{event}/my-matches`.
    pub async fn my_matches(&self, event: &str) -> Result<Vec<Match>> {
        let b: MatchesBody = self
            .get_v1(&format!("events/{}/my-matches", seg(event)?), &[])
            .await?;
        Ok(b.matches)
    }

    /// `POST tournaments/{tournament}/checkin`.
    pub async fn tournament_checkin(&self, tournament_id: u64) -> Result<Tournament> {
        let b: TournamentBody = self
            .send_v1(
                Method::POST,
                &format!("tournaments/{tournament_id}/checkin"),
                None,
            )
            .await?;
        Ok(b.tournament)
    }

    /// `POST matches/{match}/report`: `score1 : score2` is own : opponent, no tie.
    pub async fn match_report(&self, match_id: u64, score1: u32, score2: u32) -> Result<Match> {
        let b: MatchBody = self
            .send_v1(
                Method::POST,
                &format!("matches/{match_id}/report"),
                Some(json!({"score1": score1, "score2": score2})),
            )
            .await?;
        Ok(b.the_match)
    }

    /// `POST matches/{match}/confirm` (the opponent confirms a pending claim).
    pub async fn match_confirm(&self, match_id: u64, lock_version: u64) -> Result<Match> {
        let b: MatchBody = self
            .send_v1(
                Method::POST,
                &format!("matches/{match_id}/confirm"),
                Some(json!({"lock_version": lock_version})),
            )
            .await?;
        Ok(b.the_match)
    }

    /// `POST matches/{match}/dispute`.
    pub async fn match_dispute(&self, match_id: u64) -> Result<Match> {
        let b: MatchBody = self
            .send_v1(Method::POST, &format!("matches/{match_id}/dispute"), None)
            .await?;
        Ok(b.the_match)
    }

    /// `POST matches/{match}/report/withdraw` (the reporting side takes its claim back).
    pub async fn match_withdraw_report(&self, match_id: u64, lock_version: u64) -> Result<Match> {
        let b: MatchBody = self
            .send_v1(
                Method::POST,
                &format!("matches/{match_id}/report/withdraw"),
                Some(json!({"lock_version": lock_version})),
            )
            .await?;
        Ok(b.the_match)
    }

    /// `GET events/{event}/servers`.
    pub async fn event_servers(&self, event: &str) -> Result<Vec<GameServer>> {
        let b: ServersBody = self
            .get_v1(&format!("events/{}/servers", seg(event)?), &[])
            .await?;
        Ok(b.servers)
    }

    /// `GET servers` (the permanent ones).
    pub async fn permanent_servers(&self) -> Result<Vec<GameServer>> {
        let b: ServersBody = self.get_v1("servers", &[]).await?;
        Ok(b.servers)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::Error;
    use wiremock::matchers::{body_json, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn client(server: &MockServer) -> ApiClient {
        ApiClient::new(&server.uri()).unwrap().with_token("t")
    }

    fn server_json() -> Value {
        json!({
          "id": 21, "title": "CS2 Cup Runde 2", "game": "Counter-Strike 2", "matchLabel": "CS2 Cup, Runde 2",
          "description": null, "status": "ready",
          "players": 4, "maxPlayers": 10, "map": "de_mirage", "checkedAt": "2026-11-07T18:21:00+00:00",
          "address": "203.0.113.5", "port": 27015,
          "connectString": "steam://connect/203.0.113.5:27015", "connectIsLink": true,
          "installHint": {"steamUrl": "steam://store/730", "shareUrl": null, "versionNote": null},
          "joinUrl": "steam://connect/203.0.113.5:27015",
          "copyText": "203.0.113.5:27015"
        })
    }

    fn match_json() -> Value {
        json!({
          "id": 88, "phase": "active", "status": "ready",
          "tournament": {"id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "status": "live"},
          "round": 2, "bracket": "winners",
          "scheduledAt": null, "warmupStartedAt": null, "updatedAt": "2026-11-07T18:20:00+00:00",
          "me": {"entryId": 31, "name": "Team Rot"}, "opponent": {"entryId": 35, "name": "Team Blau"},
          "score": null, "won": null, "lockVersion": 0,
          "pendingReport": null,
          "voiceRoomId": 14,
          "server": server_json(),
          "can": {"report": true, "confirm": false, "dispute": false, "withdrawReport": false, "checkIn": false}
        })
    }

    #[tokio::test]
    async fn now_feed_keeps_card_data_as_json() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/lan-party-2026/now"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "cards": [
                {"kind": "match", "id": "match:88", "state": "ready", "title": "Dein Match ist bereit",
                 "body": "gegen Team Blau", "at": null, "data": {"match": match_json()}},
                {"kind": "vote", "id": "vote:3", "state": "open", "title": "Abstimmung", "body": null,
                 "at": "2026-11-07T19:00:00+00:00",
                 "data": {"pollId": 3, "question": "Which map?", "closesAt": "2026-11-07T19:00:00+00:00",
                          "options": [{"id": 12, "label": "Dust"}]}}
              ],
              "more": []
            })))
            .mount(&server)
            .await;
        let feed = client(&server).now("lan-party-2026").await.unwrap();
        assert_eq!(feed.cards.len(), 2);
        assert_eq!(feed.cards[0].id, "match:88");
        let m: Match = serde_json::from_value(feed.cards[0].data["match"].clone()).unwrap();
        assert_eq!(m.voice_room_id, Some(14));
        assert_eq!(feed.cards[1].data["options"][0]["label"], "Dust");
        assert!(feed.more.is_empty());
    }

    #[tokio::test]
    async fn tournaments_and_matches_parse_the_documented_dtos() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/tournaments"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"tournaments": [{
              "id": 7, "name": "CS2 Cup", "game": "Counter-Strike 2", "format": "single_elimination", "status": "check_in",
              "startsAt": "2026-11-07T18:00:00+00:00",
              "checkinOpensAt": "2026-11-07T17:30:00+00:00", "checkinClosesAt": "2026-11-07T17:55:00+00:00",
              "entryCount": 12, "maxEntries": 16, "teamSize": 1,
              "myEntry": {"id": 31, "status": "registered", "canWithdraw": true, "isOwner": true},
              "can": {"checkIn": true, "withdraw": true},
              "registration": {"required": false, "open": false, "url": "https://app.example/events/e/register"}
            }]})))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/my-matches"))
            .respond_with(
                ResponseTemplate::new(200).set_body_json(json!({"matches": [match_json()]})),
            )
            .mount(&server)
            .await;
        let c = client(&server);
        let t = c.tournaments("e").await.unwrap();
        assert!(t[0].can.check_in && t[0].my_entry.as_ref().unwrap().is_owner);
        assert_eq!(t[0].max_entries, Some(16));
        let m = c.my_matches("e").await.unwrap();
        let srv = m[0].server.as_ref().unwrap();
        assert_eq!(
            srv.join_url.as_deref(),
            Some("steam://connect/203.0.113.5:27015")
        );
        assert_eq!(srv.copy_text.as_deref(), Some("203.0.113.5:27015"));
        assert!(m[0].can.report && !m[0].can.confirm);
    }

    #[tokio::test]
    async fn match_actions_send_the_documented_bodies() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/tournaments/7/checkin"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_body_json(json!({"tournament": {"id": 7, "name": "CS2 Cup"}})),
            )
            .mount(&server)
            .await;
        for (p, body) in [
            ("report", Some(json!({"score1": 2, "score2": 1}))),
            ("confirm", Some(json!({"lock_version": 3}))),
            ("dispute", None),
            ("report/withdraw", Some(json!({"lock_version": 4}))),
        ] {
            let m = Mock::given(method("POST"))
                .and(path(format!("/api/voice-client/v1/matches/88/{p}")));
            let m = match body {
                Some(b) => m.and(body_json(b)),
                None => m,
            };
            m.respond_with(
                ResponseTemplate::new(200).set_body_json(json!({"match": match_json()})),
            )
            .expect(1)
            .mount(&server)
            .await;
        }
        let c = client(&server);
        assert_eq!(c.tournament_checkin(7).await.unwrap().id, 7);
        assert_eq!(c.match_report(88, 2, 1).await.unwrap().id, 88);
        c.match_confirm(88, 3).await.unwrap();
        c.match_dispute(88).await.unwrap();
        c.match_withdraw_report(88, 4).await.unwrap();
    }

    #[tokio::test]
    async fn stale_lock_version_is_a_conflict_and_domain_rules_are_validation() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/matches/88/confirm"))
            .respond_with(ResponseTemplate::new(409).set_body_json(json!({
                "message": "Das Match hat sich geändert.", "error": "tournaments.errors.stale_match"
            })))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/tournaments/7/checkin"))
            .respond_with(ResponseTemplate::new(422).set_body_json(json!({
                "message": "Das Einchecken ist geschlossen.", "error": "tournaments.errors.checkin_closed"
            })))
            .mount(&server)
            .await;
        let c = client(&server);
        match c.match_confirm(88, 0).await.unwrap_err() {
            Error::Conflict { code, message } => {
                assert_eq!(code.as_deref(), Some("tournaments.errors.stale_match"));
                assert!(message.contains("geändert"));
            }
            e => panic!("{e:?}"),
        }
        match c.tournament_checkin(7).await.unwrap_err() {
            Error::Validation { code, message, .. } => {
                assert_eq!(code.as_deref(), Some("tournaments.errors.checkin_closed"));
                assert!(message.contains("geschlossen"));
            }
            e => panic!("{e:?}"),
        }
    }

    #[tokio::test]
    async fn servers_event_and_permanent() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/servers"))
            .respond_with(
                ResponseTemplate::new(200).set_body_json(json!({"servers": [server_json()]})),
            )
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/servers"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"servers": [
                {"id": 2, "title": "Minecraft", "status": "offline", "joinUrl": null, "copyText": null, "connectIsLink": false}
            ]})))
            .mount(&server)
            .await;
        let c = client(&server);
        let e = c.event_servers("e").await.unwrap();
        assert_eq!(e[0].players, Some(4));
        let p = c.permanent_servers().await.unwrap();
        assert!(p[0].join_url.is_none() && p[0].copy_text.is_none());
        assert_eq!(p[0].status, "offline");
    }

    #[tokio::test]
    async fn module_off_is_a_not_found() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/servers"))
            .respond_with(ResponseTemplate::new(404).set_body_json(json!({"message": "Not Found"})))
            .mount(&server)
            .await;
        assert!(matches!(
            client(&server).permanent_servers().await,
            Err(Error::NotFound(_))
        ));
    }
}
