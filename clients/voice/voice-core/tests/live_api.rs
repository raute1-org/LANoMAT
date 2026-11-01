//! Reads every endpoint group of the native client API from a REAL LANoMAT and follows a chat
//! message over the real Reverb websocket. `#[ignore]`d, driven by environment variables only:
//!
//! ```text
//! LANOMAT_VOICE_BASE_URL=https://<LAN_DOMAIN> LANOMAT_VOICE_TOKEN_FILE=<path to credentials.json> \
//!   cargo test -p voice-core --test live_api -- --ignored --nocapture
//! ```
//!
//! It posts one chat message to the first room and deletes it again, so run it as a moderator
//! (orga/admin) against a test instance.

use std::time::Duration;

use voice_core::api::{ApiClient, FileTokenStore, TokenStore};
use voice_core::realtime::{self, ChatEvent, RealtimeOptions};

fn client() -> ApiClient {
    let base = std::env::var("LANOMAT_VOICE_BASE_URL").expect("LANOMAT_VOICE_BASE_URL");
    let file = std::env::var("LANOMAT_VOICE_TOKEN_FILE").expect("LANOMAT_VOICE_TOKEN_FILE");
    let creds = FileTokenStore::new(file)
        .load()
        .expect("readable token file")
        .expect("a stored login");
    ApiClient::new(&base)
        .expect("base url")
        .with_token(creds.access_token)
}

#[tokio::test]
#[ignore = "needs a real LANoMAT: set LANOMAT_VOICE_BASE_URL and LANOMAT_VOICE_TOKEN_FILE"]
async fn every_read_endpoint_deserialises_against_the_real_server() {
    let api = client();
    let me = api.me().await.expect("me");
    let cfg = api.config().await.expect("config");
    println!(
        "me={} modules={:?} realtime={}",
        me.name,
        cfg.modules.len(),
        cfg.realtime.is_some()
    );
    let rooms = api.rooms().await.expect("rooms");
    println!("rooms={}", rooms.len());
    let events = api.events().await.expect("events");
    let slug = events
        .current_event_slug
        .clone()
        .or_else(|| events.events.first().map(|e| e.slug.clone()))
        .expect("at least one event");
    println!("events={} current={slug}", events.events.len());

    let now = api.now(&slug).await.expect("now feed");
    println!("now: {} cards, {} more", now.cards.len(), now.more.len());
    println!(
        "tournaments={}",
        api.tournaments(&slug).await.expect("tournaments").len()
    );
    println!(
        "my_matches={}",
        api.my_matches(&slug).await.expect("my matches").len()
    );
    println!(
        "event_servers={}",
        api.event_servers(&slug).await.expect("event servers").len()
    );
    println!(
        "permanent_servers={}",
        api.permanent_servers()
            .await
            .expect("permanent servers")
            .len()
    );
    println!(
        "polls={}",
        api.polls(&slug).await.expect("polls").polls.len()
    );
    let catering = api.catering(&slug).await.expect("catering");
    println!("catering orders={}", catering.orders.len());
    api.catering_mine(&slug).await.expect("catering mine");
    println!(
        "files={}",
        api.files(&slug).await.expect("files").files.len()
    );
    api.files_quota(&slug).await.expect("quota");
    let unread = api.unread_count().await.expect("unread");
    println!("notifications unread={unread}");
    api.notifications(false, 1, 20)
        .await
        .expect("notifications");
    println!(
        "prefs={}",
        api.notification_preferences().await.expect("prefs").len()
    );
    let profile = api.profile().await.expect("profile");
    println!(
        "profile id={} editable={} steam_link={}",
        profile.id,
        profile.editable.len(),
        profile.has_verified_steam_link
    );
    let connections = api.connections().await.expect("connections");
    println!(
        "connections={:?}",
        connections
            .iter()
            .map(|c| format!("{}:{}", c.provider, c.status))
            .collect::<Vec<_>>()
    );
    println!("friends={}", api.friends().await.expect("friends").len());
    let requests = api.friend_requests().await.expect("friend requests");
    println!(
        "friend requests incoming={} outgoing={}",
        requests.incoming.len(),
        requests.outgoing.len()
    );
    println!(
        "friend suggestions={}",
        api.friend_suggestions()
            .await
            .expect("friend suggestions")
            .len()
    );
    println!(
        "blocked={}",
        api.blocked_users().await.expect("blocked").len()
    );
    if let Some(room) = rooms.first() {
        let page = api.messages(room.id, None).await.expect("messages");
        println!("messages={}", page.messages.len());
    }
}

#[tokio::test]
#[ignore = "needs a real LANoMAT with Reverb and a moderator login"]
async fn a_chat_message_arrives_over_the_real_websocket() {
    let api = client();
    let cfg = api.config().await.expect("config");
    let rooms = api.rooms().await.expect("rooms");
    let room = rooms.first().expect("a room").id;
    println!("realtime config present: {}", cfg.realtime.is_some());

    let (handle, mut events) = realtime::spawn(
        api.clone(),
        cfg.realtime.clone(),
        RealtimeOptions::default(),
    );
    handle.set_rooms([room]);

    // Wait for the link to be live (or tell what state it settled in).
    let mut state = String::new();
    let live = tokio::time::timeout(Duration::from_secs(20), async {
        while let Some(ev) = events.recv().await {
            if let ChatEvent::Status { state: s, detail } = ev {
                state = format!("{s:?} {detail:?}");
                println!("link: {state}");
                if matches!(s, realtime::LinkState::Live) {
                    return true;
                }
            }
        }
        false
    })
    .await
    .unwrap_or(false);
    assert!(live, "realtime link never went live, last state: {state}");

    let marker = format!("live-test {}", std::process::id());
    let posted = api.post_message(room, &marker).await.expect("post");
    let got = tokio::time::timeout(Duration::from_secs(10), async {
        while let Some(ev) = events.recv().await {
            if let ChatEvent::MessagePosted { message, .. } = ev {
                if message.id == posted.id {
                    return true;
                }
            }
        }
        false
    })
    .await
    .unwrap_or(false);
    let _ = api.delete_message(posted.id).await;
    assert!(got, "the message did not arrive over the websocket");
}
