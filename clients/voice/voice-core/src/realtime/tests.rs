//! Tests against an in-process fake Pusher server (tokio + tungstenite) and a wiremock API.

use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use serde_json::{json, Value};
use tokio::net::TcpListener;
use tokio::sync::mpsc;
use tokio_tungstenite::tungstenite::Message;
use wiremock::matchers::{header, method, path};
use wiremock::{Mock, MockServer, Request, ResponseTemplate};

use super::*;

enum Cmd {
    Send(String),
    Close,
}

#[derive(Default)]
struct FakeState {
    connections: AtomicUsize,
    /// (connection index, socket id, channel, auth as received)
    subs: Mutex<Vec<(usize, String, String, String)>>,
    unsubs: Mutex<Vec<String>>,
    pongs: AtomicUsize,
    pings_from_client: AtomicUsize,
    conns: Mutex<Vec<mpsc::UnboundedSender<Cmd>>>,
    activity_timeout: AtomicUsize,
    answer_client_pings: AtomicBool,
    /// Accept the TCP connection and close it at once (no WebSocket handshake).
    refuse: AtomicBool,
}

struct FakePusher {
    port: u16,
    st: Arc<FakeState>,
}

fn sig(socket_id: &str, channel: &str) -> String {
    format!("app-key:sig-{socket_id}-{channel}")
}

impl FakePusher {
    async fn start() -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let st = Arc::new(FakeState::default());
        st.activity_timeout.store(30, Ordering::SeqCst);
        st.answer_client_pings.store(true, Ordering::SeqCst);
        let st2 = st.clone();
        tokio::spawn(async move {
            loop {
                let Ok((tcp, _)) = listener.accept().await else {
                    return;
                };
                if st2.refuse.load(Ordering::SeqCst) {
                    drop(tcp);
                    continue;
                }
                let st = st2.clone();
                tokio::spawn(async move {
                    let Ok(ws) = tokio_tungstenite::accept_async(tcp).await else {
                        return;
                    };
                    let idx = st.connections.fetch_add(1, Ordering::SeqCst);
                    let socket_id = format!("{}.77", 100 + idx);
                    let (mut sink, mut stream) = ws.split();
                    let (ctx, mut crx) = mpsc::unbounded_channel();
                    st.conns.lock().unwrap().push(ctx);
                    let hello = json!({"event": "pusher:connection_established", "data":
                        json!({"socket_id": socket_id,
                               "activity_timeout": st.activity_timeout.load(Ordering::SeqCst)}).to_string()});
                    let _ = sink.send(Message::text(hello.to_string())).await;
                    loop {
                        tokio::select! {
                            cmd = crx.recv() => match cmd {
                                Some(Cmd::Send(s)) => { let _ = sink.send(Message::text(s)).await; }
                                Some(Cmd::Close) | None => { let _ = sink.close().await; return; }
                            },
                            frame = stream.next() => {
                                let Some(Ok(Message::Text(t))) = frame else { return };
                                let v: Value = serde_json::from_str(t.as_str()).unwrap();
                                match v["event"].as_str().unwrap_or("") {
                                    "pusher:subscribe" => {
                                        let ch = v["data"]["channel"].as_str().unwrap().to_owned();
                                        let auth = v["data"]["auth"].as_str().unwrap_or("").to_owned();
                                        st.subs.lock().unwrap().push((idx, socket_id.clone(), ch.clone(), auth));
                                        let ok = json!({"event": "pusher_internal:subscription_succeeded", "channel": ch, "data": "{}"});
                                        let _ = sink.send(Message::text(ok.to_string())).await;
                                    }
                                    "pusher:unsubscribe" => {
                                        st.unsubs.lock().unwrap().push(v["data"]["channel"].as_str().unwrap().to_owned());
                                    }
                                    "pusher:pong" => { st.pongs.fetch_add(1, Ordering::SeqCst); }
                                    "pusher:ping" => {
                                        st.pings_from_client.fetch_add(1, Ordering::SeqCst);
                                        if st.answer_client_pings.load(Ordering::SeqCst) {
                                            let _ = sink.send(Message::text(json!({"event": "pusher:pong", "data": "{}"}).to_string())).await;
                                        }
                                    }
                                    _ => {}
                                }
                            }
                        }
                    }
                });
            }
        });
        Self { port, st }
    }

    fn config(&self) -> RealtimeConfig {
        RealtimeConfig {
            driver: "reverb".into(),
            key: "app-key".into(),
            host: "127.0.0.1".into(),
            port: self.port,
            scheme: "http".into(),
            auth_endpoint: "/api/voice-client/v1/broadcasting/auth".into(),
        }
    }

    fn send_to_all(&self, s: String) {
        for c in self.st.conns.lock().unwrap().iter() {
            let _ = c.send(Cmd::Send(s.clone()));
        }
    }

    fn event(&self, channel: &str, event: &str, data: Value) {
        self.send_to_all(
            json!({"event": event, "channel": channel, "data": data.to_string()}).to_string(),
        );
    }

    fn drop_connections(&self) {
        for c in self.st.conns.lock().unwrap().drain(..) {
            let _ = c.send(Cmd::Close);
        }
    }

    async fn wait_subscribed(&self, channel: &str, count: usize) {
        for _ in 0..200 {
            let n = self
                .st
                .subs
                .lock()
                .unwrap()
                .iter()
                .filter(|s| s.2 == channel)
                .count();
            if n >= count {
                return;
            }
            tokio::time::sleep(Duration::from_millis(25)).await;
        }
        panic!("channel {channel} was not subscribed {count} time(s)");
    }
}

