use std::sync::{Arc, Mutex};

use serde_json::{json, Value};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

use super::*;

#[derive(Default)]
struct Recorder(Mutex<Vec<SystemNotification>>);
impl Notifier for Recorder {
    fn notify(&self, n: &SystemNotification) -> std::result::Result<(), String> {
        self.0.lock().unwrap().push(n.clone());
        Ok(())
    }
}
impl Recorder {
    fn titles(&self) -> Vec<String> {
        self.0
            .lock()
            .unwrap()
            .iter()
            .map(|n| n.title.clone())
            .collect()
    }
}

#[derive(Default)]
struct MemStore(Mutex<SeenState>);
impl SeenStore for MemStore {
    fn load(&self) -> SeenState {
        self.0.lock().unwrap().clone()
    }
    fn save(&self, s: &SeenState) {
        *self.0.lock().unwrap() = s.clone();
    }
}

struct Rig {
    server: MockServer,
    list: Arc<Mutex<Vec<Value>>>,
    list_calls: Arc<Mutex<u32>>,
    rec: Arc<Recorder>,
    store: Arc<MemStore>,
    events: Arc<Mutex<Vec<NotifyEvent>>>,
}

fn n(id: &str, cat: &str, at: &str) -> Value {
    json!({"id": id, "category": cat, "title": format!("title {id}"), "body": "b",
           "read": false, "read_at": null, "created_at": at})
}

impl Rig {
    async fn new() -> Rig {
        let server = MockServer::start().await;
        let list: Arc<Mutex<Vec<Value>>> = Arc::default();
        let list_calls: Arc<Mutex<u32>> = Arc::default();
        let (l1, l2, c2) = (list.clone(), list.clone(), list_calls.clone());
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/notifications/unread-count"))
            .respond_with(move |_: &Request| {
                ResponseTemplate::new(200)
                    .set_body_json(json!({"unread": l1.lock().unwrap().len()}))
            })
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/notifications"))
            .respond_with(move |_: &Request| {
                *c2.lock().unwrap() += 1;
                let items = l2.lock().unwrap().clone();
                ResponseTemplate::new(200).set_body_json(json!({
                    "notifications": items, "page": 1, "per_page": 50, "total": items.len(), "has_more": false
                }))
            })
            .mount(&server)
            .await;
        Rig {
            server,
            list,
            list_calls,
            rec: Arc::default(),
            store: Arc::default(),
            events: Arc::default(),
        }
    }

    fn engine(&self) -> Arc<NotificationEngine> {
        let ev = self.events.clone();
        NotificationEngine::new(
            ApiClient::new(&self.server.uri()).unwrap().with_token("t"),
            self.rec.clone(),
            self.store.clone(),
            Arc::new(move |e| ev.lock().unwrap().push(e)),
            NotifyConfig::default(),
            BTreeMap::new(),
        )
    }

    fn push(&self, v: Value) {
        self.list.lock().unwrap().push(v);
    }
}

#[tokio::test]
async fn the_first_look_only_sets_the_baseline() {
    let rig = Rig::new().await;
    rig.push(n("old1", "match", "2026-11-02T10:00:00+00:00"));
    rig.push(n("old2", "catering", "2026-11-02T10:01:00+00:00"));
    let e = rig.engine();
    e.tick().await.unwrap();
    assert!(
        rig.rec.titles().is_empty(),
        "old unread items are not announced"
    );
    let s = rig.store.load();
    assert!(s.baselined && s.ids.len() == 2);
    // The unread badge was still reported.
    assert!(rig
        .events
        .lock()
        .unwrap()
        .contains(&NotifyEvent::Unread { count: 2 }));
}

#[tokio::test]
async fn a_new_matching_item_becomes_one_system_notification_with_its_target() {
    let rig = Rig::new().await;
    let e = rig.engine();
    e.tick().await.unwrap(); // baseline (empty)
    rig.push(n("a", "match", "2026-11-02T12:00:00+00:00"));
    e.tick().await.unwrap();
    assert_eq!(rig.rec.titles(), vec!["title a"]);
    let shown = rig.rec.0.lock().unwrap()[0].clone();
    assert_eq!(shown.target.screen, "spielen");
    assert!(rig
        .events
        .lock()
        .unwrap()
        .iter()
        .any(|e| matches!(e, NotifyEvent::Delivered { .. })));
    // Polling again with the same unread item does nothing.
    e.tick().await.unwrap();
    assert_eq!(rig.rec.titles().len(), 1);
}

#[tokio::test]
async fn categories_that_are_off_by_default_stay_quiet_but_are_marked_seen() {
    let rig = Rig::new().await;
    let e = rig.engine();
    e.tick().await.unwrap();
    rig.push(n("f", "friends", "2026-11-02T12:00:00+00:00"));
    e.tick().await.unwrap();
    assert!(rig.rec.titles().is_empty());
    assert!(rig.store.load().ids.contains(&"f".to_owned()));
    // Switching the category on later does not replay it.
    let mut o = BTreeMap::new();
    o.insert(Category::Friends, true);
    e.set_overrides(o);
    e.tick().await.unwrap();
    assert!(rig.rec.titles().is_empty());
}

