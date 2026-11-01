//! The two-session latency self-test, the native counterpart of the browser's "Latenztest":
//! one session publishes synthetic click bursts, a second one in the same process receives them,
//! and the delay is `detected - scheduled` on one `Instant` clock. No microphone or speaker.
//!
//! What it measures: capture hand-over, Opus encode, network, SFU, jitter buffer, decode, receive
//! pull. What it excludes: device buffers of the OS, capture processing, the human. Both ends
//! work in 10 ms frames, so each end adds up to one frame of quantisation (see the README).

use std::time::{Duration, Instant};

use crate::api::JoinGrant;
use crate::audio::synthetic::{ClickConfig, ClickSource, RecordingSink};
use crate::audio::{NullSink, SAMPLE_RATE};
use crate::error::{Error, Result};
use crate::latency::{match_onsets, LatencyStats, OnsetDetector};
use crate::session::{Session, SessionOptions};

/// Magnitude (of 32767) a sample must reach to count as the click: 10 %, well below the 50 %
/// burst and well above the decoder's noise floor.
const DETECT_THRESHOLD: i16 = 3277;

#[derive(Debug, Clone)]
pub struct LatencyReport {
    /// Measured bursts sent (warm-up excluded).
    pub sent: usize,
    pub delays: Vec<Duration>,
    pub stats: Option<LatencyStats>,
}

impl LatencyReport {
    pub fn lost(&self) -> usize {
        self.sent - self.delays.len()
    }
}

/// Runs the test with two prepared grants (different identities, same room).
pub async fn run_latency_test(
    tx_grant: &JoinGrant,
    rx_grant: &JoinGrant,
    click: ClickConfig,
) -> Result<LatencyReport> {
    let (sink, recording) = RecordingSink::new();
    // Receiver first, so it is in the room when the transmitter publishes.
    let rx_opts = SessionOptions {
        audio: rx_grant.audio.clone(),
        start_talking: false,
        audio_processing: false,
    };
    let rx = Session::connect(rx_grant, None, Box::new(sink), rx_opts).await?;

    let (source, log) = ClickSource::new(click.clone());
    let tx_opts = SessionOptions {
        audio: tx_grant.audio.clone(),
        start_talking: true,
        audio_processing: false,
    };
    let tx = Session::connect(
        tx_grant,
        Some(Box::new(source)),
        Box::new(NullSink),
        tx_opts,
    )
    .await?;

    // The source schedules `total_bursts` onsets one period apart, then a tail.
    let run_time = click.period * click.total_bursts() as u32 + click.tail + Duration::from_secs(3);
    let deadline = Instant::now() + run_time;
    while log.snapshot().len() < click.total_bursts() && Instant::now() < deadline {
        tokio::time::sleep(Duration::from_millis(100)).await;
    }
    // Let the last burst travel through the SFU and the jitter buffer.
    tokio::time::sleep(Duration::from_millis(1500)).await;
    tx.close().await;
    rx.close().await;

    analyse(
        &log.snapshot(),
        &recording
            .frames()
            .iter()
            .map(|f| (f.at, f.samples.clone()))
            .collect::<Vec<_>>(),
        click.warmup as usize,
        click.period,
    )
}

/// Pure analysis step, separate so it is unit-testable with fabricated instants.
pub(crate) fn analyse(
    sent: &[Instant],
    frames: &[(Instant, Vec<i16>)],
    warmup: usize,
    period: Duration,
) -> Result<LatencyReport> {
    if sent.len() <= warmup {
        return Err(Error::Audio(
            "the transmitter never produced a measured burst".into(),
        ));
    }
    if frames.is_empty() {
        return Err(Error::Audio(
            "no audio was received: the second session never subscribed to the first".into(),
        ));
    }
    let origin = sent[0].min(frames[0].0);
    let mut detector = OnsetDetector::new(DETECT_THRESHOLD, SAMPLE_RATE, period / 2);
    let detected: Vec<Duration> = frames
        .iter()
        .filter_map(|(at, samples)| detector.feed(samples, at.duration_since(origin)))
        .collect();
    let measured: Vec<Duration> = sent[warmup..]
        .iter()
        .map(|s| s.duration_since(origin))
        .collect();
    let delays = match_onsets(&measured, &detected, period / 2);
    Ok(LatencyReport {
        sent: measured.len(),
        stats: LatencyStats::from_delays(&delays),
        delays,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn burst_frames(origin: Instant, onset_ms: &[u64], total_ms: u64) -> Vec<(Instant, Vec<i16>)> {
        (0..total_ms / 10)
            .map(|n| {
                let start_ms = n * 10;
                let samples = (0..480)
                    .map(|i| {
                        let t_ms = start_ms as f64 + i as f64 / 48.0;
                        let hit = onset_ms
                            .iter()
                            .any(|&o| t_ms >= o as f64 && t_ms < o as f64 + 20.0);
                        if hit {
                            16_000
                        } else {
                            0
                        }
                    })
                    .collect();
                (origin + Duration::from_millis(start_ms), samples)
            })
            .collect()
    }

    #[test]
    fn analysis_recovers_a_constant_delay() {
        let t0 = Instant::now();
        let sent: Vec<Instant> = (0..4)
            .map(|k| t0 + Duration::from_millis(1000 * k))
            .collect();
        // Every burst arrives 40 ms late.
        let frames = burst_frames(t0, &[40, 1040, 2040, 3040], 4200);
        let report = analyse(&sent, &frames, 1, Duration::from_secs(1)).unwrap();
        assert_eq!(report.sent, 3);
        assert_eq!(report.delays.len(), 3);
        let stats = report.stats.clone().unwrap();
        assert!(
            (stats.median.as_secs_f64() - 0.040).abs() < 0.001,
            "{stats:?}"
        );
        assert!(stats.jitter < Duration::from_millis(1));
        assert_eq!(report.lost(), 0);
    }

    #[test]
    fn missing_audio_is_reported_not_zero() {
        let t0 = Instant::now();
        let sent = vec![t0, t0 + Duration::from_secs(1)];
        let err = analyse(&sent, &[], 0, Duration::from_secs(1)).unwrap_err();
        assert!(err.to_string().contains("no audio"));
    }

    #[test]
    fn counts_lost_bursts() {
        let t0 = Instant::now();
        let sent: Vec<Instant> = (0..3)
            .map(|k| t0 + Duration::from_millis(1000 * k))
            .collect();
        let frames = burst_frames(t0, &[30, 2030], 3200);
        let report = analyse(&sent, &frames, 0, Duration::from_secs(1)).unwrap();
        assert_eq!(report.sent, 3);
        assert_eq!(report.lost(), 1);
    }
}