fn msg(id: u64, room: u64) -> Value {
    json!({"id": id, "channel_id": room, "body": format!("m{id}"),
           "created_at": "2026-11-02T12:00:00+00:00", "author": {"id": 7, "name": "Ada", "avatar_url": null}})
}

struct Api {
    server: MockServer,
    auth_bodies: Arc<Mutex<Vec<Value>>>,
    messages: Arc<Mutex<Vec<Value>>>,
}

async fn api() -> Api {
    let server = MockServer::start().await;
    let auth_bodies = Arc::new(Mutex::new(Vec::new()));
    let a = auth_bodies.clone();
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/broadcasting/auth"))
        .and(header("authorization", "Bearer tok"))
        .respond_with(move |req: &Request| {
            let b: Value = req.body_json().unwrap();
            a.lock().unwrap().push(b.clone());
            let ch = b["channel_name"].as_str().unwrap_or("");
            if ch.ends_with(".666") {
                return ResponseTemplate::new(403).set_body_json(json!({"message": "no"}));
            }
            ResponseTemplate::new(200)
                .set_body_json(json!({"auth": sig(b["socket_id"].as_str().unwrap(), ch)}))
        })
        .mount(&server)
        .await;
    let messages = Arc::new(Mutex::new(Vec::new()));
    let m = messages.clone();
    Mock::given(method("GET"))
        .and(wiremock::matchers::path_regex(
            r"^/api/voice-client/v1/rooms/\d+/messages$",
        ))
        .respond_with(move |_: &Request| {
            let list = m.lock().unwrap().clone();
            ResponseTemplate::new(200).set_body_json(json!({"messages": list, "next_before": null}))
        })
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/api/voice-client/v1/rooms"))
        .respond_with(ResponseTemplate::new(200).set_body_json(
            json!({"rooms": [{"id": 3, "name": "Lobby", "description": null, "kind": "general"}]}),
        ))
        .mount(&server)
        .await;
    Api {
        server,
        auth_bodies,
        messages,
    }
}

fn fast() -> RealtimeOptions {
    RealtimeOptions {
        poll_interval: Duration::from_millis(100),
        backoff_base: Duration::from_millis(50),
        backoff_cap: Duration::from_millis(200),
        fall_back_after: 2,
        connect_timeout: Duration::from_secs(2),
        pong_timeout: Duration::from_millis(300),
        tick: Duration::from_millis(50),
    }
}

