use std::time::Duration;

use serde_json::json;
use voice_core::api::{FileTokenStore, StoredCredentials, TokenStore};
use voice_core::notify::SystemNotification;
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

use super::*;

#[derive(Default)]
struct Recorder(Mutex<Vec<SystemNotification>>);
impl Notifier for Recorder {
    fn notify(&self, n: &SystemNotification) -> Result<(), String> {
        self.0.lock().unwrap().push(n.clone());
        Ok(())
    }
}

struct Rig {
    server: MockServer,
    features: Arc<Features>,
    engine: Arc<Engine>,
    events: Arc<Mutex<Vec<(String, Value)>>>,
    notes: Arc<Recorder>,
    dir: tempfile::TempDir,
}

fn fast() -> FeatureOptions {
    FeatureOptions {
        realtime: RealtimeOptions {
            poll_interval: Duration::from_millis(100),
            ..Default::default()
        },
        notify: NotifyConfig::default(),
        retry_delays: Some(vec![Duration::ZERO, Duration::from_millis(10)]),
    }
}

async fn rig_at(base_url: Option<String>) -> Rig {
    let server = MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let store = FileTokenStore::new(dir.path().join("credentials.json"));
    store
        .save(&StoredCredentials {
            base_url: base_url.unwrap_or_else(|| server.uri()),
            access_token: "tok".into(),
            device_name: "test".into(),
        })
        .unwrap();
    let engine = Engine::new(
        store,
        dir.path().join("settings.json"),
        Arc::new(|_| {}),
        true,
    );
    let events: Arc<Mutex<Vec<(String, Value)>>> = Arc::default();
    let ev = events.clone();
    let notes: Arc<Recorder> = Arc::default();
    let features = Features::new(
        engine.clone(),
        Arc::new(move |n, v| ev.lock().unwrap().push((n.to_owned(), v))),
        notes.clone(),
        fast(),
    )
    .unwrap();
    Rig {
        server,
        features,
        engine,
        events,
        notes,
        dir,
    }
}

async fn rig() -> Rig {
    rig_at(None).await
}

impl Rig {
    fn events_named(&self, name: &str) -> Vec<Value> {
        self.events
            .lock()
            .unwrap()
            .iter()
            .filter(|(n, _)| n == name)
            .map(|(_, v)| v.clone())
            .collect()
    }
}

