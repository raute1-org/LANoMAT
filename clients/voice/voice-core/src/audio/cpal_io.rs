//! Real audio devices through cpal. Host only: this needs sound hardware (or a sound server)
//! and is **not exercised by the container tests**; the pure parts (mixer, resampler) are.

use std::collections::{HashMap, VecDeque};
use std::sync::{Arc, Mutex};
use std::time::Instant;

use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};
use cpal::{FromSample, SampleFormat, SizedSample, StreamConfig};
use tokio::sync::mpsc;

use super::resample::{downmix_to_mono, f32_to_i16, i16_to_f32, PullResampler};
use super::{
    AudioSink, AudioSource, CaptureFrame, PlaybackFrame, SourceGuard, FRAME_SAMPLES, SAMPLE_RATE,
};
use crate::error::{Error, Result};

fn audio_err(context: &str, e: impl std::fmt::Display) -> Error {
    Error::Audio(format!("{context}: {e}"))
}

fn device_name(d: &cpal::Device) -> String {
    d.description()
        .map(|d| d.name().to_owned())
        .unwrap_or_else(|_| "<unnamed>".into())
}

/// Names of the available input and output devices, for `--input` / `--output` selection.
pub fn list_devices() -> Result<(Vec<String>, Vec<String>)> {
    let host = cpal::default_host();
    let inputs = host
        .input_devices()
        .map_err(|e| audio_err("listing input devices", e))?
        .map(|d| device_name(&d))
        .collect();
    let outputs = host
        .output_devices()
        .map_err(|e| audio_err("listing output devices", e))?
        .map(|d| device_name(&d))
        .collect();
    Ok((inputs, outputs))
}

fn pick_device(want_input: bool, name: Option<&str>) -> Result<cpal::Device> {
    let host = cpal::default_host();
    let kind = if want_input { "input" } else { "output" };
    match name {
        None => if want_input {
            host.default_input_device()
        } else {
            host.default_output_device()
        }
        .ok_or_else(|| Error::Audio(format!("no default {kind} device"))),
        Some(wanted) => {
            let devices: Vec<cpal::Device> = if want_input {
                host.input_devices()
                    .map_err(|e| audio_err("listing devices", e))?
                    .collect()
            } else {
                host.output_devices()
                    .map_err(|e| audio_err("listing devices", e))?
                    .collect()
            };
            devices
                .into_iter()
                .find(|d| {
                    device_name(d)
                        .to_lowercase()
                        .contains(&wanted.to_lowercase())
                })
                .ok_or_else(|| Error::Audio(format!("no {kind} device matching `{wanted}`")))
        }
    }
}

/// Runs a cpal stream on its own thread (a `Stream` is not `Send`); dropping the guard stops it.
struct StreamThread {
    _stop: std::sync::mpsc::Sender<()>,
}

fn spawn_stream_thread<F>(build: F) -> Result<StreamThread>
where
    F: FnOnce() -> Result<cpal::Stream> + Send + 'static,
{
    let (ready_tx, ready_rx) = std::sync::mpsc::channel::<Result<()>>();
    let (stop_tx, stop_rx) = std::sync::mpsc::channel::<()>();
    std::thread::Builder::new()
        .name("cpal-stream".into())
        .spawn(move || match build() {
            Ok(stream) => {
                let _ = ready_tx.send(Ok(()));
                // Blocks until the guard (the sender) is dropped, then drops the stream.
                let _ = stop_rx.recv();
                drop(stream);
            }
            Err(e) => {
                let _ = ready_tx.send(Err(e));
            }
        })
        .map_err(|e| audio_err("spawning the audio thread", e))?;
    ready_rx
        .recv()
        .map_err(|_| Error::Audio("audio thread died during start".into()))??;
    Ok(StreamThread { _stop: stop_tx })
}

fn log_stream_error(err: cpal::Error) {
    tracing::warn!("audio stream error: {err}");
}

// ---- capture ----

/// Microphone through cpal. Accepts any device rate/channel count and converts to 48 kHz mono.
pub struct CpalSource {
    device: Option<String>,
}

impl CpalSource {
    pub fn new(device: Option<String>) -> Self {
        Self { device }
    }
}

