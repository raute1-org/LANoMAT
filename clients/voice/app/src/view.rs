//! What the UI shows about the joined room, as a pure reducer over [`SessionEvent`]s so it can be
//! tested without a LiveKit server. The same snapshot is sent to the main window and the overlay.

use serde::Serialize;
use voice_core::session::SessionEvent;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Phase {
    /// Not in a room.
    Idle,
    Connecting,
    Connected,
    Reconnecting,
    /// The room connection ended unexpectedly; `error` says why.
    Failed,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct RoomRef {
    pub id: u64,
    pub name: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Participant {
    pub identity: String,
    pub name: String,
    pub speaking: bool,
    pub muted: bool,
    pub is_self: bool,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct RoomView {
    pub phase: Phase,
    pub room: Option<RoomRef>,
    /// Currently transmitting (push-to-talk held, or open mic).
    pub talking: bool,
    /// Hard mute from the tray or the mute button: nothing is transmitted until it is lifted.
    pub muted: bool,
    /// Moderator mute or no microphone: the session can only listen.
    pub listen_only: bool,
    pub open_mic: bool,
    pub participants: Vec<Participant>,
    pub error: Option<String>,
}

impl RoomView {
    pub fn idle() -> Self {
        Self {
            phase: Phase::Idle,
            room: None,
            talking: false,
            muted: false,
            listen_only: false,
            open_mic: false,
            participants: Vec::new(),
            error: None,
        }
    }

    pub fn connecting(room: RoomRef, muted: bool, open_mic: bool) -> Self {
        Self {
            phase: Phase::Connecting,
            room: Some(room),
            muted,
            open_mic,
            ..Self::idle()
        }
    }

    fn upsert(&mut self, identity: &str) -> &mut Participant {
        if let Some(i) = self
            .participants
            .iter()
            .position(|p| p.identity == identity)
        {
            return &mut self.participants[i];
        }
        self.participants.push(Participant {
            identity: identity.to_owned(),
            name: identity.to_owned(),
            speaking: false,
            muted: false,
            is_self: false,
        });
        self.participants.last_mut().expect("just pushed")
    }

    /// The local transmit state changed; mirrors it onto our own roster entry.
    pub fn set_talking(&mut self, talking: bool) {
        self.talking = talking;
        for p in self.participants.iter_mut().filter(|p| p.is_self) {
            p.muted = !talking;
        }
    }

    pub fn sort(&mut self) {
        self.participants
            .sort_by_cached_key(|p| (!p.is_self, p.name.to_lowercase()));
    }

    /// Applies one session event. Returns true when the view changed.
    pub fn apply(&mut self, event: &SessionEvent, my_name: &str) -> bool {
        match event {
            SessionEvent::Connected { identity } => {
                self.phase = Phase::Connected;
                self.error = None;
                let talking = self.talking;
                let me = self.upsert(identity);
                me.is_self = true;
                me.name = my_name.to_owned();
                me.muted = !talking;
            }
            SessionEvent::ParticipantJoined(who) => {
                self.upsert(who);
            }
            SessionEvent::ParticipantLeft(who) => {
                self.participants
                    .retain(|p| &p.identity != who || p.is_self);
            }
            SessionEvent::ParticipantNamed { identity, name } => {
                let p = self.upsert(identity);
                if !p.is_self && !name.is_empty() {
                    p.name = name.clone();
                }
            }
            SessionEvent::ParticipantMuted { identity, muted } => {
                let p = self.upsert(identity);
                if !p.is_self {
                    p.muted = *muted;
                }
            }
            SessionEvent::ActiveSpeakers(who) => {
                for p in &mut self.participants {
                    p.speaking = who.contains(&p.identity);
                }
            }
            SessionEvent::Reconnecting => self.phase = Phase::Reconnecting,
            SessionEvent::Reconnected => self.phase = Phase::Connected,
            SessionEvent::Disconnected(reason) => {
                self.phase = Phase::Failed;
                self.error = Some(reason.clone());
                for p in &mut self.participants {
                    p.speaking = false;
                }
            }
            SessionEvent::RemoteAudio(_) => return false,
        }
        self.sort();
        true
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn joined() -> RoomView {
        let mut v = RoomView::connecting(
            RoomRef {
                id: 1,
                name: "Lobby".into(),
            },
            false,
            false,
        );
        v.apply(
            &SessionEvent::Connected {
                identity: "u1".into(),
            },
            "Ich",
        );
        v
    }

    #[test]
    fn connect_marks_self_and_roster_follows_events() {
        let mut v = joined();
        assert_eq!(v.phase, Phase::Connected);
        assert!(v.participants[0].is_self);
        v.apply(&SessionEvent::ParticipantJoined("u2".into()), "Ich");
        v.apply(
            &SessionEvent::ParticipantNamed {
                identity: "u2".into(),
                name: "Anna".into(),
            },
            "Ich",
        );
        assert_eq!(v.participants.len(), 2);
        assert_eq!(v.participants[1].name, "Anna");
        v.apply(&SessionEvent::ParticipantLeft("u2".into()), "Ich");
        assert_eq!(v.participants.len(), 1);
        // We never remove ourselves through a leave event.
        v.apply(&SessionEvent::ParticipantLeft("u1".into()), "Ich");
        assert_eq!(v.participants.len(), 1);
    }

    #[test]
    fn speakers_and_mute_flags_are_tracked_and_cleared_on_disconnect() {
        let mut v = joined();
        v.apply(&SessionEvent::ParticipantJoined("u2".into()), "Ich");
        v.apply(&SessionEvent::ActiveSpeakers(vec!["u2".into()]), "Ich");
        assert!(v
            .participants
            .iter()
            .any(|p| p.identity == "u2" && p.speaking));
        v.apply(
            &SessionEvent::ParticipantMuted {
                identity: "u2".into(),
                muted: true,
            },
            "Ich",
        );
        assert!(v.participants.iter().any(|p| p.identity == "u2" && p.muted));
        v.apply(&SessionEvent::Disconnected("ClientInitiated".into()), "Ich");
        assert_eq!(v.phase, Phase::Failed);
        assert!(v.participants.iter().all(|p| !p.speaking));
        assert!(v.error.is_some());
    }

    #[test]
    fn own_mute_flag_mirrors_the_transmit_state_not_remote_events() {
        let mut v = joined();
        assert!(v.participants[0].muted);
        v.set_talking(true);
        assert!(!v.participants[0].muted);
        v.apply(
            &SessionEvent::ParticipantMuted {
                identity: "u1".into(),
                muted: true,
            },
            "Ich",
        );
        assert!(!v.participants[0].muted);
    }

    #[test]
    fn reconnect_cycle_and_remote_audio() {
        let mut v = joined();
        assert!(v.apply(&SessionEvent::Reconnecting, "Ich"));
        assert_eq!(v.phase, Phase::Reconnecting);
        assert!(v.apply(&SessionEvent::Reconnected, "Ich"));
        assert_eq!(v.phase, Phase::Connected);
        assert!(!v.apply(&SessionEvent::RemoteAudio("u2".into()), "Ich"));
    }
}
