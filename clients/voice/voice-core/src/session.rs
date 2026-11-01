//! A joined LiveKit room: publishes one local audio track (when allowed), plays every remote
//! audio track into an [`AudioSink`], and exposes push-to-talk as mute/unmute.

use std::borrow::Cow;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex};

use futures_util::StreamExt;
use livekit::options::{AudioEncoding, TrackPublishOptions};
use livekit::prelude::*;
use livekit::webrtc::audio_frame::AudioFrame;
use livekit::webrtc::audio_source::native::NativeAudioSource;
use livekit::webrtc::audio_source::{AudioSourceOptions, RtcAudioSource};
use livekit::webrtc::audio_stream::native::NativeAudioStream;
use tokio::sync::mpsc;

use crate::api::{AudioSettings, JoinGrant};
use crate::audio::{
    AudioSink, AudioSource, CaptureFrame, PlaybackFrame, SourceGuard, FRAME_SAMPLES, SAMPLE_RATE,
};
use crate::error::{Error, Result};

/// What the session reports to its owner (the CLI prints these).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum SessionEvent {
    Connected {
        identity: String,
    },
    ParticipantJoined(String),
    ParticipantLeft(String),
    /// The display name a participant joined with (sent right after `ParticipantJoined`).
    ParticipantNamed {
        identity: String,
        name: String,
    },
    /// A participant muted or unmuted their microphone track.
    ParticipantMuted {
        identity: String,
        muted: bool,
    },
    /// Identities the SFU currently detects as speaking (our own included), loudest first.
    ActiveSpeakers(Vec<String>),
    /// Audio of this participant is now being played.
    RemoteAudio(String),
    Reconnecting,
    Reconnected,
    Disconnected(String),
}

#[derive(Debug, Clone)]
pub struct SessionOptions {
    /// Publish/encoder settings; usually straight from the server's `audio` object.
    pub audio: AudioSettings,
    /// Start transmitting immediately (open mic) instead of waiting for push-to-talk.
    pub start_talking: bool,
    /// Enable the SDK's echo cancellation, noise suppression and AGC as the server asks. The
    /// latency test turns this off to measure the bare pipeline, like the browser self-test.
    pub audio_processing: bool,
}

impl Default for SessionOptions {
    fn default() -> Self {
        Self {
            audio: AudioSettings::default(),
            start_talking: false,
            audio_processing: true,
        }
    }
}

pub struct Session {
    room: Arc<Room>,
    identity: String,
    talking: Arc<AtomicBool>,
    publication: Option<LocalTrackPublication>,
    events: Option<mpsc::UnboundedReceiver<SessionEvent>>,
    tasks: Vec<tokio::task::JoinHandle<()>>,
    _source_guard: Option<SourceGuard>,
}