impl AudioSource for CpalSource {
    fn start(self: Box<Self>, tx: mpsc::Sender<CaptureFrame>) -> Result<SourceGuard> {
        let wanted = self.device;
        let thread = spawn_stream_thread(move || {
            let device = pick_device(true, wanted.as_deref())?;
            let supported = device
                .default_input_config()
                .map_err(|e| audio_err("input configuration", e))?;
            let format = supported.sample_format();
            let config: StreamConfig = supported.into();
            tracing::info!(
                "capture: {} ({} Hz, {} ch, {format})",
                device_name(&device),
                config.sample_rate,
                config.channels
            );
            let stream = match format {
                SampleFormat::F32 => build_input::<f32>(&device, config, tx)?,
                SampleFormat::I16 => build_input::<i16>(&device, config, tx)?,
                SampleFormat::U16 => build_input::<u16>(&device, config, tx)?,
                SampleFormat::I32 => build_input::<i32>(&device, config, tx)?,
                other => {
                    return Err(Error::Audio(format!(
                        "unsupported input sample format {other}"
                    )))
                }
            };
            stream
                .play()
                .map_err(|e| audio_err("starting capture", e))?;
            Ok(stream)
        })?;
        Ok(SourceGuard::new(thread))
    }
}

fn build_input<T>(
    device: &cpal::Device,
    config: StreamConfig,
    tx: mpsc::Sender<CaptureFrame>,
) -> Result<cpal::Stream>
where
    T: SizedSample,
    f32: FromSample<T>,
{
    let channels = usize::from(config.channels);
    let mut resampler = PullResampler::new(config.sample_rate, SAMPLE_RATE);
    let mut queue: VecDeque<f32> = VecDeque::new();
    let mut frame: Vec<i16> = Vec::with_capacity(FRAME_SAMPLES);
    device
        .build_input_stream(
            config,
            move |data: &[T], _| {
                let floats: Vec<f32> = data.iter().map(|s| s.to_sample::<f32>()).collect();
                queue.extend(downmix_to_mono(&floats, channels));
                while let Some(v) = resampler.pull(&mut queue) {
                    frame.push(f32_to_i16(v));
                    if frame.len() == FRAME_SAMPLES {
                        let samples =
                            std::mem::replace(&mut frame, Vec::with_capacity(FRAME_SAMPLES));
                        // A full channel drops the frame: never block the audio thread.
                        let _ = tx.try_send(CaptureFrame {
                            samples,
                            at: Instant::now(),
                        });
                    }
                }
            },
            log_stream_error,
            None,
        )
        .map_err(|e| audio_err("opening the input stream", e))
}

// ---- playback ----

/// Per-remote-stream queues of 48 kHz mono samples that are summed on read.
#[derive(Debug, Default)]
pub struct Mixer {
    queues: HashMap<u32, VecDeque<i16>>,
}

/// 200 ms: more than this means the device stalled, drop the oldest audio instead of lagging.
const MAX_QUEUED: usize = SAMPLE_RATE as usize / 5;

impl Mixer {
    pub fn push(&mut self, stream: u32, samples: &[i16]) {
        let q = self.queues.entry(stream).or_default();
        q.extend(samples);
        let excess = q.len().saturating_sub(MAX_QUEUED);
        q.drain(..excess);
    }

    /// Moves up to `max` mixed samples (the sum of all streams, saturating) into `out`;
    /// returns how many. Streams that ran dry contribute silence for the remainder.
    pub fn drain_into(&mut self, out: &mut VecDeque<f32>, max: usize) -> usize {
        let n = self
            .queues
            .values()
            .map(VecDeque::len)
            .max()
            .unwrap_or(0)
            .min(max);
        for _ in 0..n {
            let sum: i32 = self
                .queues
                .values_mut()
                .map(|q| i32::from(q.pop_front().unwrap_or(0)))
                .sum();
            out.push_back(i16_to_f32(
                sum.clamp(i32::from(i16::MIN), i32::from(i16::MAX)) as i16,
            ));
        }
        self.queues.retain(|_, q| !q.is_empty());
        n
    }
}

