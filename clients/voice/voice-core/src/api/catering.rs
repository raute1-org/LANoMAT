//! Catering (module Catering): menu and rounds, my items, place and cancel an item. Money is
//! integer cents, JSON keys are snake_case as on the server.

use reqwest::Method;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use super::{seg, ApiClient};
use crate::error::{Error, Result};

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct MenuEntry {
    pub key: String,
    pub name: String,
    pub price_cents: i64,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct MyItem {
    pub id: u64,
    pub option_key: String,
    pub option_name: String,
    pub note: Option<String>,
    pub price_cents: i64,
    pub paid: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct FoodOrder {
    pub id: u64,
    pub title: String,
    pub status: String,
    pub is_open: bool,
    pub opens_at: Option<String>,
    pub closes_at: Option<String>,
    pub menu: Vec<MenuEntry>,
    pub my_items: Vec<MyItem>,
    pub my_total_cents: i64,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct CateringLimits {
    pub items_per_user: u32,
    pub my_open_items: u32,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct Catering {
    pub event_slug: String,
    pub registration_required: bool,
    pub limits: CateringLimits,
    pub orders: Vec<FoodOrder>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct MineItem {
    pub id: u64,
    pub option_key: String,
    pub option_name: String,
    pub note: Option<String>,
    pub price_cents: i64,
    pub paid: bool,
    pub order_id: u64,
    pub order_title: String,
    pub order_status: String,
    pub order_is_open: bool,
    pub can_cancel: bool,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct MineResponse {
    pub items: Vec<MineItem>,
    pub total_cents: i64,
    pub paid_cents: i64,
}

impl ApiClient {
    /// `GET events/{event}/catering`.
    pub async fn catering(&self, event: &str) -> Result<Catering> {
        self.get_v1(&format!("events/{}/catering", seg(event)?), &[])
            .await
    }

    /// `GET events/{event}/catering/mine`.
    pub async fn catering_mine(&self, event: &str) -> Result<MineResponse> {
        self.get_v1(&format!("events/{}/catering/mine", seg(event)?), &[])
            .await
    }

    /// `POST food-orders/{order}/items` (`201` with the item; the price comes from the menu).
    pub async fn place_food_item(
        &self,
        order_id: u64,
        option_key: &str,
        note: Option<&str>,
    ) -> Result<MyItem> {
        let mut body = json!({"option_key": option_key});
        if let Some(n) = note.map(str::trim).filter(|n| !n.is_empty()) {
            body["note"] = Value::String(n.to_owned());
        }
        let v: Value = self
            .send_v1(
                Method::POST,
                &format!("food-orders/{order_id}/items"),
                Some(body),
            )
            .await?;
        // The doc says "the item"; accept it bare or wrapped in `item`/`data`.
        let inner = v
            .get("item")
            .or_else(|| v.get("data"))
            .cloned()
            .unwrap_or(v);
        serde_json::from_value(inner).map_err(|e| Error::BadResponse(format!("item: {e}")))
    }

    /// `DELETE food-order-items/{item}` (`204`).
    pub async fn cancel_food_item(&self, item_id: u64) -> Result<()> {
        self.send_v1_empty(Method::DELETE, &format!("food-order-items/{item_id}"))
            .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{body_json, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    fn client(s: &MockServer) -> ApiClient {
        ApiClient::new(&s.uri()).unwrap().with_token("t")
    }

    #[tokio::test]
    async fn menu_and_mine_parse_the_documented_examples() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/catering"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "event_slug": "lan-party-2026", "registration_required": false,
              "limits": {"items_per_user": 10, "my_open_items": 1},
              "orders": [{
                "id": 4, "title": "Pizza", "status": "open", "is_open": true,
                "opens_at": "2026-11-06T17:00:00+00:00", "closes_at": "2026-11-06T19:00:00+00:00",
                "menu": [{"key": "pizza_margherita", "name": "Pizza Margherita", "price_cents": 850}],
                "my_items": [{"id": 31, "option_key": "pizza_margherita", "option_name": "Pizza Margherita", "note": null, "price_cents": 850, "paid": false}],
                "my_total_cents": 850
              }]
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/events/e/catering/mine"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
              "items": [{"id": 31, "option_key": "pizza_margherita", "option_name": "Pizza Margherita", "note": null,
                         "price_cents": 850, "paid": false, "order_id": 4, "order_title": "Pizza",
                         "order_status": "open", "order_is_open": true, "can_cancel": true}],
              "total_cents": 850, "paid_cents": 0
            })))
            .mount(&server)
            .await;
        let c = client(&server);
        let cat = c.catering("e").await.unwrap();
        assert_eq!(cat.limits.items_per_user, 10);
        assert_eq!(cat.orders[0].menu[0].price_cents, 850);
        assert_eq!(cat.orders[0].my_total_cents, 850);
        let mine = c.catering_mine("e").await.unwrap();
        assert!(mine.items[0].can_cancel);
        assert_eq!(mine.total_cents, 850);
    }

    #[tokio::test]
    async fn placing_sends_key_and_trimmed_note_and_cancel_accepts_204() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/food-orders/4/items"))
            .and(body_json(
                json!({"option_key": "pizza_margherita", "note": "ohne Oliven"}),
            ))
            .respond_with(ResponseTemplate::new(201).set_body_json(json!({
                "id": 32, "option_key": "pizza_margherita", "option_name": "Pizza Margherita",
                "note": "ohne Oliven", "price_cents": 850, "paid": false
            })))
            .expect(1)
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/food-orders/4/items"))
            .and(body_json(json!({"option_key": "cola"})))
            .respond_with(ResponseTemplate::new(201).set_body_json(
                json!({"item": {"id": 33, "option_key": "cola", "option_name": "Cola", "price_cents": 250}}),
            ))
            .expect(1)
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path("/api/voice-client/v1/food-order-items/32"))
            .respond_with(ResponseTemplate::new(204))
            .expect(1)
            .mount(&server)
            .await;
        let c = client(&server);
        let item = c
            .place_food_item(4, "pizza_margherita", Some("  ohne Oliven "))
            .await
            .unwrap();
        assert_eq!(item.id, 32);
        assert_eq!(
            c.place_food_item(4, "cola", Some("  ")).await.unwrap().id,
            33
        );
        c.cancel_food_item(32).await.unwrap();
    }

    #[tokio::test]
    async fn refused_business_rules_carry_code_and_german_message() {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/api/voice-client/v1/food-orders/4/items"))
            .respond_with(ResponseTemplate::new(422).set_body_json(
                json!({"code": "item_limit", "message": "Du hast das Limit erreicht."}),
            ))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .and(path("/api/voice-client/v1/food-order-items/9"))
            .respond_with(ResponseTemplate::new(422).set_body_json(
                json!({"code": "not_open", "message": "Die Bestellung ist geschlossen."}),
            ))
            .mount(&server)
            .await;
        let c = client(&server);
        let e = c.place_food_item(4, "x", None).await.unwrap_err();
        assert_eq!(e.code(), Some("item_limit"));
        let e = c.cancel_food_item(9).await.unwrap_err();
        assert_eq!(e.code(), Some("not_open"));
        assert!(!e.retryable());
    }
}