impl Session {
    /// Connects with a grant from the API (or a dev token provider).
    ///
    /// `source` is the microphone; `None`, or a grant with `muted: true` (a moderator mute: the
    /// token has no publish right), makes the session listen-only.
    pub async fn connect(
        grant: &JoinGrant,
        source: Option<Box<dyn AudioSource>>,
        sink: Box<dyn AudioSink>,
        options: SessionOptions,
    ) -> Result<Session> {
        crate::install_crypto_provider();
        let (room, mut room_events) =
            Room::connect(&grant.url, &grant.token, RoomOptions::default())
                .await
                .map_err(|e| Error::Room(e.to_string()))?;
        let room = Arc::new(room);
        let (event_tx, event_rx) = mpsc::unbounded_channel();
        let identity = room.local_participant().identity().as_str().to_owned();
        let _ = event_tx.send(SessionEvent::Connected {
            identity: identity.clone(),
        });

        // Participants who were already in the room: no join event will ever arrive for them.
        for p in room.remote_participants().values() {
            let who = p.identity().as_str().to_owned();
            let _ = event_tx.send(SessionEvent::ParticipantJoined(who.clone()));
            let _ = event_tx.send(SessionEvent::ParticipantNamed {
                identity: who.clone(),
                name: p.name(),
            });
            for publication in p.track_publications().values() {
                if publication.kind() == TrackKind::Audio {
                    let _ = event_tx.send(SessionEvent::ParticipantMuted {
                        identity: who.clone(),
                        muted: publication.is_muted(),
                    });
                }
            }
        }

        let talking = Arc::new(AtomicBool::new(false));
        let mut tasks = Vec::new();
        let mut publication = None;
        let mut source_guard = None;

        if let (false, Some(source)) = (grant.muted, source) {
            let native = NativeAudioSource::new(
                AudioSourceOptions {
                    echo_cancellation: options.audio_processing && options.audio.echo_cancellation,
                    noise_suppression: options.audio_processing && options.audio.noise_suppression,
                    auto_gain_control: options.audio_processing && options.audio.auto_gain_control,
                },
                SAMPLE_RATE,
                1,
                // No internal queue: frames go straight to the encoder, lowest latency. This
                // requires exact 10 ms frames, which every AudioSource delivers.
                0,
            );
            let track = LocalAudioTrack::create_audio_track(
                "microphone",
                RtcAudioSource::Native(native.clone()),
            );
            let publish = TrackPublishOptions {
                source: TrackSource::Microphone,
                dtx: options.audio.dtx,
                red: options.audio.red,
                audio_encoding: Some(AudioEncoding {
                    max_bitrate: options.audio.bitrate,
                }),
                ..Default::default()
            };
            let publ = room
                .local_participant()
                .publish_track(LocalTrack::Audio(track), publish)
                .await
                .map_err(|e| Error::Room(format!("publishing the microphone failed: {e}")))?;
            talking.store(options.start_talking, Ordering::SeqCst);
            if !options.start_talking {
                publ.mute();
            }

            let (tx, rx) = mpsc::channel::<CaptureFrame>(32);
            source_guard = Some(source.start(tx)?);
            tasks.push(tokio::spawn(capture_pump(rx, native, talking.clone())));
            publication = Some(publ);
        }

        // Remote audio and room events.
        let sink = Arc::new(Mutex::new(sink));
        let next_stream = Arc::new(AtomicU32::new(0));
        let remote_tx = event_tx.clone();
        tasks.push(tokio::spawn(async move {
            while let Some(event) = room_events.recv().await {
                match event {
                    RoomEvent::ParticipantConnected(p) => {
                        let who = p.identity().as_str().to_owned();
                        let _ = remote_tx.send(SessionEvent::ParticipantJoined(who.clone()));
                        let _ = remote_tx.send(SessionEvent::ParticipantNamed {
                            identity: who,
                            name: p.name(),
                        });
                    }
                    RoomEvent::TrackMuted { participant, .. } => {
                        let _ = remote_tx.send(SessionEvent::ParticipantMuted {
                            identity: participant.identity().as_str().to_owned(),
                            muted: true,
                        });
                    }
                    RoomEvent::TrackUnmuted { participant, .. } => {
                        let _ = remote_tx.send(SessionEvent::ParticipantMuted {
                            identity: participant.identity().as_str().to_owned(),
                            muted: false,
                        });
                    }
                    RoomEvent::ActiveSpeakersChanged { speakers } => {
                        let _ = remote_tx.send(SessionEvent::ActiveSpeakers(
                            speakers
                                .iter()
                                .map(|s| s.identity().as_str().to_owned())
                                .collect(),
                        ));
                    }
                    RoomEvent::ParticipantDisconnected(p) => {
                        let _ = remote_tx.send(SessionEvent::ParticipantLeft(
                            p.identity().as_str().to_owned(),
                        ));
                    }
                    RoomEvent::TrackSubscribed {
                        track: RemoteTrack::Audio(track),
                        participant,
                        ..
                    } => {
                        let who = participant.identity().as_str().to_owned();
                        let _ = remote_tx.send(SessionEvent::RemoteAudio(who));
                        let stream_id = next_stream.fetch_add(1, Ordering::SeqCst);
                        tokio::spawn(play_track(track, stream_id, sink.clone()));
                    }
                    RoomEvent::Reconnecting => {
                        let _ = remote_tx.send(SessionEvent::Reconnecting);
                    }
                    RoomEvent::Reconnected => {
                        let _ = remote_tx.send(SessionEvent::Reconnected);
                    }
                    RoomEvent::Disconnected { reason } => {
                        let _ = remote_tx.send(SessionEvent::Disconnected(format!("{reason:?}")));
                        break;
                    }
                    _ => {}
                }
            }
        }));

        Ok(Session {
            room,
            identity,
            talking,
            publication,
            events: Some(event_rx),
            tasks,
            _source_guard: source_guard,
        })
    }

