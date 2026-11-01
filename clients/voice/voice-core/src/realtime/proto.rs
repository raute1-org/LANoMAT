//! The Pusher wire protocol (what Reverb speaks), as pure functions: parse a frame, build a frame,
//! map an event to a [`ChatEvent`](super::ChatEvent).

use std::time::Duration;

use serde_json::{json, Value};

use super::ChatEvent;
use crate::api::ChatMessage;

pub const DIRECTORY_CHANNEL: &str = "private-web-voice.directory";

pub fn room_channel(room_id: u64) -> String {
    format!("private-web-voice.channel.{room_id}")
}

/// The room id of `private-web-voice.channel.{id}`.
pub fn room_of_channel(channel: &str) -> Option<u64> {
    channel
        .strip_prefix("private-web-voice.channel.")?
        .parse()
        .ok()
}

/// One frame from the server.
#[derive(Debug, Clone, PartialEq)]
pub enum Incoming {
    ConnectionEstablished {
        socket_id: String,
        activity_timeout: Option<u64>,
    },
    SubscriptionSucceeded {
        channel: String,
    },
    Ping,
    Pong,
    Error {
        code: Option<u64>,
        message: String,
    },
    Event {
        channel: Option<String>,
        event: String,
        data: Value,
    },
}

/// Pusher sends `data` as a JSON *string* that has to be decoded a second time; some servers send
/// an object. Anything else (or undecodable text) is kept as it is.
fn decode_data(v: Option<&Value>) -> Value {
    match v {
        Some(Value::String(s)) => {
            serde_json::from_str(s).unwrap_or_else(|_| Value::String(s.clone()))
        }
        Some(other) => other.clone(),
        None => Value::Null,
    }
}

pub fn parse_frame(text: &str) -> Option<Incoming> {
    let v: Value = serde_json::from_str(text).ok()?;
    let event = v.get("event")?.as_str()?.to_owned();
    let channel = v.get("channel").and_then(Value::as_str).map(str::to_owned);
    let data = decode_data(v.get("data"));
    Some(match event.as_str() {
        "pusher:connection_established" => Incoming::ConnectionEstablished {
            socket_id: data.get("socket_id")?.as_str()?.to_owned(),
            activity_timeout: data.get("activity_timeout").and_then(Value::as_u64),
        },
        "pusher_internal:subscription_succeeded" => {
            Incoming::SubscriptionSucceeded { channel: channel? }
        }
        "pusher:ping" => Incoming::Ping,
        "pusher:pong" => Incoming::Pong,
        "pusher:error" => Incoming::Error {
            code: data.get("code").and_then(Value::as_u64),
            message: data
                .get("message")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
        },
        _ => Incoming::Event {
            channel,
            event,
            data,
        },
    })
}

pub fn subscribe_frame(channel: &str, auth: &str) -> String {
    json!({"event": "pusher:subscribe", "data": {"channel": channel, "auth": auth}}).to_string()
}

pub fn unsubscribe_frame(channel: &str) -> String {
    json!({"event": "pusher:unsubscribe", "data": {"channel": channel}}).to_string()
}

pub fn pong_frame() -> String {
    json!({"event": "pusher:pong", "data": {}}).to_string()
}

pub fn ping_frame() -> String {
    json!({"event": "pusher:ping", "data": {}}).to_string()
}

/// Maps an application event to a [`ChatEvent`]; unknown events are ignored.
pub fn to_chat_event(channel: Option<&str>, event: &str, data: &Value) -> Option<ChatEvent> {
    match event {
        "message.posted" => {
            let message: ChatMessage = serde_json::from_value(data.get("message")?.clone()).ok()?;
            Some(ChatEvent::MessagePosted {
                room_id: message.channel_id,
                message,
            })
        }
        "message.deleted" => {
            let room_id = data
                .get("channel_id")
                .and_then(Value::as_u64)
                .or_else(|| channel.and_then(room_of_channel))?;
            Some(ChatEvent::MessageDeleted {
                room_id,
                message_id: data.get("message_id")?.as_u64()?,
            })
        }
        "channels.changed" => Some(ChatEvent::ChannelsChanged),
        _ => None,
    }
}

