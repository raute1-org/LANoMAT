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
        legacy_installs_dir: None,
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
async fn config_reports_the_server_version_only_when_the_server_says_it() {
    let r = rig().await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/config"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "api_version": 1, "voice_ready": true, "modules": {},
            "client": {"server_version": "m12-470-gabc1234", "download_path": "/talk/client"}
        })))
        .mount(&r.server)
        .await;
    let c = r.features.config().await.unwrap();
    assert_eq!(c.server_version.as_deref(), Some("m12-470-gabc1234"));
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

// ---- games (library) ----

fn game_summary(slug: &str, extra: Value) -> Value {
    let mut v = json!({
        "slug": slug, "title": format!("Game {slug}"), "summary": "Shooter", "version": "1.0",
        "status": "published", "platforms": ["windows", "linux"], "genre": "fps",
        "players": "2-16", "players_max": 16, "size_bytes": 4096, "installable": true,
        "launcher_supported": true, "in_lineup": true, "play_url": null,
        "updated_at": "2026-11-22T11:50:00+00:00"
    });
    v.as_object_mut()
        .unwrap()
        .extend(extra.as_object().unwrap().clone());
    v
}

fn game_plan(slug: &str, on_lan: bool) -> Value {
    json!({
        "plan_version": 1,
        "recipe": {"slug": slug, "title": "T", "launch_targets": []},
        "os": "linux", "target_dir": "<dir>", "steps": [], "avatars": [],
        "files": [{
            "requirement": "base", "label": "Portable archive", "status": if on_lan {"matched"} else {"missing"},
            "required": true, "source": if on_lan { json!("lan") } else { Value::Null },
            "name": "game.zip", "size_bytes": 4096, "sha256": "a".repeat(64),
            "download_url": if on_lan { json!("https://files.example/p?sig=SECRET") } else { Value::Null },
            "download_expires_at": "2026-11-22T12:00:00+00:00"
        }],
        "missing": if on_lan { json!([]) } else { json!(["Portable archive"]) },
        "generated_at": "2026-11-22T11:50:00+00:00"
    })
}

async fn mount_recipes(r: &Rig, recipes: Vec<Value>) {
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/recipes"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"recipes": recipes})))
        .mount(&r.server)
        .await;
}

async fn mount_plan(r: &Rig, slug: &str, status: u16, body: Value) {
    Mock::given(method("GET"))
        .and(path(format!("/api/voice-client/v1/recipes/{slug}/plan")))
        .respond_with(ResponseTemplate::new(status).set_body_json(body))
        .mount(&r.server)
        .await;
}

async fn plan_requests(r: &Rig) -> Vec<String> {
    r.server
        .received_requests()
        .await
        .unwrap()
        .iter()
        .filter(|q| q.url.path().ends_with("/plan"))
        .map(|q| q.url.query().unwrap_or_default().to_owned())
        .collect()
}

#[tokio::test]
async fn games_state_follows_the_feature_flag_and_the_pairing() {
    for (features, expected) in [
        (None, (false, Some("server_too_old"))),
        (Some(json!({})), (false, Some("server_too_old"))),
        (
            Some(json!({"launcher": false})),
            (false, Some("module_off")),
        ),
        (Some(json!({"launcher": true})), (true, None)),
    ] {
        let r = rig().await;
        let mut body = json!({"api_version": 1, "voice_ready": false, "modules": {}});
        if let Some(f) = features {
            body["features"] = f;
        }
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(ResponseTemplate::new(200).set_body_json(body))
            .mount(&r.server)
            .await;
        let s = r.features.games_state().await.unwrap();
        assert_eq!((s.enabled, s.reason), expected);
    }

    // Not paired: no request at all.
    let r = rig().await;
    std::fs::remove_file(r.dir.path().join("credentials.json")).unwrap();
    let s = r.features.games_state().await.unwrap();
    assert_eq!((s.enabled, s.reason), (false, Some("not_paired")));
    assert!(r.server.received_requests().await.unwrap().is_empty());
    let e = r.features.games_list().await.unwrap_err();
    assert_eq!(e.code.as_deref(), Some("not_paired"));
}

