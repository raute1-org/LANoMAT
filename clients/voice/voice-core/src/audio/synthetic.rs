//! Deterministic audio implementations for tests and the latency self-test: a source that emits
//! scheduled click bursts and a sink that records what arrives. No sound hardware involved.

use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use tokio::sync::mpsc;

use super::{
    AudioSink, AudioSource, CaptureFrame, PlaybackFrame, SourceGuard, FRAME_DURATION,
    FRAME_SAMPLES, SAMPLE_RATE,
};
use crate::error::Result;

/// Shape of the synthetic click train. The defaults mirror the browser self-test: 20 ms 1 kHz
/// bursts at amplitude 0.5, one per second, 3 warm-up bursts and 20 measured ones, each at a
/// random phase inside the 20 ms packet grid.
#[derive(Debug, Clone)]
pub struct ClickConfig {
    pub warmup: u32,
    pub measured: u32,
    pub period: Duration,
    pub burst: Duration,
    pub freq_hz: f32,
    pub amplitude: f32,
    /// Silence after the last burst so its tail can arrive before the stream ends.
    pub tail: Duration,
    /// Width of the random onset phase, in samples (20 ms at 48 kHz).
    pub phase_samples: usize,
    pub seed: u64,
}

impl Default for ClickConfig {
    fn default() -> Self {
        Self {
            warmup: 3,
            measured: 20,
            period: Duration::from_secs(1),
            burst: Duration::from_millis(20),
            freq_hz: 1000.0,
            amplitude: 0.5,
            tail: Duration::from_secs(1),
            phase_samples: 960,
            seed: 0x9E37_79B9_7F4A_7C15,
        }
    }
}

impl ClickConfig {
    pub fn total_bursts(&self) -> usize {
        (self.warmup + self.measured) as usize
    }
}

/// xorshift64*, enough for a phase offset; no `rand` dependency.
struct Rng(u64);

impl Rng {
    fn next(&mut self) -> u64 {
        let mut x = self.0;
        x ^= x >> 12;
        x ^= x << 25;
        x ^= x >> 27;
        self.0 = x;
        x.wrapping_mul(0x2545_F491_4F6C_DD1D)
    }
}

/// Instants at which burst onsets were handed to the audio pipeline (warm-up bursts included),
/// shared with the test harness.
#[derive(Debug, Clone, Default)]
pub struct ClickLog(Arc<Mutex<Vec<Instant>>>);

impl ClickLog {
    pub fn snapshot(&self) -> Vec<Instant> {
        self.0.lock().expect("click log poisoned").clone()
    }

    fn push(&self, at: Instant) {
        self.0.lock().expect("click log poisoned").push(at);
    }
}

pub struct ClickSource {
    config: ClickConfig,
    log: ClickLog,
}

impl ClickSource {
    pub fn new(config: ClickConfig) -> (Self, ClickLog) {
        let log = ClickLog::default();
        (
            Self {
                config,
                log: log.clone(),
            },
            log,
        )
    }

    /// Absolute sample index of every burst onset: `k * period + random phase`.
    fn onsets(config: &ClickConfig) -> Vec<usize> {
        let mut rng = Rng(config.seed | 1);
        let period = (config.period.as_secs_f64() * f64::from(SAMPLE_RATE)) as usize;
        (0..config.total_bursts())
            .map(|k| k * period + (rng.next() as usize) % config.phase_samples.max(1))
            .collect()
    }

    fn sample_at(config: &ClickConfig, onsets: &[usize], g: usize) -> i16 {
        let burst = (config.burst.as_secs_f64() * f64::from(SAMPLE_RATE)) as usize;
        // Onsets are ascending; find the last one at or before g.
        let idx = onsets.partition_point(|&o| o <= g);
        if idx == 0 {
            return 0;
        }
        let rel = g - onsets[idx - 1];
        if rel >= burst {
            return 0;
        }
        // Cosine start: the first sample is already at full amplitude, a hard click.
        let phase = 2.0 * std::f32::consts::PI * config.freq_hz * rel as f32 / SAMPLE_RATE as f32;
        (phase.cos() * config.amplitude * f32::from(i16::MAX)) as i16
    }
}

struct AbortOnDrop(tokio::task::JoinHandle<()>);

impl Drop for AbortOnDrop {
    fn drop(&mut self) {
        self.0.abort();
    }
}

