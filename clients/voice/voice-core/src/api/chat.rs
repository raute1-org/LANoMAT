//! Text chat of a voice room: history paging, post, delete (moderation).

use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::json;

use super::ApiClient;
use crate::error::Result;

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct ChatAuthor {
    pub id: u64,
    pub name: String,
    pub avatar_url: Option<String>,
}

/// The message DTO, identical to the REST payload and to the realtime `message.posted` event.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct ChatMessage {
    pub id: u64,
    pub channel_id: u64,
    pub body: String,
    pub created_at: String,
    pub author: ChatAuthor,
}

/// A page of history: oldest first inside the page, ready to append (or prepend for older pages).
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct MessagesPage {
    pub messages: Vec<ChatMessage>,
    /// Pass as `before` for the next older page; `None` when the history is exhausted.
    pub next_before: Option<u64>,
}

#[derive(Deserialize)]
struct PostBody {
    message: ChatMessage,
}

impl ApiClient {
    /// `GET rooms/{room}/messages?before=<id>`.
    pub async fn messages(&self, room_id: u64, before: Option<u64>) -> Result<MessagesPage> {
        let q: Vec<(&str, String)> = before
            .map(|b| ("before", b.to_string()))
            .into_iter()
            .collect();
        self.get_v1(&format!("rooms/{room_id}/messages"), &q).await
    }

    /// `POST rooms/{room}/messages` (trimmed, 1 to 500 characters; 10 per 10 s per user).
    pub async fn post_message(&self, room_id: u64, body: &str) -> Result<ChatMessage> {
        let b: PostBody = self
            .send_v1(
                Method::POST,
                &format!("rooms/{room_id}/messages"),
                Some(json!({"body": body})),
            )
            .await?;
        Ok(b.message)
    }

    /// `DELETE messages/{message}` (admin and orga only).
    pub async fn delete_message(&self, message_id: u64) -> Result<()> {
        self.send_v1_empty(Method::DELETE, &format!("messages/{message_id}"))
            .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::Error;
    use wiremock::matchers::{body_json, method, path, query_param};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn msg(id: u64) -> serde_json::Value {
        json!({"id": id, "channel_id": 3, "body": "gl hf", "created_at": "2026-11-02T12:00:00+00:00",
               "author": {"id": 7, "name": "Ada", "avatar_url": null}})
    }

    #[tokio::test]
    async fn history_pages_with_before() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/rooms/3/messages"))
            .and(query_param("before", "410"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_body_json(json!({"messages": [msg(405), msg(406)], "next_before": null})),
            )
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/rooms/3/messages"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_body_json(json!({"messages": [msg(410), msg(412)], "next_before": 410})),
            )
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        let latest = c.messages(3, None).await.unwrap();
        assert_eq!(latest.messages.last().unwrap().id, 412);
        assert_eq!(latest.next_before, Some(410));
        let older = c.messages(3, latest.next_before).await.unwrap();
        assert_eq!(older.messages[0].id, 405);
        assert_eq!(older.next_before, None);
    }

    #[tokio::test]
    async fn post_delete_and_error_mapping() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/rooms/3/messages"))
            .and(body_json(json!({"body": "gl hf"})))
            .respond_with(ResponseTemplate::new(201).set_body_json(json!({"message": msg(413)})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/rooms/4/messages"))
            .respond_with(
                ResponseTemplate::new(429)
                    .insert_header("Retry-After", "7")
                    .set_body_json(json!({"message": "Too Many Attempts."})),
            )
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path("/api/voice-client/v1/messages/413"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"ok": true})))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path("/api/voice-client/v1/messages/1"))
            .respond_with(ResponseTemplate::new(403).set_body_json(json!({"message": "x"})))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        assert_eq!(c.post_message(3, "gl hf").await.unwrap().id, 413);
        match c.post_message(4, "x").await.unwrap_err() {
            Error::RateLimited { retry_after_secs } => assert_eq!(retry_after_secs, Some(7)),
            e => panic!("{e:?}"),
        }
        c.delete_message(413).await.unwrap();
        assert!(matches!(
            c.delete_message(1).await,
            Err(Error::Forbidden(_))
        ));
    }
}