#[tokio::test]
async fn the_library_shows_availability_from_dry_run_plans_and_the_local_state() {
    let r = rig().await;
    mount_recipes(
        &r,
        vec![
            game_summary("on-lan", json!({})),
            game_summary("no-files", json!({})),
            game_summary("not-installable", json!({"installable": false})),
            game_summary("browser", json!({"play_url": "https://play.example/"})),
            // An older server: the new fields are absent.
            json!({"slug": "legacy", "title": "Legacy"}),
        ],
    )
    .await;
    mount_plan(&r, "on-lan", 200, game_plan("on-lan", true)).await;
    mount_plan(&r, "no-files", 200, game_plan("no-files", false)).await;
    mount_plan(&r, "legacy", 200, game_plan("legacy", true)).await;
    let ready = r.dir.path().join("games").join("on-lan");
    std::fs::create_dir_all(&ready).unwrap();
    r.features
        .installs
        .begin_install("on-lan", ready.to_str().unwrap(), None, None, None)
        .unwrap();
    r.features
        .installs
        .set_status("on-lan", launcher_core::state::InstallStatus::Ready)
        .unwrap();

    let list = r.features.games_list().await.unwrap();
    let by = |slug: &str| list.recipes.iter().find(|g| g.slug == slug).unwrap();

    assert_eq!(
        list.recipes
            .iter()
            .map(|g| g.slug.as_str())
            .collect::<Vec<_>>(),
        ["on-lan", "no-files", "not-installable", "browser", "legacy"],
        "the server's order"
    );
    use voice_core::api::Availability::*;
    assert_eq!(by("on-lan").availability, OnLan);
    assert_eq!(by("no-files").availability, Missing);
    assert_eq!(by("not-installable").availability, Unknown);
    assert_eq!(by("browser").availability, Unknown);
    assert_eq!(by("legacy").availability, OnLan);
    assert_eq!(by("legacy").installable, None, "unknown is not no");
    assert_eq!(by("legacy").launcher_supported, None);
    assert!(by("legacy").in_lineup);
    assert_eq!(by("on-lan").local.status, "ready");
    assert_eq!(by("on-lan").local.path.as_deref(), ready.to_str());
    assert_eq!(by("no-files").local.status, "not_installed");
    assert!(!list.fetched_at.is_empty());

    // Browsing never writes an audit entry: every plan request is a dry run.
    let plans = plan_requests(&r).await;
    assert_eq!(
        plans.len(),
        3,
        "no plan for a recipe that cannot be installed"
    );
    assert!(
        plans
            .iter()
            .all(|q| q.contains("dry_run=1") && q.contains("os=")),
        "{plans:?}"
    );

    // The answer is a plain JSON object with snake_case keys and no signed link in it.
    let json = serde_json::to_value(&list).unwrap();
    assert_eq!(json["recipes"][0]["availability"], "on_lan");
    assert_eq!(json["recipes"][0]["local"]["status"], "ready");
    assert!(!json.to_string().contains("SECRET"));

    // Asking again within the cache window asks the server nothing more.
    r.features.games_list().await.unwrap();
    assert_eq!(plan_requests(&r).await.len(), 3);
}

#[tokio::test]
async fn plan_lookups_stay_within_the_budget_and_never_fail_the_list() {
    let r = rig().await;
    let slugs: Vec<String> = (0..30).map(|i| format!("game-{i}")).collect();
    mount_recipes(
        &r,
        slugs.iter().map(|s| game_summary(s, json!({}))).collect(),
    )
    .await;
    Mock::given(method("GET"))
        .and(wiremock::matchers::path_regex(
            r"^/api/voice-client/v1/recipes/game-\d+/plan$",
        ))
        .respond_with(ResponseTemplate::new(200).set_body_json(game_plan("x", true)))
        .mount(&r.server)
        .await;

    let list = r.features.games_list().await.unwrap();
    assert_eq!(list.recipes.len(), 30);
    assert_eq!(
        plan_requests(&r).await.len(),
        24,
        "below the server's 30 a minute"
    );
    let known = list
        .recipes
        .iter()
        .filter(|g| g.availability == voice_core::api::Availability::OnLan)
        .count();
    assert_eq!(known, 24);

    // A throttled server leaves the rest unknown, the list still works.
    let r = rig().await;
    mount_recipes(
        &r,
        vec![game_summary("a", json!({})), game_summary("b", json!({}))],
    )
    .await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/recipes/a/plan"))
        .respond_with(
            ResponseTemplate::new(429)
                .insert_header("Retry-After", "5")
                .set_body_json(json!({})),
        )
        .mount(&r.server)
        .await;
    mount_plan(&r, "b", 500, json!({"message": "boom"})).await;
    let list = r.features.games_list().await.unwrap();
    assert!(list
        .recipes
        .iter()
        .all(|g| g.availability == voice_core::api::Availability::Unknown));
}