    /// The LiveKit identity this session joined with.
    pub fn identity(&self) -> &str {
        &self.identity
    }

    /// True when the session cannot transmit (moderator mute, or no microphone given).
    pub fn is_listen_only(&self) -> bool {
        self.publication.is_none()
    }

    pub fn is_talking(&self) -> bool {
        self.talking.load(Ordering::SeqCst)
    }

    /// Push-to-talk: unmute while held, mute on release. Idempotent. Errors when listen-only.
    pub fn set_talking(&self, talking: bool) -> std::result::Result<bool, String> {
        self.ptt_handle().set_talking(talking)
    }

    /// A cheap, thread-safe handle for the control socket to drive push-to-talk with.
    pub fn ptt_handle(&self) -> PttHandle {
        PttHandle {
            talking: self.talking.clone(),
            publication: self.publication.clone(),
        }
    }

    /// Hands the event stream to the owner. Returns `None` on the second call.
    pub fn take_events(&mut self) -> Option<mpsc::UnboundedReceiver<SessionEvent>> {
        self.events.take()
    }

    /// Leaves the room and stops all tasks.
    pub async fn close(self) {
        let _ = self.room.close().await;
        for t in self.tasks {
            t.abort();
        }
    }
}

/// Push-to-talk control of a session, cloneable and `Send + Sync`.
#[derive(Clone)]
pub struct PttHandle {
    talking: Arc<AtomicBool>,
    publication: Option<LocalTrackPublication>,
}

impl PttHandle {
    pub fn is_talking(&self) -> bool {
        self.talking.load(Ordering::SeqCst)
    }

    /// Unmute while held, mute on release. Idempotent. Errors when the session is listen-only.
    pub fn set_talking(&self, talking: bool) -> std::result::Result<bool, String> {
        let Some(publication) = &self.publication else {
            return Err(
                "this session is listen-only (muted by a moderator, or no microphone)".into(),
            );
        };
        self.talking.store(talking, Ordering::SeqCst);
        if talking {
            publication.unmute();
        } else {
            publication.mute();
        }
        Ok(talking)
    }
}

/// Moves captured frames into the encoder, but only while transmitting.
async fn capture_pump(
    mut rx: mpsc::Receiver<CaptureFrame>,
    native: NativeAudioSource,
    talking: Arc<AtomicBool>,
) {
    while let Some(frame) = rx.recv().await {
        if !talking.load(Ordering::SeqCst) || frame.samples.len() != FRAME_SAMPLES {
            continue;
        }
        let audio = AudioFrame {
            data: Cow::Owned(frame.samples),
            sample_rate: SAMPLE_RATE,
            num_channels: 1,
            samples_per_channel: FRAME_SAMPLES as u32,
        };
        if let Err(e) = native.capture_frame(&audio).await {
            tracing::warn!("capture_frame failed: {e}");
        }
    }
}

/// Pulls decoded 10 ms frames of one remote track into the sink until the track ends.
async fn play_track(track: RemoteAudioTrack, stream_id: u32, sink: Arc<Mutex<Box<dyn AudioSink>>>) {
    let mut stream = NativeAudioStream::new(track.rtc_track(), SAMPLE_RATE as i32, 1);
    while let Some(frame) = stream.next().await {
        let playback = PlaybackFrame {
            samples: frame.data.into_owned(),
            at: std::time::Instant::now(),
        };
        if let Ok(mut s) = sink.lock() {
            s.write(stream_id, &playback);
        }
    }
}