async fn until<F: Fn() -> bool>(f: F) {
    for _ in 0..300 {
        if f() {
            return;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    panic!("condition not reached in time");
}

#[tokio::test]
async fn every_failure_has_one_kind_a_german_text_and_a_retry_flag() {
    let r = rig().await;
    let s = &r.server;
    for (route, status, body) in [
        (
            "/api/voice-client/v1/events",
            401,
            json!({"message": "Unauthenticated."}),
        ),
        (
            "/api/voice-client/v1/servers",
            404,
            json!({"message": "Not Found"}),
        ),
        (
            "/api/voice-client/v1/events/e/polls",
            500,
            json!({"message": "boom"}),
        ),
        (
            "/api/voice-client/v1/events/e/catering",
            503,
            json!({"message": "später"}),
        ),
    ] {
        Mock::given(method("GET"))
            .and(path(route))
            .respond_with(ResponseTemplate::new(status).set_body_json(body))
            .mount(s)
            .await;
    }
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/polls/3/vote"))
        .respond_with(ResponseTemplate::new(422).set_body_json(json!({
            "code": "already_voted", "message": "Du hast schon abgestimmt.",
            "errors": {"option_id": ["ungültig"]}
        })))
        .mount(s)
        .await;
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/matches/9/confirm"))
        .respond_with(ResponseTemplate::new(409).set_body_json(
            json!({"message": "Das Match hat sich geändert.", "error": "tournaments.errors.stale_match"}),
        ))
        .mount(s)
        .await;
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/rooms/3/messages"))
        .respond_with(
            ResponseTemplate::new(429)
                .insert_header("Retry-After", "9")
                .set_body_json(json!({"message": "Too Many Attempts."})),
        )
        .mount(s)
        .await;
    Mock::given(method("DELETE"))
        .and(path("/api/voice-client/v1/messages/1"))
        .respond_with(ResponseTemplate::new(403).set_body_json(json!({"message": "x"})))
        .mount(s)
        .await;
    let f = &r.features;

    let e = f.permanent_servers().await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("not_found", false));

    let e = f.polls("e").await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("server", true));

    let e = f.catering("e").await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("unavailable", true));

    let e = f.vote(3, 12).await.unwrap_err();
    assert_eq!(e.kind, "validation");
    assert_eq!(e.code.as_deref(), Some("already_voted"));
    assert_eq!(
        e.message, "Du hast schon abgestimmt.",
        "the server's German text"
    );
    assert_eq!(e.fields["option_id"][0], "ungültig");
    assert!(!e.retryable);

    let e = f.match_confirm(9, 0).await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("conflict", false));
    assert_eq!(e.code.as_deref(), Some("tournaments.errors.stale_match"));

    let e = f.chat_post(3, "hi").await.unwrap_err();
    assert_eq!(
        (e.kind, e.retryable, e.retry_after_secs),
        ("rate_limited", true, Some(9))
    );

    let e = f.chat_delete(1).await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("forbidden", false));

    // 401: re-login, and every window is told once.
    let e = f.events().await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("auth", false));
    assert_eq!(r.events_named(EVT_AUTH).len(), 1);

    // The shape the UI gets.
    let v = serde_json::to_value(&e).unwrap();
    assert_eq!(v["kind"], "auth");
    assert!(v["message"].as_str().unwrap().contains("Verbinde"));
    assert_eq!(v["retryable"], false);
}

#[tokio::test]
async fn an_unreachable_server_is_a_retryable_network_error() {
    let r = rig_at(Some("http://127.0.0.1:1".into())).await;
    let e = r.features.events().await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("network", true));
}

#[tokio::test]
async fn without_credentials_everything_is_an_auth_error_without_a_request() {
    let r = rig().await;
    r.engine.logout().await.unwrap();
    let e = r.features.events().await.unwrap_err();
    assert_eq!(e.kind, "auth");
}

#[tokio::test]
async fn config_exposes_modules_and_realtime_as_a_flag_only() {
    let r = rig().await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/config"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "api_version": 1, "livekit_url": "wss://x", "voice_ready": true, "audio": {},
            "modules": {"game_servers": true, "catering": false, "voting": true},
            "realtime": {"driver": "reverb", "key": "k", "host": "h", "port": 443, "scheme": "https",
                         "auth_endpoint": "/api/voice-client/v1/broadcasting/auth"}
        })))
        .mount(&r.server)
        .await;
    let c = r.features.config().await.unwrap();
    assert!(c.realtime && c.voice_ready);
    assert!(!c.modules["catering"]);
    assert!(
        !serde_json::to_string(&c).unwrap().contains("\"k\""),
        "no connection details"
    );
}

fn vote_card(id: u64, closes_in_secs: i64) -> Value {
    let at = (OffsetDateTime::now_utc() + time::Duration::seconds(closes_in_secs))
        .format(&Rfc3339)
        .unwrap();
    json!({"kind": "vote", "id": format!("vote:{id}"), "state": "open", "title": "Abstimmung",
           "body": null, "at": at,
           "data": {"pollId": id, "question": "Welche Map?", "closesAt": at, "options": []}})
}

