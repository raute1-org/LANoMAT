//! Audio abstraction: where microphone samples come from and where received audio goes.
//!
//! Everything in the session speaks one format, 48 kHz mono signed 16-bit in 10 ms frames (the
//! frame size of WebRTC's audio pipeline). Real devices (`cpal_io`, feature `cpal-audio`) convert
//! to and from it; the synthetic implementations (`synthetic`) make the whole path testable in a
//! container without sound hardware.

pub mod resample;
pub mod synthetic;

#[cfg(feature = "cpal-audio")]
pub mod cpal_io;

use std::time::{Duration, Instant};

use tokio::sync::mpsc;

use crate::error::Result;

pub const SAMPLE_RATE: u32 = 48_000;
/// Samples per 10 ms mono frame.
pub const FRAME_SAMPLES: usize = 480;
pub const FRAME_DURATION: Duration = Duration::from_millis(10);

/// A captured 10 ms frame. `at` is when its first sample was handed over (see the latency note
/// in the README about the resulting up-to-10 ms quantisation).
#[derive(Debug, Clone)]
pub struct CaptureFrame {
    pub samples: Vec<i16>,
    pub at: Instant,
}

/// A 10 ms frame received from the room. `at` is when it was pulled from the receive pipeline.
#[derive(Debug, Clone)]
pub struct PlaybackFrame {
    pub samples: Vec<i16>,
    pub at: Instant,
}

/// Keeps a source running; dropping it stops capture.
pub struct SourceGuard(#[allow(dead_code)] Box<dyn Send>);

impl SourceGuard {
    pub fn new(inner: impl Send + 'static) -> Self {
        Self(Box::new(inner))
    }
}

pub trait AudioSource: Send + 'static {
    /// Starts delivering frames into `tx` (use `try_send`: a full channel drops the frame rather
    /// than blocking a real-time thread). Dropping the returned guard stops delivery.
    fn start(self: Box<Self>, tx: mpsc::Sender<CaptureFrame>) -> Result<SourceGuard>;
}

pub trait AudioSink: Send + 'static {
    /// Receives one frame of one remote audio stream. `stream` identifies the remote track so a
    /// mixing sink can keep them apart. Must not block.
    fn write(&mut self, stream: u32, frame: &PlaybackFrame);
}

/// A sink that drops everything (a transmit-only session).
#[derive(Debug, Default)]
pub struct NullSink;

impl AudioSink for NullSink {
    fn write(&mut self, _stream: u32, _frame: &PlaybackFrame) {}
}