#[tokio::test]
async fn library_errors_carry_the_stable_codes() {
    // The module is off: the routes are 404.
    let r = rig().await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/recipes"))
        .respond_with(ResponseTemplate::new(404).set_body_json(json!({"message": "Not Found"})))
        .mount(&r.server)
        .await;
    let e = r.features.games_list().await.unwrap_err();
    assert_eq!(e.code.as_deref(), Some("module_off"));

    // Server trouble.
    let r = rig().await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/recipes"))
        .respond_with(ResponseTemplate::new(500).set_body_json(json!({"message": "boom"})))
        .mount(&r.server)
        .await;
    let e = r.features.games_list().await.unwrap_err();
    assert_eq!(
        (e.code.as_deref(), e.retryable),
        (Some("server_error"), true)
    );

    // Unreachable.
    let r = rig_at(Some("http://127.0.0.1:1".into())).await;
    let e = r.features.games_list().await.unwrap_err();
    assert_eq!(
        (e.code.as_deref(), e.kind, e.retryable),
        (Some("offline"), "network", true)
    );
    let e = r.features.game_detail("any").await.unwrap_err();
    assert_eq!(e.code.as_deref(), Some("offline"));

    // A rejected token: not paired any more, and every window is told.
    let r = rig().await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/recipes"))
        .respond_with(
            ResponseTemplate::new(401).set_body_json(json!({"message": "Unauthenticated."})),
        )
        .mount(&r.server)
        .await;
    let e = r.features.games_list().await.unwrap_err();
    assert_eq!((e.code.as_deref(), e.kind), (Some("not_paired"), "auth"));
    assert_eq!(r.events_named(EVT_AUTH).len(), 1);
}

#[tokio::test]
async fn the_detail_has_the_long_fields_and_a_plan_summary() {
    let r = rig().await;
    let mut recipe = game_summary("on-lan", json!({}));
    recipe["description"] = json!("Long text");
    recipe["aliases"] = json!(["ol"]);
    recipe["homepage"] = json!("https://game.example/");
    recipe["license"] = json!("freeware");
    recipe["portable"] = json!(true);
    recipe["supports_multiplayer"] = json!(true);
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/recipes/on-lan"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"recipe": recipe})))
        .mount(&r.server)
        .await;
    let mut plan = game_plan("on-lan", true);
    plan["files"].as_array_mut().unwrap().push(json!({
        "requirement": "patch", "name": "patch.zip", "status": "external", "required": false,
        "source": "external", "download_url": "https://cdn.example/patch.zip"
    }));
    mount_plan(&r, "on-lan", 200, plan).await;

    let d = r.features.game_detail("on-lan").await.unwrap();
    assert_eq!(d.recipe.game.slug, "on-lan");
    assert_eq!(d.recipe.description.as_deref(), Some("Long text"));
    assert_eq!(d.recipe.aliases, ["ol"]);
    assert_eq!(d.recipe.portable, Some(true));
    assert_eq!(
        d.recipe.game.availability,
        voice_core::api::Availability::OnLan
    );
    assert_eq!(d.local.status, "not_installed");
    let ps = d.plan_summary.unwrap();
    assert_eq!(ps.plan_version, 1);
    assert_eq!(ps.files.len(), 2);
    assert_eq!(
        (
            ps.files[0].label.as_str(),
            ps.files[0].source.as_str(),
            ps.files[0].required,
            ps.files[0].size_bytes
        ),
        ("Portable archive", "lan", true, Some(4096))
    );
    assert_eq!(ps.files[1].label, "patch.zip");
    assert!(plan_requests(&r).await[0].contains("dry_run=1"));

    // The JSON shape for the UI: flat recipe, snake_case, no signed link.
    let json = serde_json::to_value(r.features.game_detail("on-lan").await.unwrap()).unwrap();
    assert_eq!(json["recipe"]["slug"], "on-lan");
    assert_eq!(json["recipe"]["local"]["status"], "not_installed");
    assert_eq!(json["local"]["path"], Value::Null);
    assert_eq!(json["plan_summary"]["files"][0]["source"], "lan");
    assert!(!json.to_string().contains("SECRET"));
}

