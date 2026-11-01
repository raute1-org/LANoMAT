//! Push-to-talk over a real LiveKit server. `#[ignore]`d, driven by the same environment
//! variables as `live.rs` (`LANOMAT_VOICE_LIVEKIT_URL`, `_API_KEY`, `_API_SECRET`).
#![cfg(feature = "dev-tokens")]

use std::time::{Duration, Instant};

use voice_core::api::AudioSettings;
use voice_core::audio::synthetic::{ClickConfig, ClickSource, RecordingSink};
use voice_core::audio::{NullSink, SAMPLE_RATE};
use voice_core::devtokens::DevKeys;
use voice_core::latency::OnsetDetector;
use voice_core::session::{Session, SessionOptions};

/// A published but muted track is silent at the receiver, becomes audible on
/// `set_talking(true)` and silent again on `set_talking(false)`.
#[tokio::test]
#[ignore = "needs a LiveKit server: set LANOMAT_VOICE_LIVEKIT_URL, _API_KEY and _API_SECRET"]
async fn push_to_talk_mutes_and_unmutes_over_the_sfu() {
    let keys = DevKeys::from_env().expect("LiveKit env vars");
    let room = format!("live-ptt-{}", std::process::id());
    let tx_grant = keys
        .grant(&room, "ptt-tx", AudioSettings::default())
        .unwrap();
    let rx_grant = keys
        .grant(&room, "ptt-rx", AudioSettings::default())
        .unwrap();
    let opts = SessionOptions {
        start_talking: false,
        audio_processing: false,
        ..SessionOptions::default()
    };

    let (sink, recording) = RecordingSink::new();
    let rx = Session::connect(&rx_grant, None, Box::new(sink), opts.clone())
        .await
        .unwrap();
    let click = ClickConfig {
        warmup: 0,
        measured: 30,
        period: Duration::from_millis(300),
        tail: Duration::from_millis(500),
        ..ClickConfig::default()
    };
    let (source, log) = ClickSource::new(click);
    let tx = Session::connect(&tx_grant, Some(Box::new(source)), Box::new(NullSink), opts)
        .await
        .unwrap();

    tokio::time::sleep(Duration::from_secs(3)).await;
    let on = Instant::now();
    assert_eq!(tx.set_talking(true), Ok(true));
    tokio::time::sleep(Duration::from_secs(3)).await;
    let off = Instant::now();
    assert_eq!(tx.set_talking(false), Ok(false));
    tokio::time::sleep(Duration::from_secs(4)).await;
    tx.close().await;
    rx.close().await;

    let frames = recording.frames();
    assert!(!frames.is_empty(), "the receiver never got any audio frame");
    let origin = log.snapshot()[0].min(frames[0].at);
    let mut det = OnsetDetector::new(3277, SAMPLE_RATE, Duration::from_millis(150));
    let detected: Vec<Duration> = frames
        .iter()
        .filter_map(|f| det.feed(&f.samples, f.at.duration_since(origin)))
        .collect();
    let rel = |i: Instant| i.duration_since(origin);
    let muted_before = detected.iter().filter(|&&d| d < rel(on)).count();
    let talking = detected
        .iter()
        .filter(|&&d| d > rel(on) + Duration::from_millis(500) && d < rel(off))
        .count();
    let muted_after = detected
        .iter()
        .filter(|&&d| d > rel(off) + Duration::from_millis(700))
        .count();
    println!("detected: before={muted_before} talking={talking} after={muted_after}");
    assert_eq!(muted_before, 0, "audio leaked while muted at the start");
    assert!(talking >= 6, "too few bursts while talking: {talking}");
    assert_eq!(muted_after, 0, "audio leaked after muting again");
}