#[tokio::test]
async fn a_restart_does_not_announce_what_was_already_seen() {
    let rig = Rig::new().await;
    let e1 = rig.engine();
    e1.tick().await.unwrap();
    rig.push(n("a", "match", "2026-11-02T12:00:00+00:00"));
    e1.tick().await.unwrap();
    assert_eq!(rig.rec.titles().len(), 1);
    drop(e1);
    // "App restart": a new engine over the same persisted state, the item is still unread.
    let e2 = rig.engine();
    e2.tick().await.unwrap();
    assert_eq!(
        rig.rec.titles().len(),
        1,
        "no second notification for the same id"
    );
    // A genuinely new one still arrives.
    rig.push(n("b", "match", "2026-11-02T13:00:00+00:00"));
    e2.tick().await.unwrap();
    assert_eq!(rig.rec.titles().len(), 2);
}

#[tokio::test]
async fn a_flood_gives_one_notification_and_a_count() {
    let rig = Rig::new().await;
    let e = rig.engine();
    e.tick().await.unwrap();
    for i in 0..4 {
        rig.push(n(
            &format!("m{i}"),
            "match",
            &format!("2026-11-02T12:0{i}:00+00:00"),
        ));
    }
    e.tick().await.unwrap();
    assert_eq!(
        rig.rec.titles(),
        vec!["title m0"],
        "one per category per minute"
    );
}

#[tokio::test]
async fn talking_and_the_quiet_toggle_suppress_system_notifications() {
    let rig = Rig::new().await;
    let e = rig.engine();
    e.tick().await.unwrap();
    e.set_talking(true);
    rig.push(n("a", "match", "2026-11-02T12:00:00+00:00"));
    e.tick().await.unwrap();
    assert!(rig.rec.titles().is_empty());
    e.set_talking(false);
    e.set_quiet(true);
    assert!(e.quiet());
    rig.push(n("b", "match", "2026-11-02T12:01:00+00:00"));
    e.tick().await.unwrap();
    assert!(rig.rec.titles().is_empty());
    e.set_quiet(false);
    rig.push(n("c", "match", "2026-11-02T12:02:00+00:00"));
    e.tick().await.unwrap();
    assert_eq!(rig.rec.titles(), vec!["title c"]);
}

#[tokio::test]
async fn client_side_offers_go_through_the_policy_and_dedupe_by_key() {
    let rig = Rig::new().await;
    let e = rig.engine();
    let mention = |id: &str| Incoming {
        id: id.into(),
        category: Category::Direct,
        title: "Ada hat dich erwähnt".into(),
        body: Some("@du gl".into()),
    };
    e.offer(mention("chat:1"));
    e.offer(mention("chat:1"));
    assert_eq!(rig.rec.titles().len(), 1);
    // Direct is on by default; a second one within the minute is grouped.
    e.offer(mention("chat:2"));
    assert_eq!(rig.rec.titles().len(), 1);
    let shown = rig.rec.0.lock().unwrap()[0].clone();
    assert_eq!(shown.target.screen, "sprache");
}

#[tokio::test]
async fn the_list_is_only_fetched_when_the_count_changed_or_on_the_refresh_beat() {
    let rig = Rig::new().await;
    rig.push(n("a", "friends", "2026-11-02T12:00:00+00:00"));
    let e = rig.engine();
    e.tick().await.unwrap(); // baseline fetch (tick 0)
    let after_first = *rig.list_calls.lock().unwrap();
    for _ in 0..5 {
        e.tick().await.unwrap(); // unchanged count, not a refresh tick
    }
    assert_eq!(*rig.list_calls.lock().unwrap(), after_first);
    rig.push(n("b", "friends", "2026-11-02T12:01:00+00:00"));
    e.tick().await.unwrap();
    assert_eq!(*rig.list_calls.lock().unwrap(), after_first + 1);
}

#[tokio::test]
async fn the_loop_stops_and_reports_when_the_token_is_rejected() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(ResponseTemplate::new(401).set_body_json(json!({"message": "x"})))
        .mount(&server)
        .await;
    let events: Arc<Mutex<Vec<NotifyEvent>>> = Arc::default();
    let ev = events.clone();
    let e = NotificationEngine::new(
        ApiClient::new(&server.uri()).unwrap().with_token("bad"),
        Arc::new(Recorder::default()),
        Arc::new(MemStore::default()),
        Arc::new(move |x| ev.lock().unwrap().push(x)),
        NotifyConfig::default(),
        BTreeMap::new(),
    );
    let h = e.spawn();
    tokio::time::timeout(std::time::Duration::from_secs(5), h)
        .await
        .expect("loop ended")
        .unwrap();
    assert_eq!(*events.lock().unwrap(), vec![NotifyEvent::AuthExpired]);
}

#[test]
fn polling_is_calm_faster_when_focused_and_backs_off_on_failures() {
    let c = NotifyConfig::default();
    assert_eq!(next_delay(&c, true, 0).as_secs(), 15);
    assert_eq!(next_delay(&c, false, 0).as_secs(), 45);
    assert_eq!(next_delay(&c, true, 1).as_secs(), 30);
    assert_eq!(next_delay(&c, true, 2).as_secs(), 60);
    assert_eq!(next_delay(&c, false, 3).as_secs(), 300, "capped");
    assert_eq!(next_delay(&c, false, 40).as_secs(), 300);
}