#[tokio::test]
async fn the_detail_survives_a_failed_plan_but_not_a_plan_from_the_future() {
    let r = rig().await;
    for slug in ["flaky", "future", "gone"] {
        let mut recipe = game_summary(slug, json!({}));
        recipe["description"] = json!("d");
        Mock::given(method("GET"))
            .and(path(format!("/api/voice-client/v1/recipes/{slug}")))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"recipe": recipe})))
            .mount(&r.server)
            .await;
    }
    mount_plan(&r, "flaky", 500, json!({"message": "x"})).await;
    let mut future = game_plan("future", true);
    future["plan_version"] = json!(2);
    future["files"] = json!("a shape this client does not know");
    mount_plan(&r, "future", 200, future).await;

    let d = r.features.game_detail("flaky").await.unwrap();
    assert!(d.plan_summary.is_none());
    assert_eq!(
        d.recipe.game.availability,
        voice_core::api::Availability::Unknown
    );

    let e = r.features.game_detail("future").await.unwrap_err();
    assert_eq!(e.code.as_deref(), Some("plan_version_unsupported"));
    assert!(e.message.contains("Aktualisiere die App"));

    let e = r.features.game_detail("missing-slug").await.unwrap_err();
    assert_eq!(e.code.as_deref(), Some("not_found"));
    let e = r.features.game_detail("../x").await.unwrap_err();
    assert_eq!(e.kind, "usage");
}

#[tokio::test]
async fn logging_out_drops_the_cached_availability() {
    let r = rig().await;
    mount_recipes(&r, vec![game_summary("a", json!({}))]).await;
    mount_plan(&r, "a", 200, game_plan("a", true)).await;
    r.features.games_list().await.unwrap();
    assert_eq!(plan_requests(&r).await.len(), 1);

    r.features.stop_all();
    r.features.games_list().await.unwrap();
    assert_eq!(
        plan_requests(&r).await.len(),
        2,
        "another account starts from scratch"
    );
}

#[tokio::test]
async fn the_first_start_imports_the_old_launcher_installs_but_never_its_token() {
    let root = tempfile::tempdir().unwrap();
    let legacy = root.path().join("legacy");
    std::fs::create_dir_all(&legacy).unwrap();
    let game = root.path().join("ut2004");
    std::fs::create_dir_all(&game).unwrap();
    std::fs::write(
        legacy.join("installs.json"),
        json!([{"slug": "unreal-tournament-2004", "target_dir": game.to_str().unwrap()}])
            .to_string(),
    )
    .unwrap();
    std::fs::write(
        legacy.join("config.json"),
        r#"{"token":"old-launcher-token"}"#,
    )
    .unwrap();

    let dir = tempfile::tempdir().unwrap();
    let store = FileTokenStore::new(dir.path().join("credentials.json"));
    let engine = Engine::new(
        store,
        dir.path().join("settings.json"),
        Arc::new(|_| {}),
        true,
    );
    let features = Features::new(
        engine,
        Arc::new(|_, _| {}),
        Arc::new(Recorder::default()),
        FeatureOptions {
            legacy_installs_dir: Some(legacy),
            ..fast()
        },
    )
    .unwrap();

    assert_eq!(
        features.installs.local("unreal-tournament-2004").status,
        "ready"
    );
    for entry in std::fs::read_dir(dir.path()).unwrap() {
        let p = entry.unwrap().path();
        if p.is_file() {
            assert!(!std::fs::read_to_string(&p)
                .unwrap()
                .contains("old-launcher-token"));
        }
    }
}

// ---- local store library ----

mod library {
    use launcher_core::store_library::handoff::Handoff;
    use launcher_core::store_library::testing::{MapFs, MapRegistry};
    use launcher_core::store_library::{Hive, LocalStatus, StoreEnv};

    use super::*;
    use crate::features::library::{LibraryEnv, Sources};

    type Fixture = fn() -> (MapRegistry, MapFs);

    fn acf(name: &str, dir: &str) -> String {
        acf_flags(name, dir, "4")
    }

