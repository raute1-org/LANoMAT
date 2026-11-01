//! The first-run audio check: a microphone level meter and a test tone, so a person can pick a
//! headset and hear that it works before joining a room. Plain Rust with no Tauri types: events go
//! out through an [`EventSink`], the audio devices are the same `AudioSource` / `AudioSink` seams
//! the sessions use (synthetic ones in headless runs).

use std::sync::{Arc, Mutex, MutexGuard};
use std::time::Duration;

use serde_json::json;
use tokio::sync::mpsc;
use voice_core::audio::cpal_io::{CpalSink, CpalSource};
use voice_core::audio::synthetic::{ClickConfig, ClickSource};
use voice_core::audio::{
    AudioSink, AudioSource, CaptureFrame, PlaybackFrame, SourceGuard, FRAME_DURATION,
    FRAME_SAMPLES, SAMPLE_RATE,
};

use crate::engine::{Engine, UiError};
use crate::features::EventSink;

/// Event with the current input level: `{level: 0.0 to 1.0}`.
pub const EVT_LEVEL: &str = "audio-level";

/// Frames (10 ms each) folded into one level event: about 16 events per second.
const FRAMES_PER_EVENT: usize = 6;
/// The test tone: 440 Hz for one second at a comfortable amplitude.
const TONE_HZ: f32 = 440.0;
const TONE_FRAMES: usize = 100;
const TONE_AMPLITUDE: f32 = 0.2;

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

/// Maps the RMS of one frame to a 0..1 meter position: silence at -60 dBFS and below is 0, full
/// scale is 1 (a logarithmic scale, like every level meter).
pub fn frame_level(samples: &[i16]) -> f32 {
    if samples.is_empty() {
        return 0.0;
    }
    let sum: f64 = samples.iter().map(|s| f64::from(*s).powi(2)).sum();
    let rms = (sum / samples.len() as f64).sqrt() / 32768.0;
    if rms <= 0.0 {
        return 0.0;
    }
    let db = 20.0 * rms.log10();
    (((db + 60.0) / 60.0).clamp(0.0, 1.0)) as f32
}

/// One 10 ms frame of the test tone starting at sample `offset`.
pub fn tone_frame(offset: usize) -> Vec<i16> {
    (0..FRAME_SAMPLES)
        .map(|i| {
            let t = (offset + i) as f32 / SAMPLE_RATE as f32;
            let v = (2.0 * std::f32::consts::PI * TONE_HZ * t).sin() * TONE_AMPLITUDE;
            (v * f32::from(i16::MAX)) as i16
        })
        .collect()
}

struct Run {
    _guard: SourceGuard,
    task: tokio::task::JoinHandle<()>,
}

impl Drop for Run {
    fn drop(&mut self) {
        self.task.abort();
    }
}

pub struct AudioCheck {
    engine: Arc<Engine>,
    emit: EventSink,
    synthetic: bool,
    run: Mutex<Option<Run>>,
}

impl AudioCheck {
    pub fn new(engine: Arc<Engine>, emit: EventSink, synthetic: bool) -> Arc<Self> {
        Arc::new(Self {
            engine,
            emit,
            synthetic,
            run: Mutex::new(None),
        })
    }

    fn refuse_in_room(&self) -> Result<(), UiError> {
        if self.engine.view().room.is_some() {
            return Err(UiError::new(
                "local",
                "Du bist gerade in einem Sprachraum. Verlasse ihn, dann kannst du das Mikrofon prüfen.",
            ));
        }
        Ok(())
    }

    /// Starts the meter on `input` (a device name or part of one; `None` is the system default).
    /// A second start replaces the first.
    pub fn start(&self, input: Option<String>) -> Result<(), UiError> {
        self.refuse_in_room()?;
        self.stop();
        let source: Box<dyn AudioSource> = if self.synthetic {
            let (tone, _log) = ClickSource::new(ClickConfig {
                warmup: 0,
                measured: 3_600,
                burst: Duration::from_millis(600),
                period: Duration::from_millis(900),
                freq_hz: 440.0,
                ..ClickConfig::default()
            });
            Box::new(tone)
        } else {
            Box::new(CpalSource::new(input))
        };
        let (tx, mut rx) = mpsc::channel::<CaptureFrame>(64);
        let guard = source
            .start(tx)
            .map_err(|e| UiError::new("local", crate::engine::german(&e)))?;
        let emit = self.emit.clone();
        let task = tokio::spawn(async move {
            let mut peak = 0.0_f32;
            let mut n = 0;
            while let Some(frame) = rx.recv().await {
                peak = peak.max(frame_level(&frame.samples));
                n += 1;
                if n == FRAMES_PER_EVENT {
                    emit(EVT_LEVEL, json!({ "level": peak }));
                    peak = 0.0;
                    n = 0;
                }
            }
        });
        *lock(&self.run) = Some(Run {
            _guard: guard,
            task,
        });
        Ok(())
    }

    pub fn stop(&self) {
        if lock(&self.run).take().is_some() {
            (self.emit)(EVT_LEVEL, json!({ "level": 0.0 }));
        }
    }

    /// Plays one second of tone on `output` and returns when it is over.
    pub async fn play_tone(&self, output: Option<String>) -> Result<(), UiError> {
        self.refuse_in_room()?;
        self.stop();
        let mut sink: Box<dyn AudioSink> = if self.synthetic {
            Box::new(voice_core::audio::NullSink)
        } else {
            let sink = tokio::task::spawn_blocking(move || CpalSink::new(output))
                .await
                .map_err(|e| UiError::new("local", e.to_string()))?
                .map_err(|e| UiError::new("local", crate::engine::german(&e)))?;
            Box::new(sink)
        };
        let mut tick = tokio::time::interval(FRAME_DURATION);
        for i in 0..TONE_FRAMES {
            tick.tick().await;
            sink.write(
                0,
                &PlaybackFrame {
                    samples: tone_frame(i * FRAME_SAMPLES),
                    at: std::time::Instant::now(),
                },
            );
        }
        // Let the device play out what is queued before the stream is closed.
        tokio::time::sleep(Duration::from_millis(250)).await;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn silence_is_zero_and_full_scale_is_one() {
        assert_eq!(frame_level(&[0; 480]), 0.0);
        assert_eq!(frame_level(&[]), 0.0);
        let loud = vec![i16::MAX; 480];
        assert!(frame_level(&loud) > 0.99);
    }

    #[test]
    fn the_meter_is_logarithmic() {
        // -6 dBFS is 0.9, -30 dBFS is 0.5, below -60 dBFS nothing shows.
        let half = vec![16384_i16; 480];
        assert!((frame_level(&half) - 0.9).abs() < 0.01);
        let quiet = vec![1035_i16; 480];
        assert!((frame_level(&quiet) - 0.5).abs() < 0.02);
        assert_eq!(frame_level(&[10; 480]), 0.0);
    }

    #[test]
    fn the_tone_frame_has_the_right_size_and_level() {
        let f = tone_frame(0);
        assert_eq!(f.len(), FRAME_SAMPLES);
        let peak = f.iter().map(|s| s.unsigned_abs()).max().unwrap();
        assert!(peak > 5_000 && peak < 7_000, "peak {peak}");
        // The phase continues across frames: the first sample of frame 1 follows frame 0.
        let g = tone_frame(FRAME_SAMPLES);
        assert!((i32::from(f[FRAME_SAMPLES - 1]) - i32::from(g[0])).abs() < 1_500);
    }
}