async fn recv(rx: &mut mpsc::UnboundedReceiver<ChatEvent>) -> ChatEvent {
    tokio::time::timeout(Duration::from_secs(5), rx.recv())
        .await
        .expect("timed out waiting for a ChatEvent")
        .expect("event stream closed")
}

async fn until<F: Fn(&ChatEvent) -> bool>(
    rx: &mut mpsc::UnboundedReceiver<ChatEvent>,
    pred: F,
) -> ChatEvent {
    loop {
        let e = recv(rx).await;
        if pred(&e) {
            return e;
        }
    }
}

fn is_state(s: LinkState) -> impl Fn(&ChatEvent) -> bool {
    move |e| matches!(e, ChatEvent::Status { state, .. } if *state == s)
}

fn client(api: &Api) -> ApiClient {
    ApiClient::new(&api.server.uri()).unwrap().with_token("tok")
}

#[tokio::test]
async fn handshake_auth_round_trip_and_event_delivery() {
    let fake = FakePusher::start().await;
    let api = api().await;
    let (h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    h.set_rooms([3]);
    until(&mut rx, is_state(LinkState::Live)).await;
    fake.wait_subscribed(DIRECTORY_CHANNEL, 1).await;
    fake.wait_subscribed("private-web-voice.channel.3", 1).await;

    // The auth call carried the socket id of the connection and the channel, with the bearer.
    let bodies = api.auth_bodies.lock().unwrap().clone();
    assert!(bodies.iter().all(|b| b["socket_id"] == "100.77"));
    let chans: Vec<&str> = bodies
        .iter()
        .map(|b| b["channel_name"].as_str().unwrap())
        .collect();
    assert!(chans.contains(&DIRECTORY_CHANNEL) && chans.contains(&"private-web-voice.channel.3"));
    // The server saw exactly the signatures the API round trip produced.
    for (_, sid, ch, auth) in fake.st.subs.lock().unwrap().iter() {
        assert_eq!(auth, &sig(sid, ch));
    }

    fake.event(
        "private-web-voice.channel.3",
        "message.posted",
        json!({"message": msg(500, 3)}),
    );
    match until(&mut rx, |e| matches!(e, ChatEvent::MessagePosted { .. })).await {
        ChatEvent::MessagePosted { room_id, message } => {
            assert_eq!(
                (room_id, message.id, message.body.as_str()),
                (3, 500, "m500")
            );
        }
        _ => unreachable!(),
    }
    fake.event(
        "private-web-voice.channel.3",
        "message.deleted",
        json!({"channel_id": 3, "message_id": 500}),
    );
    assert_eq!(
        until(&mut rx, |e| matches!(e, ChatEvent::MessageDeleted { .. })).await,
        ChatEvent::MessageDeleted {
            room_id: 3,
            message_id: 500
        }
    );
    fake.event(DIRECTORY_CHANNEL, "channels.changed", json!({}));
    until(&mut rx, |e| matches!(e, ChatEvent::ChannelsChanged)).await;
}

#[tokio::test]
async fn rooms_can_be_added_and_dropped_while_connected() {
    let fake = FakePusher::start().await;
    let api = api().await;
    let (h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    until(&mut rx, is_state(LinkState::Live)).await;
    h.set_rooms([3]);
    fake.wait_subscribed("private-web-voice.channel.3", 1).await;
    h.set_rooms([] as [u64; 0]);
    for _ in 0..100 {
        if !fake.st.unsubs.lock().unwrap().is_empty() {
            break;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    assert_eq!(
        fake.st.unsubs.lock().unwrap().clone(),
        vec!["private-web-voice.channel.3".to_owned()]
    );
}

#[tokio::test]
async fn a_forbidden_channel_is_skipped_without_killing_the_link() {
    let fake = FakePusher::start().await;
    let api = api().await;
    let (h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    h.set_rooms([666, 3]);
    until(&mut rx, is_state(LinkState::Live)).await;
    fake.wait_subscribed("private-web-voice.channel.3", 1).await;
    let subs = fake.st.subs.lock().unwrap().clone();
    assert!(!subs.iter().any(|s| s.2.ends_with(".666")));
    assert_eq!(fake.st.connections.load(Ordering::SeqCst), 1);
}

#[tokio::test]
async fn server_pings_are_answered() {
    let fake = FakePusher::start().await;
    let api = api().await;
    let (_h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    until(&mut rx, is_state(LinkState::Live)).await;
    fake.send_to_all(json!({"event": "pusher:ping", "data": "{}"}).to_string());
    for _ in 0..100 {
        if fake.st.pongs.load(Ordering::SeqCst) > 0 {
            return;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    panic!("no pusher:pong for the server's ping");
}

#[tokio::test]
async fn a_silent_link_is_pinged_and_then_reconnected() {
    let fake = FakePusher::start().await;
    fake.st.activity_timeout.store(1, Ordering::SeqCst);
    fake.st.answer_client_pings.store(false, Ordering::SeqCst);
    let api = api().await;
    let (_h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    until(&mut rx, is_state(LinkState::Live)).await;
    // ~1 s of silence, our ping, no pong within 300 ms: the client drops and reconnects.
    until(&mut rx, is_state(LinkState::Reconnecting)).await;
    until(&mut rx, is_state(LinkState::Live)).await;
    assert!(fake.st.pings_from_client.load(Ordering::SeqCst) >= 1);
    assert!(fake.st.connections.load(Ordering::SeqCst) >= 2);
}

#[tokio::test]
async fn reconnect_reauthorises_with_the_new_socket_id_and_catches_up() {
    let fake = FakePusher::start().await;
    let api = api().await;
    api.messages.lock().unwrap().push(msg(10, 3));
    let (h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    h.set_rooms([3]);
    until(&mut rx, is_state(LinkState::Live)).await;
    fake.wait_subscribed("private-web-voice.channel.3", 1).await;

    // A live message, then the connection dies; a message arrives while we are away.
    fake.event(
        "private-web-voice.channel.3",
        "message.posted",
        json!({"message": msg(11, 3)}),
    );
    until(&mut rx, |e| matches!(e, ChatEvent::MessagePosted { .. })).await;
    // The server has both messages before the link dies (the client does not poll while live), so
    // the catch-up after the reconnect cannot race with the test's own bookkeeping.
    api.messages.lock().unwrap().push(msg(11, 3));
    api.messages.lock().unwrap().push(msg(12, 3));
    fake.drop_connections();

    // Collect everything until the link is live again and the missed message has arrived: under
    // load the first reconnect attempt may fail once, and the catch-up then runs from the
    // polling fallback before "live", so the order of the two is not part of the contract.
    let mut seen: Vec<ChatEvent> = Vec::new();
    let live = is_state(LinkState::Live);
    loop {
        let has_live = seen.iter().any(&live);
        let has_12 = seen
            .iter()
            .any(|e| matches!(e, ChatEvent::MessagePosted { message, .. } if message.id == 12));
        if has_live && has_12 {
            break;
        }
        seen.push(recv(&mut rx).await);
    }
    assert!(seen.iter().any(is_state(LinkState::Reconnecting)));
    let posted: Vec<u64> = seen
        .iter()
        .filter_map(|e| match e {
            ChatEvent::MessagePosted { message, .. } => Some(message.id),
            _ => None,
        })
        .collect();
    assert_eq!(
        posted,
        vec![12],
        "only the missed message (11 was seen live, 10 was the baseline)"
    );

    // The last connection has a new socket id and every channel was authorised again for it.
    fake.wait_subscribed(DIRECTORY_CHANNEL, 2).await;
    fake.wait_subscribed("private-web-voice.channel.3", 2).await;
    let subs = fake.st.subs.lock().unwrap().clone();
    let last = subs.iter().map(|s| s.0).max().unwrap();
    assert!(last >= 1);
    let current: Vec<_> = subs.iter().filter(|s| s.0 == last).collect();
    let socket_id = format!("{}.77", 100 + last);
    assert!(current
        .iter()
        .all(|s| s.1 == socket_id && s.3 == sig(&s.1, &s.2)));
    assert!(current.iter().any(|s| s.2 == DIRECTORY_CHANNEL));
    assert!(current.iter().any(|s| s.2 == "private-web-voice.channel.3"));
    assert!(api
        .auth_bodies
        .lock()
        .unwrap()
        .iter()
        .any(|b| b["socket_id"] == socket_id.as_str()));
}

#[tokio::test]
async fn without_realtime_info_it_polls() {
    let api = api().await;
    api.messages.lock().unwrap().push(msg(1, 3));
    let (h, mut rx) = spawn(client(&api), None, fast());
    h.set_rooms([3]);
    until(&mut rx, is_state(LinkState::Polling)).await;
    // First pass sets the baseline silently; a new message is then delivered.
    tokio::time::sleep(Duration::from_millis(250)).await;
    api.messages.lock().unwrap().push(msg(2, 3));
    match until(&mut rx, |e| matches!(e, ChatEvent::MessagePosted { .. })).await {
        ChatEvent::MessagePosted { room_id, message } => {
            assert_eq!((room_id, message.id), (3, 2));
        }
        _ => unreachable!(),
    }
}

#[tokio::test]
async fn an_unreachable_socket_falls_back_to_polling_and_recovers() {
    let fake = FakePusher::start().await;
    fake.st.refuse.store(true, Ordering::SeqCst);
    let api = api().await;
    api.messages.lock().unwrap().push(msg(1, 3));
    let (h, mut rx) = spawn(client(&api), Some(fake.config()), fast());
    h.set_rooms([3]);
    until(&mut rx, is_state(LinkState::Polling)).await;
    tokio::time::sleep(Duration::from_millis(250)).await;
    api.messages.lock().unwrap().push(msg(2, 3));
    match until(&mut rx, |e| matches!(e, ChatEvent::MessagePosted { .. })).await {
        ChatEvent::MessagePosted { message, .. } => assert_eq!(message.id, 2),
        _ => unreachable!(),
    }
    // The socket comes back: live again, and live events flow.
    fake.st.refuse.store(false, Ordering::SeqCst);
    until(&mut rx, is_state(LinkState::Live)).await;
    fake.wait_subscribed("private-web-voice.channel.3", 1).await;
    fake.event(
        "private-web-voice.channel.3",
        "message.posted",
        json!({"message": msg(3, 3)}),
    );
    match until(&mut rx, |e| matches!(e, ChatEvent::MessagePosted { .. })).await {
        ChatEvent::MessagePosted { message, .. } => assert_eq!(message.id, 3),
        _ => unreachable!(),
    }
}

#[tokio::test]
async fn a_rejected_token_stops_the_client_for_good() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .respond_with(
            ResponseTemplate::new(401).set_body_json(json!({"message": "Unauthenticated."})),
        )
        .mount(&server)
        .await;
    let c = ApiClient::new(&server.uri()).unwrap().with_token("bad");
    let (_h, mut rx) = spawn(c, None, fast());
    until(&mut rx, is_state(LinkState::Offline)).await;
}

#[test]
fn ws_urls_follow_the_scheme() {
    let mut c = RealtimeConfig {
        host: "ws.example.org".into(),
        port: 443,
        scheme: "https".into(),
        key: "k".into(),
        ..Default::default()
    };
    assert!(ws_url(&c).starts_with("wss://ws.example.org:443/app/k?"));
    c.scheme = "http".into();
    c.port = 8080;
    assert!(ws_url(&c).starts_with("ws://ws.example.org:8080/app/k?"));
}
