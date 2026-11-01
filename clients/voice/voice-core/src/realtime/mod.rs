//! Realtime text chat: a Pusher-protocol (Reverb) client over WebSocket that authorises private
//! channels through the bearer API, reconnects with backoff, re-subscribes, and falls back to
//! polling `GET rooms/{room}/messages` when `realtime` is null or the socket cannot connect.
//!
//! The consumer sees only a stream of [`ChatEvent`]s. After a reconnect (and when the socket
//! comes back after a polling period) the client fetches what it missed, so the stream has no
//! gap; a message may arrive twice across a reconnect, so consumers de-duplicate by message id.

pub mod proto;

use std::collections::{BTreeSet, HashMap};
use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use serde::Serialize;
use serde_json::json;
use tokio::sync::{mpsc, watch};
use tokio::time::Instant;
use tokio_tungstenite::tungstenite::Message;
use tokio_util::sync::CancellationToken;

use crate::api::{ApiClient, ChatMessage, RealtimeConfig};
use crate::error::Error;
use proto::{Incoming, DIRECTORY_CHANNEL};

/// Where the realtime link stands. `Polling` means the REST fallback is active.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum LinkState {
    Connecting,
    Live,
    Reconnecting,
    Polling,
    /// Stopped for good (the token was rejected): log in again.
    Offline,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ChatEvent {
    MessagePosted {
        room_id: u64,
        message: ChatMessage,
    },
    MessageDeleted {
        room_id: u64,
        message_id: u64,
    },
    /// The room list changed: refetch `GET rooms`.
    ChannelsChanged,
    Status {
        state: LinkState,
        detail: Option<String>,
    },
}

#[derive(Debug, Clone)]
pub struct RealtimeOptions {
    /// Poll period while the fallback is active.
    pub poll_interval: Duration,
    pub backoff_base: Duration,
    pub backoff_cap: Duration,
    /// Failed connection attempts in a row before the polling fallback starts.
    pub fall_back_after: u32,
    pub connect_timeout: Duration,
    /// How long to wait for a pong after our own ping before the link counts as dead.
    pub pong_timeout: Duration,
    /// Granularity of the liveness check.
    pub tick: Duration,
}

impl Default for RealtimeOptions {
    fn default() -> Self {
        Self {
            poll_interval: Duration::from_secs(5),
            backoff_base: Duration::from_secs(1),
            backoff_cap: Duration::from_secs(30),
            fall_back_after: 2,
            connect_timeout: Duration::from_secs(10),
            pong_timeout: Duration::from_secs(20),
            tick: Duration::from_secs(1),
        }
    }
}

/// Controls a running realtime client. Dropping it stops the client.
pub struct RealtimeHandle {
    rooms: watch::Sender<BTreeSet<u64>>,
    cancel: CancellationToken,
}

impl RealtimeHandle {
    /// The rooms whose messages to follow (the directory channel is always followed). Replaces
    /// the previous set.
    pub fn set_rooms(&self, rooms: impl IntoIterator<Item = u64>) {
        let set: BTreeSet<u64> = rooms.into_iter().collect();
        self.rooms.send_if_modified(|cur| {
            if *cur == set {
                false
            } else {
                *cur = set;
                true
            }
        });
    }

    pub fn shutdown(&self) {
        self.cancel.cancel();
    }
}

impl Drop for RealtimeHandle {
    fn drop(&mut self) {
        self.cancel.cancel();
    }
}

/// Starts the client on the current tokio runtime. `realtime` is `config.realtime` (`None`: poll).
pub fn spawn(
    api: ApiClient,
    realtime: Option<RealtimeConfig>,
    opts: RealtimeOptions,
) -> (RealtimeHandle, mpsc::UnboundedReceiver<ChatEvent>) {
    crate::install_crypto_provider();
    let (tx, rx) = mpsc::unbounded_channel();
    let (rooms_tx, rooms_rx) = watch::channel(BTreeSet::new());
    let cancel = CancellationToken::new();
    let driver = Driver {
        api,
        cfg: realtime,
        opts,
        tx,
        rooms: rooms_rx,
        cancel: cancel.clone(),
        last_seen: HashMap::new(),
        known_rooms: None,
        state: None,
        fatal: false,
    };
    tokio::spawn(driver.run());
    (
        RealtimeHandle {
            rooms: rooms_tx,
            cancel,
        },
        rx,
    )
}