impl AudioSource for ClickSource {
    fn start(self: Box<Self>, tx: mpsc::Sender<CaptureFrame>) -> Result<SourceGuard> {
        let ClickSource { config, log } = *self;
        let handle = tokio::spawn(async move {
            let onsets = ClickSource::onsets(&config);
            let total_samples = onsets.last().copied().unwrap_or(0)
                + (config.burst.as_secs_f64() * f64::from(SAMPLE_RATE)) as usize
                + (config.tail.as_secs_f64() * f64::from(SAMPLE_RATE)) as usize;
            let frames = total_samples.div_ceil(FRAME_SAMPLES);
            // Anchored schedule (start + n * 10 ms), so sleep jitter never accumulates.
            let start = tokio::time::Instant::now();
            for n in 0..frames {
                tokio::time::sleep_until(start + FRAME_DURATION * n as u32).await;
                let at = Instant::now();
                let base = n * FRAME_SAMPLES;
                let samples: Vec<i16> = (0..FRAME_SAMPLES)
                    .map(|i| ClickSource::sample_at(&config, &onsets, base + i))
                    .collect();
                for &o in onsets
                    .iter()
                    .filter(|&&o| o >= base && o < base + FRAME_SAMPLES)
                {
                    log.push(
                        at + Duration::from_secs_f64((o - base) as f64 / f64::from(SAMPLE_RATE)),
                    );
                }
                if tx.send(CaptureFrame { samples, at }).await.is_err() {
                    return;
                }
            }
        });
        Ok(SourceGuard::new(AbortOnDrop(handle)))
    }
}

/// Records every received frame for offline analysis.
#[derive(Debug, Clone, Default)]
pub struct Recording(Arc<Mutex<Vec<PlaybackFrame>>>);

impl Recording {
    pub fn frames(&self) -> Vec<PlaybackFrame> {
        self.0.lock().expect("recording poisoned").clone()
    }
}

pub struct RecordingSink {
    recording: Recording,
}

impl RecordingSink {
    pub fn new() -> (Self, Recording) {
        let recording = Recording::default();
        (
            Self {
                recording: recording.clone(),
            },
            recording,
        )
    }
}

impl AudioSink for RecordingSink {
    fn write(&mut self, _stream: u32, frame: &PlaybackFrame) {
        self.recording
            .0
            .lock()
            .expect("recording poisoned")
            .push(frame.clone());
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::latency::{match_onsets, LatencyStats, OnsetDetector};

    fn fast_config() -> ClickConfig {
        ClickConfig {
            warmup: 1,
            measured: 3,
            period: Duration::from_millis(100),
            tail: Duration::from_millis(100),
            ..ClickConfig::default()
        }
    }

    #[test]
    fn onsets_are_ascending_and_inside_their_period() {
        let c = fast_config();
        let onsets = ClickSource::onsets(&c);
        assert_eq!(onsets.len(), 4);
        let period = 4800;
        for (k, &o) in onsets.iter().enumerate() {
            assert!(o >= k * period && o < k * period + 960, "{k}: {o}");
        }
        // The seed makes the train reproducible.
        assert_eq!(onsets, ClickSource::onsets(&c));
    }

    #[test]
    fn burst_is_a_cosine_of_the_configured_amplitude() {
        let c = fast_config();
        let onsets = vec![100];
        assert_eq!(ClickSource::sample_at(&c, &onsets, 99), 0);
        let first = ClickSource::sample_at(&c, &onsets, 100);
        assert!((i32::from(first) - 16_383).abs() <= 1, "{first}");
        // 20 ms = 960 samples, then silence again.
        assert_ne!(ClickSource::sample_at(&c, &onsets, 100 + 959), 0);
        assert_eq!(ClickSource::sample_at(&c, &onsets, 100 + 960), 0);
    }

    #[tokio::test]
    async fn source_to_sink_loopback_measures_zero_delay() {
        let (source, log) = ClickSource::new(fast_config());
        let (tx, mut rx) = mpsc::channel(64);
        let _guard = Box::new(source).start(tx).unwrap();
        let (mut sink, recording) = RecordingSink::new();
        while let Some(f) = rx.recv().await {
            sink.write(
                0,
                &PlaybackFrame {
                    samples: f.samples,
                    at: f.at,
                },
            );
        }
        let sent = log.snapshot();
        assert_eq!(sent.len(), 4);
        let frames = recording.frames();
        assert!(!frames.is_empty());
        // One origin for both sides: the first frame's hand-over instant.
        let origin = frames[0].at;
        let mut det = OnsetDetector::new(3000, SAMPLE_RATE, Duration::from_millis(50));
        let mut detected = Vec::new();
        for f in &frames {
            let start = f.at.duration_since(origin);
            if let Some(at) = det.feed(&f.samples, start) {
                detected.push(at);
            }
        }
        let sent_rel: Vec<Duration> = sent.iter().map(|s| s.duration_since(origin)).collect();
        let delays = match_onsets(&sent_rel, &detected, Duration::from_millis(50));
        assert_eq!(delays.len(), 4, "detected {detected:?} for {sent_rel:?}");
        let stats = LatencyStats::from_delays(&delays).unwrap();
        assert!(stats.max < Duration::from_millis(1), "{stats:?}");
    }
}
