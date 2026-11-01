//! Polls (module Voting): list, detail, vote. JSON keys are snake_case as on the server.

use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::json;

use super::{seg, ApiClient};
use crate::error::Result;

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PollSummary {
    pub id: u64,
    pub question: String,
    /// `standard` or `mvp`.
    pub kind: String,
    pub status: String,
    /// The live state: an `open` poll past `closes_at` is not open.
    pub is_open: bool,
    pub closes_at: Option<String>,
    pub total_votes: u64,
    pub my_vote_option_id: Option<u64>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PollsList {
    pub event_slug: String,
    pub polls: Vec<PollSummary>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PollOption {
    pub id: u64,
    pub label: String,
    pub count: u64,
    pub percent: f64,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PollDetail {
    pub id: u64,
    pub event_slug: String,
    pub question: String,
    pub kind: String,
    pub status: String,
    pub is_open: bool,
    pub closes_at: Option<String>,
    pub total_votes: u64,
    pub options: Vec<PollOption>,
    pub my_vote_option_id: Option<u64>,
}

impl ApiClient {
    /// `GET events/{event}/polls`.
    pub async fn polls(&self, event: &str) -> Result<PollsList> {
        self.get_v1(&format!("events/{}/polls", seg(event)?), &[])
            .await
    }

    /// `GET polls/{poll}`.
    pub async fn poll(&self, poll_id: u64) -> Result<PollDetail> {
        self.get_v1(&format!("polls/{poll_id}"), &[]).await
    }

    /// `POST polls/{poll}/vote`. One vote per user, no changing it (`422 already_voted`).
    pub async fn vote(&self, poll_id: u64, option_id: u64) -> Result<PollDetail> {
        self.send_v1(
            Method::POST,
            &format!("polls/{poll_id}/vote"),
            Some(json!({"option_id": option_id})),
        )
        .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::Error;
    use wiremock::matchers::{body_json, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn detail() -> serde_json::Value {
        json!({
          "id": 3, "event_slug": "lan-party-2026", "question": "Which map?", "kind": "standard",
          "status": "open", "is_open": true, "closes_at": null, "total_votes": 3,
          "options": [{"id": 12, "label": "Dust", "count": 2, "percent": 66.7}, {"id": 13, "label": "Mirage", "count": 1, "percent": 33.3}],
          "my_vote_option_id": 12
        })
    }

    #[tokio::test]
    async fn list_detail_and_vote() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/polls"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "event_slug": "e",
              "polls": [{"id": 3, "question": "Which map?", "kind": "standard", "status": "open",
                         "is_open": true, "closes_at": null, "total_votes": 3, "my_vote_option_id": null}]
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/polls/3"))
            .respond_with(ResponseTemplate::new(200).set_body_json(detail()))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/polls/3/vote"))
            .and(body_json(json!({"option_id": 12})))
            .respond_with(ResponseTemplate::new(201).set_body_json(detail()))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        let l = c.polls("e").await.unwrap();
        assert!(l.polls[0].is_open && l.polls[0].my_vote_option_id.is_none());
        let d = c.poll(3).await.unwrap();
        assert_eq!(d.options.len(), 2);
        assert!((d.options[0].percent - 66.7).abs() < 1e-9);
        let v = c.vote(3, 12).await.unwrap();
        assert_eq!(v.my_vote_option_id, Some(12));
    }

    #[tokio::test]
    async fn a_second_vote_is_a_validation_error_with_code() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/polls/3/vote"))
            .respond_with(ResponseTemplate::new(422).set_body_json(
                json!({"code": "already_voted", "message": "Du hast schon abgestimmt."}),
            ))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        match c.vote(3, 12).await.unwrap_err() {
            Error::Validation { code, message, .. } => {
                assert_eq!(code.as_deref(), Some("already_voted"));
                assert!(message.starts_with("Du hast"));
            }
            e => panic!("{e:?}"),
        }
    }
}