/// Speaker through cpal. Created lazily on first audio so a listen-only start does not grab the
/// device before anyone speaks.
pub struct CpalSink {
    mixer: Arc<Mutex<Mixer>>,
    _thread: StreamThread,
}

impl CpalSink {
    pub fn new(device: Option<String>) -> Result<Self> {
        let mixer = Arc::new(Mutex::new(Mixer::default()));
        let shared = mixer.clone();
        let thread = spawn_stream_thread(move || {
            let device = pick_device(false, device.as_deref())?;
            let supported = device
                .default_output_config()
                .map_err(|e| audio_err("output configuration", e))?;
            let format = supported.sample_format();
            let config: StreamConfig = supported.into();
            tracing::info!(
                "playback: {} ({} Hz, {} ch, {format})",
                device_name(&device),
                config.sample_rate,
                config.channels
            );
            let stream = match format {
                SampleFormat::F32 => build_output::<f32>(&device, config, shared)?,
                SampleFormat::I16 => build_output::<i16>(&device, config, shared)?,
                SampleFormat::U16 => build_output::<u16>(&device, config, shared)?,
                SampleFormat::I32 => build_output::<i32>(&device, config, shared)?,
                other => {
                    return Err(Error::Audio(format!(
                        "unsupported output sample format {other}"
                    )))
                }
            };
            stream
                .play()
                .map_err(|e| audio_err("starting playback", e))?;
            Ok(stream)
        })?;
        Ok(Self {
            mixer,
            _thread: thread,
        })
    }
}

fn build_output<T>(
    device: &cpal::Device,
    config: StreamConfig,
    mixer: Arc<Mutex<Mixer>>,
) -> Result<cpal::Stream>
where
    T: SizedSample + FromSample<f32>,
{
    let channels = usize::from(config.channels);
    let mut resampler = PullResampler::new(SAMPLE_RATE, config.sample_rate);
    let mut mixed: VecDeque<f32> = VecDeque::new();
    device
        .build_output_stream(
            config,
            move |data: &mut [T], _| {
                for frame in data.chunks_mut(channels) {
                    let value = loop {
                        if let Some(v) = resampler.pull(&mut mixed) {
                            break v;
                        }
                        let n = match mixer.lock() {
                            Ok(mut m) => m.drain_into(&mut mixed, FRAME_SAMPLES),
                            Err(_) => 0,
                        };
                        if n == 0 {
                            break 0.0;
                        }
                    };
                    let out: T = T::from_sample(value);
                    for s in frame.iter_mut() {
                        *s = out;
                    }
                }
            },
            log_stream_error,
            None,
        )
        .map_err(|e| audio_err("opening the output stream", e))
}

impl AudioSink for CpalSink {
    fn write(&mut self, stream: u32, frame: &PlaybackFrame) {
        if let Ok(mut m) = self.mixer.lock() {
            m.push(stream, &frame.samples);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mixer_sums_streams_and_saturates() {
        let mut m = Mixer::default();
        m.push(1, &[1000, i16::MAX]);
        m.push(2, &[2000, i16::MAX]);
        let mut out = VecDeque::new();
        assert_eq!(m.drain_into(&mut out, 10), 2);
        assert!((out[0] - i16_to_f32(3000)).abs() < 1e-6);
        assert!((out[1] - 1.0).abs() < 1e-6);
        // Fully drained queues are forgotten.
        assert_eq!(m.drain_into(&mut out, 10), 0);
    }

    #[test]
    fn mixer_pads_the_shorter_stream_with_silence() {
        let mut m = Mixer::default();
        m.push(1, &[100]);
        m.push(2, &[10, 20, 30]);
        let mut out = VecDeque::new();
        assert_eq!(m.drain_into(&mut out, 10), 3);
        assert!((out[2] - i16_to_f32(30)).abs() < 1e-6);
    }

    #[test]
    fn mixer_caps_queue_length() {
        let mut m = Mixer::default();
        m.push(1, &vec![1; MAX_QUEUED + 500]);
        let mut out = VecDeque::new();
        assert_eq!(m.drain_into(&mut out, usize::MAX), MAX_QUEUED);
    }
}