#[tokio::test]
async fn a_vote_that_closes_soon_becomes_one_notification_and_only_once() {
    let r = rig().await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/events/e/now"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "cards": [vote_card(3, 240), vote_card(4, 7200)], "more": []
        })))
        .mount(&r.server)
        .await;
    let feed = r.features.now("e").await.unwrap();
    assert_eq!(feed.cards.len(), 2);
    r.features.now("e").await.unwrap();
    let shown = r.notes.0.lock().unwrap().clone();
    assert_eq!(shown.len(), 1, "one for vote 3, none for vote 4, no repeat");
    assert_eq!(shown[0].target.screen, "jetzt");
    assert!(shown[0].body.as_deref().unwrap().contains("Welche Map?"));
    // No click callback on the desktop: the UI claims the target when the window comes forward.
    let t = r.features.take_notification_target().expect("a target");
    assert_eq!(
        (t.screen.as_str(), t.card.as_deref()),
        ("jetzt", Some("vote"))
    );
    assert!(
        r.features.take_notification_target().is_none(),
        "claimed once"
    );
}

#[test]
fn vote_closing_logic_is_exact() {
    let now = OffsetDateTime::now_utc();
    let card = |v: Value| serde_json::from_value::<NowCard>(v).unwrap();
    assert!(vote_closing_soon(&card(vote_card(1, 300)), now, 600).is_some());
    assert!(vote_closing_soon(&card(vote_card(1, 700)), now, 600).is_none());
    assert!(
        vote_closing_soon(&card(vote_card(1, -5)), now, 600).is_none(),
        "already closed"
    );
    let mut other = vote_card(1, 300);
    other["kind"] = json!("match");
    assert!(vote_closing_soon(&card(other), now, 600).is_none());
}

#[test]
fn mentions_need_the_at_sign_and_a_word_boundary() {
    assert!(is_mention("hey @Tester kommst du?", "Tester"));
    assert!(is_mention("@tester", "Tester"));
    assert!(is_mention("gl @Tester!", "Tester"));
    assert!(!is_mention("Tester ohne at", "Tester"));
    assert!(
        !is_mention("@Testers", "Tester"),
        "a longer name is someone else"
    );
    assert!(!is_mention("@x", ""));
    assert!(is_mention("@Max Mustermann hi", "Max Mustermann"));
}

#[tokio::test]
async fn chat_events_reach_the_ui_and_a_mention_raises_a_notification() {
    let r = rig().await;
    let s = &r.server;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/me"))
        .respond_with(ResponseTemplate::new(200).set_body_json(
            json!({"id": 1, "name": "Tester", "role": "participant", "identity": "u1"}),
        ))
        .mount(s)
        .await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/config"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "api_version": 1, "livekit_url": null, "voice_ready": false, "audio": {},
            "modules": {}, "realtime": null
        })))
        .mount(s)
        .await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/rooms"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"rooms": []})))
        .mount(s)
        .await;
    let list: Arc<Mutex<Vec<Value>>> = Arc::default();
    let l2 = list.clone();
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/rooms/3/messages"))
        .respond_with(move |_: &wiremock::Request| {
            ResponseTemplate::new(200)
                .set_body_json(json!({"messages": l2.lock().unwrap().clone(), "next_before": null}))
        })
        .mount(s)
        .await;
    let msg = |id: u64, body: &str, author: u64| {
        json!({"id": id, "channel_id": 3, "body": body, "created_at": "2026-11-02T12:00:00+00:00",
               "author": {"id": author, "name": "Ada", "avatar_url": null}})
    };
    list.lock().unwrap().push(msg(1, "alt", 7));

    r.features.chat_subscribe(vec![3]).await.unwrap();
    until(|| {
        r.events_named(EVT_CHAT)
            .iter()
            .any(|e| e["type"] == "status" && e["state"] == "polling")
    })
    .await;
    tokio::time::sleep(Duration::from_millis(250)).await; // the baseline pass
    list.lock().unwrap().push(msg(2, "hallo zusammen", 7));
    list.lock().unwrap().push(msg(3, "@Tester gl hf", 7));
    list.lock()
        .unwrap()
        .push(msg(4, "@Tester bin ich selbst", 1));
    until(|| {
        r.events_named(EVT_CHAT)
            .iter()
            .any(|e| e["message"]["id"] == 3)
    })
    .await;

    let posted: Vec<u64> = r
        .events_named(EVT_CHAT)
        .iter()
        .filter(|e| e["type"] == "message_posted")
        .map(|e| e["message"]["id"].as_u64().unwrap())
        .collect();
    assert!(posted.contains(&2) && posted.contains(&3));
    assert!(!posted.contains(&1), "history is the UI's own fetch");
    until(|| !r.notes.0.lock().unwrap().is_empty()).await;
    let n = r.notes.0.lock().unwrap().clone();
    assert_eq!(n.len(), 1, "only the mention by someone else: {n:?}");
    assert_eq!(n[0].title, "Ada hat dich erwähnt");
    assert_eq!(n[0].target.screen, "sprache");
    r.features.chat_unsubscribe();
}