enum SessionEnd {
    Cancelled,
    Fatal,
    Dropped { was_live: bool },
}

struct Driver {
    api: ApiClient,
    cfg: Option<RealtimeConfig>,
    opts: RealtimeOptions,
    tx: mpsc::UnboundedSender<ChatEvent>,
    rooms: watch::Receiver<BTreeSet<u64>>,
    cancel: CancellationToken,
    last_seen: HashMap<u64, u64>,
    known_rooms: Option<Vec<u64>>,
    state: Option<LinkState>,
    fatal: bool,
}

fn ws_url(cfg: &RealtimeConfig) -> String {
    let scheme =
        if cfg.scheme.eq_ignore_ascii_case("https") || cfg.scheme.eq_ignore_ascii_case("wss") {
            "wss"
        } else {
            "ws"
        };
    let port = if cfg.port == 0 {
        String::new()
    } else {
        format!(":{}", cfg.port)
    };
    format!(
        "{scheme}://{}{port}/app/{}?protocol=7&client=lanomat-voice&version={}&flash=false",
        cfg.host,
        cfg.key,
        env!("CARGO_PKG_VERSION")
    )
}

impl Driver {
    fn emit(&self, e: ChatEvent) {
        let _ = self.tx.send(e);
    }

    fn set_state(&mut self, s: LinkState, detail: Option<String>) {
        if self.state != Some(s) {
            self.state = Some(s);
            self.emit(ChatEvent::Status { state: s, detail });
        }
    }

    async fn sleep(&self, d: Duration) -> bool {
        tokio::select! {
            _ = self.cancel.cancelled() => false,
            _ = tokio::time::sleep(d) => true,
        }
    }

    async fn run(mut self) {
        let Some(cfg) = self.cfg.clone() else {
            self.set_state(
                LinkState::Polling,
                Some("realtime is not configured on this server".into()),
            );
            self.poll_until(None).await;
            return;
        };
        self.set_state(LinkState::Connecting, None);
        let mut failures = 0u32;
        loop {
            match self.session(&cfg).await {
                SessionEnd::Cancelled => return,
                SessionEnd::Fatal => {
                    self.set_state(
                        LinkState::Offline,
                        Some("the access token was rejected".into()),
                    );
                    return;
                }
                SessionEnd::Dropped { was_live } => {
                    failures = if was_live { 1 } else { failures + 1 };
                }
            }
            let delay = proto::backoff(failures - 1, self.opts.backoff_base, self.opts.backoff_cap);
            if failures >= self.opts.fall_back_after {
                self.set_state(
                    LinkState::Polling,
                    Some("the live connection is unavailable, polling instead".into()),
                );
                self.poll_until(Some(Instant::now() + delay)).await;
            } else {
                self.set_state(LinkState::Reconnecting, None);
                self.sleep(delay).await;
            }
            if self.cancel.is_cancelled() || self.fatal {
                if self.fatal {
                    self.set_state(
                        LinkState::Offline,
                        Some("the access token was rejected".into()),
                    );
                }
                return;
            }
        }
    }

    /// Polls until `deadline` (forever when `None`).
    async fn poll_until(&mut self, deadline: Option<Instant>) {
        loop {
            self.poll_once().await;
            if self.fatal {
                self.set_state(
                    LinkState::Offline,
                    Some("the access token was rejected".into()),
                );
                return;
            }
            let mut wait = self.opts.poll_interval;
            if let Some(dl) = deadline {
                let left = dl.saturating_duration_since(Instant::now());
                if left.is_zero() {
                    return;
                }
                wait = wait.min(left);
            }
            if !self.sleep(wait).await {
                return;
            }
        }
    }

    async fn poll_once(&mut self) {
        self.catch_up().await;
        match self.api.rooms().await {
            Ok(rooms) => {
                let mut ids: Vec<u64> = rooms.iter().map(|r| r.id).collect();
                ids.sort_unstable();
                if self.known_rooms.as_ref().is_some_and(|k| *k != ids) {
                    self.emit(ChatEvent::ChannelsChanged);
                }
                self.known_rooms = Some(ids);
            }
            Err(Error::Unauthorized) => self.fatal = true,
            Err(_) => {}
        }
    }

