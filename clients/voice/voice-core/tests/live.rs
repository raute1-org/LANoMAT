//! Integration tests that need a real LiveKit server (and, for the second one, a real LANoMAT).
//! All `#[ignore]`d and driven by environment variables, never by anything in the repository:
//!
//! ```text
//! # loopback against `livekit-server --dev` (see clients/voice/README.md)
//! LANOMAT_VOICE_LIVEKIT_URL=ws://127.0.0.1:7880 \
//! LANOMAT_VOICE_LIVEKIT_API_KEY=<key> LANOMAT_VOICE_LIVEKIT_API_SECRET=<secret> \
//!   cargo test -p voice-core --features dev-tokens --test live -- --ignored --nocapture
//!
//! # against a real LANoMAT after `lanomat-voice login`
//! LANOMAT_VOICE_BASE_URL=https://<LAN_DOMAIN> LANOMAT_VOICE_TOKEN_FILE=<path to credentials.json> \
//!   LANOMAT_VOICE_ROOM=<room id or name> cargo test -p voice-core --test live -- --ignored
//! ```
#![cfg(feature = "dev-tokens")]

use std::time::Duration;

use voice_core::api::{ApiClient, AudioSettings, FileTokenStore, TokenStore};
use voice_core::audio::synthetic::ClickConfig;
use voice_core::audio::NullSink;
use voice_core::devtokens::DevKeys;
use voice_core::latency_test::run_latency_test;
use voice_core::session::{Session, SessionOptions};

/// Two sessions over the loopback: the bare publish/subscribe path of the session code, with the
/// latency statistics as the proof that audio really crossed the SFU.
#[tokio::test]
#[ignore = "needs a LiveKit server: set LANOMAT_VOICE_LIVEKIT_URL, _API_KEY and _API_SECRET"]
async fn loopback_latency_between_two_sessions() {
    let keys = DevKeys::from_env().expect("LiveKit env vars");
    let room = format!("live-test-{}", std::process::id());
    let tx = keys
        .grant(&room, "probe-tx", AudioSettings::default())
        .unwrap();
    let rx = keys
        .grant(&room, "probe-rx", AudioSettings::default())
        .unwrap();
    let click = ClickConfig {
        warmup: 2,
        measured: 8,
        ..ClickConfig::default()
    };
    let report = run_latency_test(&tx, &rx, click)
        .await
        .expect("latency test");
    let stats = report
        .stats
        .clone()
        .expect("at least one burst must arrive");
    println!(
        "{}\nlost {} of {}",
        stats.report(),
        report.lost(),
        report.sent
    );
    assert!(report.delays.len() >= 6, "too many lost bursts: {report:?}");
    // Loopback on one machine: anything near a second means something is badly wrong.
    assert!(stats.median < Duration::from_millis(500), "{stats:?}");
}

/// A listen-only join of a real room through the real API: pairing token from the credential
/// file, room token from `POST rooms/{id}/token`, connect to the LiveKit URL the server hands out.
#[tokio::test]
#[ignore = "needs a real LANoMAT: set LANOMAT_VOICE_BASE_URL, LANOMAT_VOICE_TOKEN_FILE, LANOMAT_VOICE_ROOM"]
async fn join_a_real_room_listen_only() {
    let base = std::env::var("LANOMAT_VOICE_BASE_URL").expect("LANOMAT_VOICE_BASE_URL");
    let file = std::env::var("LANOMAT_VOICE_TOKEN_FILE").expect("LANOMAT_VOICE_TOKEN_FILE");
    let wanted = std::env::var("LANOMAT_VOICE_ROOM").expect("LANOMAT_VOICE_ROOM");
    let creds = FileTokenStore::new(file)
        .load()
        .expect("readable credential file")
        .expect("credential file exists");
    let client = ApiClient::new(&base)
        .unwrap()
        .with_token(creds.access_token);
    let rooms = client.rooms().await.expect("rooms");
    let room = rooms
        .iter()
        .find(|r| r.id.to_string() == wanted || r.name.eq_ignore_ascii_case(&wanted))
        .expect("room visible to this user");
    let grant = client.room_token(room.id).await.expect("room token");
    let session = Session::connect(&grant, None, Box::new(NullSink), SessionOptions::default())
        .await
        .expect("connect");
    assert_eq!(session.identity(), grant.identity);
    session.close().await;
}