#[tokio::test]
async fn the_quiet_toggle_and_focus_reach_the_engine_and_the_ui() {
    let r = rig().await;
    assert!(!r.features.quiet());
    r.features.set_quiet(true);
    assert!(r.features.quiet());
    assert_eq!(r.events_named(EVT_QUIET), vec![json!({"quiet": true})]);
    // An engine created later starts quiet, as the person asked.
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/events/e/now"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "cards": [vote_card(3, 240)], "more": []
        })))
        .mount(&r.server)
        .await;
    r.features.now("e").await.unwrap();
    assert!(
        r.notes.0.lock().unwrap().is_empty(),
        "quiet: no system notification"
    );
}

#[tokio::test]
async fn downloads_go_to_the_chosen_folder_and_report_progress_events() {
    let r = rig().await;
    let target = r.dir.path().join("my downloads");
    r.engine
        .update_settings(|s| s.download_dir = Some(target.display().to_string()));
    assert_eq!(r.features.download_dir(), target);
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/files/21/download"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "url": format!("{}/dl/maps", r.server.uri()), "name": "maps.zip",
            "size_bytes": 1000, "expires_at": "2026-11-06T18:05:00+00:00"
        })))
        .mount(&r.server)
        .await;
    Mock::given(method("GET"))
        .and(path("/dl/maps"))
        .respond_with(ResponseTemplate::new(200).set_body_bytes(vec![7u8; 1000]))
        .mount(&r.server)
        .await;
    let started = r.features.download_file(21, "maps.zip").await.unwrap();
    until(|| {
        r.events_named(EVT_TRANSFER)
            .iter()
            .any(|e| e["id"] == started.transfer_id && e["status"] == "done")
    })
    .await;
    assert_eq!(
        std::fs::read(target.join("maps.zip")).unwrap(),
        vec![7u8; 1000]
    );
    let done = r.features.transfers();
    assert_eq!(
        done[0].path.as_deref(),
        Some(target.join("maps.zip").to_str().unwrap())
    );
}

#[tokio::test]
async fn uploads_refuse_the_apps_own_files_per_entry_and_report_server_refusals() {
    let r = rig().await;
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/events/e/files/uploads/ticket"))
        .respond_with(
            ResponseTemplate::new(422).set_body_json(
                json!({"code": "quota_exceeded", "message": "Dein Speicher ist voll."}),
            ),
        )
        .mount(&r.server)
        .await;
    let elsewhere = tempfile::tempdir().unwrap(); // outside the app's config directory
    let ok = elsewhere.path().join("photo.jpg");
    std::fs::write(&ok, b"jpegdata").unwrap();
    let secret = r.dir.path().join("credentials.json"); // the app's own credential file
    let res = r
        .features
        .upload_files(
            "e",
            vec![
                secret.display().to_string(),
                ok.display().to_string(),
                r.dir.path().join("missing.bin").display().to_string(),
            ],
        )
        .await
        .unwrap();
    assert_eq!(res[0].error.as_ref().unwrap().kind, "usage");
    assert!(res[0].transfer_id.is_none());
    let id = res[1].transfer_id.unwrap();
    assert!(res[2].error.is_some());
    until(|| {
        r.events_named(EVT_TRANSFER)
            .iter()
            .any(|e| e["id"] == id && e["status"] == "error")
    })
    .await;
    let v = r
        .features
        .transfers()
        .into_iter()
        .find(|t| t.id == id)
        .unwrap();
    let err = v.error.unwrap();
    assert_eq!(err.code, "quota_exceeded");
    assert!(!err.retryable);
    assert!(
        err.message.contains("Speicher"),
        "German text: {}",
        err.message
    );
}