    fn acf_flags(name: &str, dir: &str, flags: &str) -> String {
        format!(
            "\"AppState\"{{\"name\"\"{name}\"\"StateFlags\"\"{flags}\"\"installdir\"\"{dir}\"}}"
        )
    }

    /// Steam game 10 whose install folder is gone.
    fn steam_broken() -> (MapRegistry, MapFs) {
        (
            steam_reg(),
            MapFs::new().with_file(
                "C:/Steam/steamapps/appmanifest_10.acf",
                &acf("Counter-Strike", "Half-Life"),
            ),
        )
    }

    /// Steam game 10 mid-download.
    fn steam_updating() -> (MapRegistry, MapFs) {
        (
            steam_reg(),
            MapFs::new()
                .with_file(
                    "C:/Steam/steamapps/appmanifest_10.acf",
                    &acf_flags("Counter-Strike", "Half-Life", "1030"),
                )
                .with_dir("C:/Steam/steamapps/common/Half-Life"),
        )
    }

    fn steam_reg() -> MapRegistry {
        MapRegistry::new().with_value(
            Hive::CurrentUser,
            "Software\\Valve\\Steam",
            "SteamPath",
            "C:/Steam",
        )
    }

    fn steam_fs() -> MapFs {
        MapFs::new()
            .with_file(
                "C:/Steam/steamapps/appmanifest_10.acf",
                &acf("Counter-Strike", "Half-Life"),
            )
            .with_dir("C:/Steam/steamapps/common/Half-Life")
    }

    fn steam_only() -> (MapRegistry, MapFs) {
        (steam_reg(), steam_fs())
    }

    fn nothing() -> (MapRegistry, MapFs) {
        (MapRegistry::new(), MapFs::new())
    }

    fn steam_and_gog() -> (MapRegistry, MapFs) {
        let gog = "SOFTWARE\\WOW6432Node\\GOG.com\\Games";
        let reg = steam_reg()
            .with_subkeys(Hive::LocalMachine, gog, &["1207658924"])
            .with_value(
                Hive::LocalMachine,
                &format!("{gog}\\1207658924"),
                "gameID",
                "1207658924",
            )
            .with_value(
                Hive::LocalMachine,
                &format!("{gog}\\1207658924"),
                "gameName",
                "Counter-Strike GOG",
            )
            .with_value(
                Hive::LocalMachine,
                &format!("{gog}\\1207658924"),
                "path",
                "D:/GOG/CS",
            );
        (reg, steam_fs().with_dir("D:/GOG/CS"))
    }

    /// Installs a scan fixture and returns what the opener was asked to open.
    fn use_sources(r: &Rig, build: Fixture) -> Arc<Mutex<Vec<Handoff>>> {
        let opened: Arc<Mutex<Vec<Handoff>>> = Arc::default();
        let rec = opened.clone();
        r.features.set_library_env(LibraryEnv {
            sources: Box::new(move || {
                let (reg, fs) = build();
                Sources {
                    reg: Box::new(reg),
                    fs: Box::new(fs),
                    env: StoreEnv::default(),
                }
            }),
            opener: Box::new(move |h| {
                rec.lock().unwrap().push(h.clone());
                Ok(())
            }),
            ..LibraryEnv::default()
        });
        opened
    }

    fn unpaired(r: &Rig) {
        std::fs::remove_file(r.dir.path().join("credentials.json")).unwrap();
    }

