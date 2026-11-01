//! In-app notifications (bell list): list, unread count, read, read-all, preferences.

use std::collections::BTreeMap;

use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::json;

use super::{seg, ApiClient};
use crate::error::Result;

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct Notification {
    /// A UUID (not ordered): de-duplicate by id, order by `created_at`.
    pub id: String,
    pub category: String,
    pub title: String,
    pub body: Option<String>,
    pub read: bool,
    pub read_at: Option<String>,
    pub created_at: String,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct NotificationsPage {
    pub notifications: Vec<Notification>,
    pub page: u32,
    pub per_page: u32,
    pub total: u64,
    pub has_more: bool,
}

#[derive(Deserialize)]
struct UnreadBody {
    #[serde(default)]
    unread: u64,
}
#[derive(Deserialize)]
struct OneBody {
    notification: Notification,
}
#[derive(Deserialize)]
struct MarkedBody {
    #[serde(default)]
    marked: u64,
}
#[derive(Deserialize)]
struct PrefsBody {
    #[serde(default)]
    preferences: BTreeMap<String, bool>,
}

impl ApiClient {
    /// `GET notifications?unread=1&per_page=..&page=..` (`per_page` is clamped to 1..=50).
    pub async fn notifications(
        &self,
        unread_only: bool,
        page: u32,
        per_page: u32,
    ) -> Result<NotificationsPage> {
        let mut q = vec![
            ("per_page", per_page.clamp(1, 50).to_string()),
            ("page", page.max(1).to_string()),
        ];
        if unread_only {
            q.push(("unread", "1".into()));
        }
        self.get_v1("notifications", &q).await
    }

    /// `GET notifications/unread-count`.
    pub async fn unread_count(&self) -> Result<u64> {
        let b: UnreadBody = self.get_v1("notifications/unread-count", &[]).await?;
        Ok(b.unread)
    }

    /// `POST notifications/{id}/read`.
    pub async fn mark_notification_read(&self, id: &str) -> Result<Notification> {
        let b: OneBody = self
            .send_v1(
                Method::POST,
                &format!("notifications/{}/read", seg(id)?),
                None,
            )
            .await?;
        Ok(b.notification)
    }

    /// `POST notifications/read-all`; returns how many were marked.
    pub async fn mark_all_notifications_read(&self) -> Result<u64> {
        let b: MarkedBody = self
            .send_v1(Method::POST, "notifications/read-all", None)
            .await?;
        Ok(b.marked)
    }

    /// `GET notification-preferences`: the server-side switches that gate the bell and Discord.
    pub async fn notification_preferences(&self) -> Result<BTreeMap<String, bool>> {
        let b: PrefsBody = self.get_v1("notification-preferences", &[]).await?;
        Ok(b.preferences)
    }

    /// `PUT notification-preferences`: partial update, returns the effective preferences.
    pub async fn set_notification_preferences(
        &self,
        changes: &BTreeMap<String, bool>,
    ) -> Result<BTreeMap<String, bool>> {
        let b: PrefsBody = self
            .send_v1(
                Method::PUT,
                "notification-preferences",
                Some(json!({"preferences": changes})),
            )
            .await?;
        Ok(b.preferences)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{body_json, method, path, query_param};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn n() -> serde_json::Value {
        json!({"id": "b2c7c9a6-1f5e-4a7e-9d5b-0a1e2c3d4e5f", "category": "match", "title": "Dein Match startet",
               "body": "...", "read": false, "read_at": null, "created_at": "2026-11-02T12:00:00+00:00"})
    }

    #[tokio::test]
    async fn list_count_read_and_read_all() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/notifications"))
            .and(query_param("unread", "1"))
            .and(query_param("per_page", "50"))
            .and(query_param("page", "1"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
                "notifications": [n()], "page": 1, "per_page": 50, "total": 1, "has_more": false
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/notifications/unread-count"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"unread": 3})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path(
                "/api/voice-client/v1/notifications/b2c7c9a6-1f5e-4a7e-9d5b-0a1e2c3d4e5f/read",
            ))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"notification": n()})))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/notifications/read-all"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"marked": 3})))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        let page = c.notifications(true, 0, 500).await.unwrap();
        assert_eq!(page.notifications[0].category, "match");
        assert!(!page.has_more);
        assert_eq!(c.unread_count().await.unwrap(), 3);
        let one = c
            .mark_notification_read("b2c7c9a6-1f5e-4a7e-9d5b-0a1e2c3d4e5f")
            .await
            .unwrap();
        assert_eq!(one.title, "Dein Match startet");
        assert_eq!(c.mark_all_notifications_read().await.unwrap(), 3);
    }

    #[tokio::test]
    async fn preferences_get_and_partial_put() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/notification-preferences"))
            .respond_with(ResponseTemplate::new(200).set_body_json(
                json!({"preferences": {"registration": true, "match": true, "catering": true}}),
            ))
            .mount(&server)
            .await;
        Mock::given(method("PUT"))
            .and(path("/api/voice-client/v1/notification-preferences"))
            .and(body_json(json!({"preferences": {"match": false}})))
            .respond_with(ResponseTemplate::new(200).set_body_json(
                json!({"preferences": {"registration": true, "match": false, "catering": true}}),
            ))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        assert_eq!(c.notification_preferences().await.unwrap().len(), 3);
        let mut ch = BTreeMap::new();
        ch.insert("match".to_owned(), false);
        let eff = c.set_notification_preferences(&ch).await.unwrap();
        assert_eq!(eff.get("match"), Some(&false));
    }

    #[tokio::test]
    async fn unknown_category_is_a_validation_error_with_field_errors() {
        let server = MockServer::start().await;
        Mock::given(method("PUT"))
            .and(path("/api/voice-client/v1/notification-preferences"))
            .respond_with(ResponseTemplate::new(422).set_body_json(json!({
                "message": "Ungültig.", "errors": {"preferences.nope": ["Unbekannte Kategorie."]}
            })))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        let mut ch = BTreeMap::new();
        ch.insert("nope".to_owned(), true);
        match c.set_notification_preferences(&ch).await.unwrap_err() {
            crate::Error::Validation { fields, .. } => {
                assert_eq!(fields["preferences.nope"][0], "Unbekannte Kategorie.")
            }
            e => panic!("{e:?}"),
        }
    }
}