/// Exponential backoff: `base * 2^attempt`, capped. `attempt` starts at 0.
pub fn backoff(attempt: u32, base: Duration, cap: Duration) -> Duration {
    let factor = 1u32.checked_shl(attempt.min(16)).unwrap_or(u32::MAX);
    base.saturating_mul(factor).min(cap)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn connection_established_decodes_the_nested_json_string() {
        let f = r#"{"event":"pusher:connection_established","data":"{\"socket_id\":\"123.456\",\"activity_timeout\":30}"}"#;
        assert_eq!(
            parse_frame(f),
            Some(Incoming::ConnectionEstablished {
                socket_id: "123.456".into(),
                activity_timeout: Some(30)
            })
        );
    }

    #[test]
    fn control_frames_and_errors() {
        assert_eq!(
            parse_frame(r#"{"event":"pusher:ping","data":"{}"}"#),
            Some(Incoming::Ping)
        );
        assert_eq!(
            parse_frame(r#"{"event":"pusher:pong"}"#),
            Some(Incoming::Pong)
        );
        assert_eq!(
            parse_frame(
                r#"{"event":"pusher_internal:subscription_succeeded","channel":"private-x","data":"{}"}"#
            ),
            Some(Incoming::SubscriptionSucceeded {
                channel: "private-x".into()
            })
        );
        assert_eq!(
            parse_frame(
                r#"{"event":"pusher:error","data":"{\"code\":4001,\"message\":\"nope\"}"}"#
            ),
            Some(Incoming::Error {
                code: Some(4001),
                message: "nope".into()
            })
        );
        assert_eq!(parse_frame("not json"), None);
        assert_eq!(parse_frame(r#"{"no_event":1}"#), None);
    }

    #[test]
    fn application_events_map_to_chat_events() {
        let posted = r#"{"event":"message.posted","channel":"private-web-voice.channel.3","data":"{\"message\":{\"id\":412,\"channel_id\":3,\"body\":\"gl hf\",\"created_at\":\"2026-11-02T12:00:00+00:00\",\"author\":{\"id\":7,\"name\":\"Ada\",\"avatar_url\":null}}}"}"#;
        let Some(Incoming::Event {
            channel,
            event,
            data,
        }) = parse_frame(posted)
        else {
            panic!()
        };
        match to_chat_event(channel.as_deref(), &event, &data) {
            Some(ChatEvent::MessagePosted { room_id, message }) => {
                assert_eq!(room_id, 3);
                assert_eq!(message.author.name, "Ada");
            }
            e => panic!("{e:?}"),
        }
        let del = json!({"channel_id": 3, "message_id": 9});
        assert_eq!(
            to_chat_event(Some("private-web-voice.channel.3"), "message.deleted", &del),
            Some(ChatEvent::MessageDeleted {
                room_id: 3,
                message_id: 9
            })
        );
        // The room can also come from the channel name.
        let del = json!({"message_id": 9});
        assert_eq!(
            to_chat_event(Some("private-web-voice.channel.5"), "message.deleted", &del),
            Some(ChatEvent::MessageDeleted {
                room_id: 5,
                message_id: 9
            })
        );
        assert_eq!(
            to_chat_event(Some(DIRECTORY_CHANNEL), "channels.changed", &Value::Null),
            Some(ChatEvent::ChannelsChanged)
        );
        assert_eq!(to_chat_event(None, "something.else", &Value::Null), None);
    }

    #[test]
    fn frames_we_send_are_valid_json_with_the_pusher_shape() {
        let s: Value = serde_json::from_str(&subscribe_frame("private-a", "k:s")).unwrap();
        assert_eq!(s["event"], "pusher:subscribe");
        assert_eq!(s["data"]["channel"], "private-a");
        assert_eq!(s["data"]["auth"], "k:s");
        let p: Value = serde_json::from_str(&pong_frame()).unwrap();
        assert_eq!(p["event"], "pusher:pong");
        assert_eq!(room_of_channel(&room_channel(14)), Some(14));
        assert_eq!(room_of_channel(DIRECTORY_CHANNEL), None);
    }

    #[test]
    fn backoff_doubles_and_caps() {
        let b = Duration::from_secs(1);
        let c = Duration::from_secs(30);
        let secs: Vec<u64> = (0..8).map(|a| backoff(a, b, c).as_secs()).collect();
        assert_eq!(secs, vec![1, 2, 4, 8, 16, 30, 30, 30]);
        assert_eq!(backoff(1000, b, c), c);
    }
}