    async fn mount_launcher_config(r: &Rig) {
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
                "api_version": 1, "voice_ready": false, "modules": {},
                "features": {"launcher": true}
            })))
            .mount(&r.server)
            .await;
    }

    #[tokio::test]
    async fn library_list_without_pairing_has_local_entries() {
        let r = rig().await;
        unpaired(&r);
        use_sources(&r, steam_only);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.recipes_reason, Some("not_paired"));
        assert_eq!(list.entries.len(), 1);
        let e = &list.entries[0];
        assert_eq!(e.key, "steam:10");
        assert_eq!(e.primary, "play");
        assert!(e.recipe.is_none());
        assert_eq!(e.store_games[0].title, "Counter-Strike");
        assert_eq!(list.sources.len(), 4);
        assert!(r.server.received_requests().await.unwrap().is_empty());
    }

    #[tokio::test]
    async fn merge_matches_recipe_by_store_id() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary("cs16", json!({"store_ids": {"steam": "10"}}))],
        )
        .await;
        use_sources(&r, steam_only);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.recipes_reason, None);
        assert_eq!(list.entries.len(), 1);
        let e = &list.entries[0];
        assert_eq!(e.key, "recipe:cs16");
        assert_eq!(e.store_games.len(), 1);
        assert_eq!(e.store_games[0].key, "steam:10");
        assert_eq!(e.primary, "play");
        assert_eq!(e.recipe.as_ref().unwrap().slug, "cs16");
        let json = serde_json::to_value(&list).unwrap();
        assert_eq!(json["entries"][0]["store_games"][0]["store"], "steam");
        assert_eq!(json["entries"][0]["store_games"][0]["status"], "ready");
        assert_eq!(json["entries"][0]["recipe"]["store_ids"]["steam"], "10");
    }

    #[tokio::test]
    async fn merge_attaches_two_stores_to_one_recipe() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary(
                "cs16",
                json!({"store_ids": {"steam": "10", "gog": "1207658924"}}),
            )],
        )
        .await;
        use_sources(&r, steam_and_gog);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.entries.len(), 1);
        let keys: Vec<_> = list.entries[0]
            .store_games
            .iter()
            .map(|g| g.key.as_str())
            .collect();
        assert_eq!(keys, ["steam:10", "gog:1207658924"]);
    }

    #[tokio::test]
    async fn merge_keeps_unmatched_both_sides() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(&r, vec![game_summary("other", json!({}))]).await;
        use_sources(&r, steam_only);
        let list = r.features.library_list().await.unwrap();
        let keys: Vec<_> = list.entries.iter().map(|e| e.key.as_str()).collect();
        assert_eq!(keys, ["recipe:other", "steam:10"]);
    }

    #[tokio::test]
    async fn primary_open_store_for_unowned_steam_recipe() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![
                game_summary(
                    "portal2",
                    json!({"installable": false, "store_ids": {"steam": "620"}}),
                ),
                game_summary("plain", json!({"installable": false})),
                game_summary("lan", json!({"store_ids": {"steam": "730"}})),
            ],
        )
        .await;
        use_sources(&r, nothing);
        let list = r.features.library_list().await.unwrap();
        let primary = |k: &str| list.entries.iter().find(|e| e.key == k).unwrap().primary;
        assert_eq!(primary("recipe:portal2"), "open_store");
        assert_eq!(primary("recipe:plain"), "none");
        assert_eq!(primary("recipe:lan"), "install_recipe");
    }

    #[tokio::test]
    async fn a_failing_server_becomes_a_reason_not_an_error() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes"))
            .respond_with(ResponseTemplate::new(500))
            .mount(&r.server)
            .await;
        use_sources(&r, steam_only);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.recipes_reason, Some("server_error"));
        assert_eq!(list.entries.len(), 1);
    }

    #[tokio::test]
    async fn launch_before_any_scan_is_usage_error() {
        let r = rig().await;
        let opened = use_sources(&r, steam_only);
        let e = r.features.library_launch("steam:10").await.unwrap_err();
        assert_eq!(e.kind, "usage");
        assert!(opened.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn launch_unknown_key_is_usage_error() {
        let r = rig().await;
        let opened = use_sources(&r, steam_only);
        r.features.library_list().await.unwrap();
        for key in ["steam:999", "https://evil.example", "recipe:x", ""] {
            let e = r.features.library_launch(key).await.unwrap_err();
            assert_eq!(e.kind, "usage", "{key}");
        }
        assert!(opened.lock().unwrap().is_empty());
        let e = r
            .features
            .library_open_store("steam:999")
            .await
            .unwrap_err();
        assert_eq!(e.kind, "usage");
    }

    #[tokio::test]
    async fn a_broken_game_is_not_opened() {
        let r = rig().await;
        unpaired(&r);
        let opened = use_sources(&r, steam_broken);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.entries[0].store_games[0].status, LocalStatus::Broken);
        assert_eq!(list.entries[0].primary, "none");
        let e = r.features.library_launch("steam:10").await.unwrap_err();
        assert_eq!(e.kind, "usage");
        assert!(opened.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn a_recipe_key_needs_a_ready_store_game() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary("cs16", json!({"store_ids": {"steam": "10"}}))],
        )
        .await;
        let opened = use_sources(&r, steam_updating);
        let list = r.features.library_list().await.unwrap();
        assert_ne!(list.entries[0].primary, "play");
        let e = r.features.library_launch("recipe:cs16").await.unwrap_err();
        assert_eq!(e.kind, "usage");
        assert!(opened.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn launch_uses_cached_scan() {
        let r = rig().await;
        unpaired(&r);
        let opened = use_sources(&r, steam_only);
        r.features.library_list().await.unwrap();
        r.features.library_launch("steam:10").await.unwrap();
        assert_eq!(
            *opened.lock().unwrap(),
            [Handoff::Url("steam://rungameid/10".into())]
        );
    }

    #[tokio::test]
    async fn launch_by_recipe_key_picks_the_ready_store_game() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary("cs16", json!({"store_ids": {"steam": "10"}}))],
        )
        .await;
        let opened = use_sources(&r, steam_only);
        r.features.library_list().await.unwrap();
        r.features.library_launch("recipe:cs16").await.unwrap();
        assert_eq!(
            *opened.lock().unwrap(),
            [Handoff::Url("steam://rungameid/10".into())]
        );
    }

    #[tokio::test]
    async fn open_store_takes_the_steam_id_of_a_recipe() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary(
                "portal2",
                json!({"store_ids": {"steam": "620"}}),
            )],
        )
        .await;
        let opened = use_sources(&r, nothing);
        r.features.library_list().await.unwrap();
        r.features
            .library_open_store("recipe:portal2")
            .await
            .unwrap();
        assert_eq!(
            *opened.lock().unwrap(),
            [Handoff::Url("steam://store/620".into())]
        );
    }

    #[tokio::test]
    async fn scan_refreshes_the_cache() {
        let r = rig().await;
        unpaired(&r);
        use_sources(&r, nothing);
        assert!(r.features.library_list().await.unwrap().entries.is_empty());
        use_sources(&r, steam_only);
        // The cached scan stays until a rescan is asked for.
        assert!(r.features.library_list().await.unwrap().entries.is_empty());
        assert_eq!(r.features.library_scan().await.unwrap().entries.len(), 1);
    }

    #[tokio::test]
    async fn games_state_answers_offline_with_the_local_flag() {
        // Server unreachable: an answer, not an error.
        let r = rig_at(Some("http://127.0.0.1:1".into())).await;
        let s = r.features.games_state().await.unwrap();
        assert_eq!((s.enabled, s.reason), (false, Some("offline")));
        assert_eq!(s.local, cfg!(windows));

        // Server trouble: the same.
        let r = rig().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(ResponseTemplate::new(500))
            .mount(&r.server)
            .await;
        let s = r.features.games_state().await.unwrap();
        assert_eq!((s.enabled, s.reason), (false, Some("server_error")));
        assert_eq!(s.local, cfg!(windows));

        // A rejected token stays an error: the windows are told.
        let r = rig().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(
                ResponseTemplate::new(401).set_body_json(json!({"message": "Unauthenticated."})),
            )
            .mount(&r.server)
            .await;
        let e = r.features.games_state().await.unwrap_err();
        assert_eq!(e.kind, "auth");
        assert_eq!(r.events_named(EVT_AUTH).len(), 1);
    }

    #[tokio::test]
    async fn a_paired_client_with_the_server_down_lists_its_local_games() {
        let r = rig_at(Some("http://127.0.0.1:1".into())).await;
        use_sources(&r, steam_only);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.recipes_reason, Some("offline"));
        assert_eq!(list.entries.len(), 1);
        assert_eq!(list.entries[0].primary, "play");
    }

    #[tokio::test]
    async fn a_slow_server_does_not_hold_the_local_list_back() {
        let r = rig().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/config"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_delay(Duration::from_secs(3))
                    .set_body_json(json!({
                        "api_version": 1, "voice_ready": false, "modules": {},
                        "features": {"launcher": true}
                    })),
            )
            .mount(&r.server)
            .await;
        r.features.set_library_env(LibraryEnv {
            sources: Box::new(|| {
                let (reg, fs) = steam_only();
                Sources {
                    reg: Box::new(reg),
                    fs: Box::new(fs),
                    env: StoreEnv::default(),
                }
            }),
            opener: Box::new(|_| Ok(())),
            recipe_timeout: Duration::from_millis(150),
        });
        let started = std::time::Instant::now();
        let list = r.features.library_list().await.unwrap();
        assert!(started.elapsed() < Duration::from_secs(2));
        assert_eq!(list.recipes_reason, Some("offline"));
        assert_eq!(list.entries.len(), 1);
    }

    #[tokio::test]
    async fn slow_plan_lookups_do_not_hold_the_library_back() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary("cs16", json!({"store_ids": {"steam": "10"}}))],
        )
        .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes/cs16/plan"))
            .respond_with(
                ResponseTemplate::new(200)
                    .set_delay(Duration::from_secs(3))
                    .set_body_json(json!({})),
            )
            .mount(&r.server)
            .await;
        r.features.set_library_env(LibraryEnv {
            sources: Box::new(|| {
                let (reg, fs) = steam_only();
                Sources {
                    reg: Box::new(reg),
                    fs: Box::new(fs),
                    env: StoreEnv::default(),
                }
            }),
            opener: Box::new(|_| Ok(())),
            recipe_timeout: Duration::from_millis(500),
        });
        let started = std::time::Instant::now();
        let list = r.features.library_list().await.unwrap();
        assert!(started.elapsed() < Duration::from_secs(2));
        assert_eq!(list.recipes_reason, None);
        assert_eq!(list.entries[0].key, "recipe:cs16");
        assert!(plan_requests(&r).await.is_empty());
    }

    #[tokio::test]
    async fn the_last_good_recipes_survive_a_failed_load_but_not_a_logout() {
        let r = rig().await;
        mount_launcher_config(&r).await;
        mount_recipes(
            &r,
            vec![game_summary("cs16", json!({"store_ids": {"steam": "10"}}))],
        )
        .await;
        let opened = use_sources(&r, steam_only);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.entries[0].key, "recipe:cs16");

        // The server goes bad: the recipe stays, with the reason, and its key still starts.
        r.server.reset().await;
        mount_launcher_config(&r).await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes"))
            .respond_with(ResponseTemplate::new(500))
            .mount(&r.server)
            .await;
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.recipes_reason, Some("server_error"));
        assert_eq!(list.entries[0].key, "recipe:cs16");
        r.features.library_launch("recipe:cs16").await.unwrap();
        assert_eq!(opened.lock().unwrap().len(), 1);

        // Signed out: the server's recipes are gone, the machine's scan stays.
        unpaired(&r);
        let list = r.features.library_list().await.unwrap();
        assert_eq!(list.recipes_reason, Some("not_paired"));
        assert_eq!(list.entries[0].key, "steam:10");
    }

    #[tokio::test]
    async fn games_state_reports_local() {
        let r = rig().await;
        unpaired(&r);
        let s = r.features.games_state().await.unwrap();
        assert_eq!(s.local, cfg!(windows));
        assert_eq!(serde_json::to_value(&s).unwrap()["local"], cfg!(windows));
    }

    #[test]
    fn verbatim_prefixes_are_stripped_for_spawning() {
        use crate::features::library::strip_verbatim;
        use std::path::{Path, PathBuf};
        let cases = [
            (r"\\?\C:\Games\a.exe", r"C:\Games\a.exe"),
            (r"\\?\UNC\srv\share\a.exe", r"\\srv\share\a.exe"),
            (r"C:\Games\a.exe", r"C:\Games\a.exe"),
            ("/usr/bin/a", "/usr/bin/a"),
            // Not a drive or UNC path: the verbatim form is all there is.
            (
                r"\\?\Volume{0b1c}\Games\a.exe",
                r"\\?\Volume{0b1c}\Games\a.exe",
            ),
        ];
        for (input, want) in cases {
            assert_eq!(strip_verbatim(Path::new(input)), PathBuf::from(want));
        }
        // Too long for the classic API once stripped: keep the verbatim prefix.
        let long = format!(r"\\?\C:\{}\a.exe", "d".repeat(260));
        assert_eq!(strip_verbatim(Path::new(&long)), PathBuf::from(&long));
        let short = format!(r"\\?\C:\{}\a.exe", "d".repeat(200));
        assert_eq!(
            strip_verbatim(Path::new(&short)),
            PathBuf::from(short.trim_start_matches(r"\\?\"))
        );
    }
}