    /// Fetches the latest page of every followed room and emits what is newer than what we saw.
    /// The first look at a room only sets the baseline (the UI loaded the history itself).
    async fn catch_up(&mut self) {
        let rooms: Vec<u64> = self.rooms.borrow().iter().copied().collect();
        for room in rooms {
            match self.api.messages(room, None).await {
                Ok(page) => {
                    let newest = page.messages.iter().map(|m| m.id).max();
                    match self.last_seen.get(&room).copied() {
                        None => {
                            if let Some(n) = newest {
                                self.last_seen.insert(room, n);
                            }
                        }
                        Some(seen) => {
                            for m in page.messages.into_iter().filter(|m| m.id > seen) {
                                self.note_seen(room, m.id);
                                self.emit(ChatEvent::MessagePosted {
                                    room_id: room,
                                    message: m,
                                });
                            }
                        }
                    }
                }
                Err(Error::Unauthorized) => {
                    self.fatal = true;
                    return;
                }
                Err(_) => {} // a room we may no longer see, or a hiccup: next round
            }
        }
    }

    fn note_seen(&mut self, room: u64, id: u64) {
        let e = self.last_seen.entry(room).or_insert(0);
        if id > *e {
            *e = id;
        }
    }

    /// Brings the subscriptions in line with the wanted rooms. `Err` ends the session.
    async fn reconcile<S>(
        &self,
        sink: &mut S,
        socket_id: &str,
        subscribed: &mut BTreeSet<String>,
        denied: &mut BTreeSet<String>,
        cfg: &RealtimeConfig,
    ) -> Result<(), SessionEnd>
    where
        S: futures_util::Sink<Message> + Unpin,
    {
        let mut wanted: BTreeSet<String> = BTreeSet::new();
        wanted.insert(DIRECTORY_CHANNEL.to_owned());
        wanted.extend(self.rooms.borrow().iter().map(|r| proto::room_channel(*r)));
        let endpoint = if cfg.auth_endpoint.is_empty() {
            "/api/voice-client/v1/broadcasting/auth"
        } else {
            cfg.auth_endpoint.as_str()
        };
        for ch in wanted.difference(&subscribed.clone()) {
            if denied.contains(ch) {
                continue;
            }
            // The socket id is new on every connect, so authorisation is never cached.
            let res: Result<AuthResp, Error> = self
                .api
                .post_json_abs(
                    endpoint,
                    json!({"socket_id": socket_id, "channel_name": ch}),
                )
                .await;
            match res {
                Ok(a) => {
                    sink.send(Message::text(proto::subscribe_frame(ch, &a.auth)))
                        .await
                        .map_err(|_| SessionEnd::Dropped { was_live: true })?;
                    subscribed.insert(ch.clone());
                }
                Err(Error::Unauthorized) => return Err(SessionEnd::Fatal),
                Err(Error::Forbidden(_) | Error::NotFound(_)) => {
                    tracing::debug!("not allowed to follow a channel; skipping it");
                    denied.insert(ch.clone());
                }
                Err(_) => return Err(SessionEnd::Dropped { was_live: true }),
            }
        }
        for ch in subscribed.clone().difference(&wanted) {
            sink.send(Message::text(proto::unsubscribe_frame(ch)))
                .await
                .map_err(|_| SessionEnd::Dropped { was_live: true })?;
            subscribed.remove(ch);
        }
        Ok(())
    }

