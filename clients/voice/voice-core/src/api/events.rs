//! `GET events`: the event list and the event a client should preselect.

use serde::{Deserialize, Serialize};

use super::ApiClient;
use crate::error::Result;

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct EventRegistration {
    pub status: String,
    pub ticket_type: Option<String>,
    pub paid: bool,
    pub checked_in: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct EventSummary {
    pub id: u64,
    pub slug: String,
    pub name: String,
    pub status: String,
    pub starts_at: Option<String>,
    pub ends_at: Option<String>,
    pub location: Option<String>,
    /// True on exactly one row: the event to preselect.
    pub current: bool,
    /// `None` without an active registration.
    pub registration: Option<EventRegistration>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct EventsResponse {
    pub current_event_slug: Option<String>,
    pub events: Vec<EventSummary>,
}

impl EventsResponse {
    /// The preselected event, if the server named one that is in the list.
    pub fn current(&self) -> Option<&EventSummary> {
        let slug = self.current_event_slug.as_deref()?;
        self.events.iter().find(|e| e.slug == slug)
    }
}

impl ApiClient {
    /// `GET events`.
    pub async fn events(&self) -> Result<EventsResponse> {
        self.get_v1("events", &[]).await
    }

    /// The event to preselect (`current_event_slug`), if any.
    pub async fn current_event(&self) -> Result<Option<EventSummary>> {
        Ok(self.events().await?.current().cloned())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{header, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    #[tokio::test]
    async fn parses_the_documented_example_and_picks_the_current_event() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events"))
            .and(header("authorization", "Bearer t"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
              "current_event_slug": "lan-party-2026",
              "events": [{
                "id": 7, "slug": "lan-party-2026", "name": "LAN Party 2026", "status": "live",
                "starts_at": "2026-11-06T16:00:00+00:00", "ends_at": "2026-11-08T12:00:00+00:00",
                "location": "Stadthalle", "current": true, "future_field": 1,
                "registration": {"status": "confirmed", "ticket_type": "standard", "paid": true, "checked_in": false}
              }, {"id": 8, "slug": "old", "name": "Old", "status": "finished", "registration": null}]
            })))
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        let cur = c.current_event().await.unwrap().unwrap();
        assert_eq!(cur.slug, "lan-party-2026");
        assert!(cur.registration.unwrap().paid);
        let all = c.events().await.unwrap();
        assert_eq!(all.events.len(), 2);
        assert!(all.events[1].registration.is_none());
    }

    #[tokio::test]
    async fn no_current_event_is_none() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_body_json(serde_json::json!({"current_event_slug": null, "events": []})),
            )
            .mount(&server)
            .await;
        let c = ApiClient::new(&server.uri()).unwrap().with_token("t");
        assert!(c.current_event().await.unwrap().is_none());
    }
}