#[tokio::test]
async fn saving_settings_from_the_ui_cannot_overwrite_the_notification_bookkeeping() {
    let r = rig().await;
    let mut seen = SeenState {
        baselined: true,
        ..Default::default()
    };
    seen.ids.push_back("abc".into());
    let s2 = seen.clone();
    r.engine.update_settings(|s| s.notify_seen = s2);
    let mut from_ui = r.engine.settings();
    from_ui.notify_seen = SeenState::default();
    from_ui.notify_categories.insert("friends".into(), true);
    let saved = r.engine.save_settings(from_ui).unwrap();
    assert_eq!(saved.notify_seen, seen);
    assert_eq!(r.engine.settings().notify_seen, seen);
    let o = category_overrides(&saved);
    assert_eq!(o.get(&Category::Friends), Some(&true));
}

#[test]
fn unknown_category_keys_in_the_settings_are_ignored() {
    let mut s = Settings::default();
    s.notify_categories.insert("bogus".into(), true);
    s.notify_categories.insert("match".into(), false);
    let o = category_overrides(&s);
    assert_eq!(o.len(), 1);
    assert_eq!(o.get(&Category::Match), Some(&false));
}

#[tokio::test]
async fn account_calls_use_the_shared_error_object_and_the_expiry_event() {
    let r = rig().await;
    let s = &r.server;
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/friends/requests"))
        .respond_with(
            ResponseTemplate::new(422).set_body_json(
                json!({"code": "request_pending", "message": "Anfrage läuft schon."}),
            ),
        )
        .mount(s)
        .await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/friends"))
        .respond_with(ResponseTemplate::new(200).set_body_json(
            json!({"friends": [{"id": 9, "name": "Bob", "avatar_url": null, "extra": 1}]}),
        ))
        .mount(s)
        .await;
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/friends/requests/4/accept"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"status": "accepted"})))
        .mount(s)
        .await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/profile"))
        .respond_with(ResponseTemplate::new(401).set_body_json(json!({"message": "x"})))
        .mount(s)
        .await;

    let e = r.features.send_friend_request(9).await.unwrap_err();
    assert_eq!((e.kind, e.retryable), ("validation", false));
    assert_eq!(e.code.as_deref(), Some("request_pending"));
    assert_eq!(e.message, "Anfrage läuft schon.");

    let f = r.features.friends().await.unwrap();
    assert_eq!(f[0].name, "Bob");
    let a = r.features.accept_friend_request(4).await.unwrap();
    assert_eq!(a, json!({"status": "accepted"}));

    let e = r.features.profile().await.unwrap_err();
    assert_eq!(e.kind, "auth");
    assert_eq!(r.events_named(EVT_AUTH).len(), 1);
}

#[tokio::test]
async fn only_the_servers_own_connect_link_may_be_opened() {
    let r = rig_at(Some("https://lan.example.test".into())).await;
    let ok = "https://lan.example.test/settings/connections/link/tok_1";
    assert_eq!(r.features.checked_link_url(ok).unwrap(), ok);
    for bad in [
        "https://evil.example/settings/connections/link/tok_1",
        "https://lan.example.test/login",
        "http://lan.example.test/settings/connections/link/tok_1",
        "steam://connect/1.2.3.4:27015",
    ] {
        let e = r.features.checked_link_url(bad).unwrap_err();
        assert_eq!(e.kind, "usage", "{bad}");
    }
}