    async fn session(&mut self, cfg: &RealtimeConfig) -> SessionEnd {
        let connect = tokio_tungstenite::connect_async(ws_url(cfg));
        let ws = tokio::select! {
            _ = self.cancel.cancelled() => return SessionEnd::Cancelled,
            r = tokio::time::timeout(self.opts.connect_timeout, connect) => match r {
                Ok(Ok((ws, _))) => ws,
                Ok(Err(e)) => {
                    // The error text can carry the URL; only the kind is useful.
                    tracing::debug!("realtime connect failed: {}", short(&e));
                    return SessionEnd::Dropped { was_live: false };
                }
                Err(_) => return SessionEnd::Dropped { was_live: false },
            },
        };
        let (mut sink, mut stream) = ws.split();

        // Wait for pusher:connection_established.
        let (socket_id, activity) = {
            let wait = async {
                while let Some(Ok(msg)) = stream.next().await {
                    if let Message::Text(t) = msg {
                        if let Some(Incoming::ConnectionEstablished {
                            socket_id,
                            activity_timeout,
                        }) = proto::parse_frame(t.as_str())
                        {
                            return Some((socket_id, activity_timeout));
                        }
                    }
                }
                None
            };
            tokio::select! {
                _ = self.cancel.cancelled() => return SessionEnd::Cancelled,
                r = tokio::time::timeout(self.opts.connect_timeout, wait) => match r {
                    Ok(Some(x)) => x,
                    _ => return SessionEnd::Dropped { was_live: false },
                },
            }
        };
        let activity = Duration::from_secs(activity.unwrap_or(30).max(1));

        let mut subscribed = BTreeSet::new();
        let mut denied = BTreeSet::new();
        if let Err(end) = self
            .reconcile(&mut sink, &socket_id, &mut subscribed, &mut denied, cfg)
            .await
        {
            return match end {
                SessionEnd::Dropped { .. } => SessionEnd::Dropped { was_live: false },
                other => other,
            };
        }
        self.set_state(LinkState::Live, None);
        self.catch_up().await;
        if self.fatal {
            return SessionEnd::Fatal;
        }

        let mut last_rx = Instant::now();
        let mut ping_sent: Option<Instant> = None;
        let mut ticker = tokio::time::interval(self.opts.tick);
        loop {
            tokio::select! {
                _ = self.cancel.cancelled() => {
                    let _ = sink.close().await;
                    return SessionEnd::Cancelled;
                }
                changed = self.rooms.changed() => {
                    if changed.is_err() {
                        return SessionEnd::Cancelled;
                    }
                    if let Err(end) = self.reconcile(&mut sink, &socket_id, &mut subscribed, &mut denied, cfg).await {
                        return end;
                    }
                    self.catch_up().await;
                }
                frame = stream.next() => {
                    let Some(Ok(msg)) = frame else {
                        return SessionEnd::Dropped { was_live: true };
                    };
                    last_rx = Instant::now();
                    ping_sent = None;
                    match msg {
                        Message::Text(t) => {
                            match proto::parse_frame(t.as_str()) {
                                Some(Incoming::Ping) => {
                                    if sink.send(Message::text(proto::pong_frame())).await.is_err() {
                                        return SessionEnd::Dropped { was_live: true };
                                    }
                                }
                                Some(Incoming::Error { code, message }) => {
                                    tracing::debug!("pusher error {code:?}: {message}");
                                    return SessionEnd::Dropped { was_live: true };
                                }
                                Some(Incoming::Event { channel, event, data }) => {
                                    if let Some(ev) = proto::to_chat_event(channel.as_deref(), &event, &data) {
                                        if let ChatEvent::MessagePosted { room_id, message } = &ev {
                                            self.note_seen(*room_id, message.id);
                                        }
                                        self.emit(ev);
                                    }
                                }
                                _ => {}
                            }
                        }
                        Message::Ping(p) => {
                            if sink.send(Message::Pong(p)).await.is_err() {
                                return SessionEnd::Dropped { was_live: true };
                            }
                        }
                        Message::Close(_) => return SessionEnd::Dropped { was_live: true },
                        _ => {}
                    }
                }
                _ = ticker.tick() => {
                    let now = Instant::now();
                    if let Some(sent) = ping_sent {
                        if now.duration_since(sent) > self.opts.pong_timeout {
                            tracing::debug!("realtime link is silent, reconnecting");
                            return SessionEnd::Dropped { was_live: true };
                        }
                    } else if now.duration_since(last_rx) > activity {
                        if sink.send(Message::text(proto::ping_frame())).await.is_err() {
                            return SessionEnd::Dropped { was_live: true };
                        }
                        ping_sent = Some(now);
                    }
                }
            }
        }
    }
}

#[derive(serde::Deserialize)]
struct AuthResp {
    auth: String,
}

/// A short, URL-free description of a WebSocket error for the debug log.
fn short(e: &tokio_tungstenite::tungstenite::Error) -> &'static str {
    use tokio_tungstenite::tungstenite::Error as E;
    match e {
        E::Io(_) => "i/o error",
        E::Tls(_) => "tls error",
        E::Http(_) | E::HttpFormat(_) => "http handshake error",
        E::Url(_) => "bad url",
        E::Protocol(_) => "protocol error",
        _ => "error",
    }
}

#[cfg(test)]
mod tests;
