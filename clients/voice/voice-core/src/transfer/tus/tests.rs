//! tus client against a tusd-like in-process server, including a connection cut in the middle of
//! a chunk (the server keeps the bytes that arrived, as tusd does).

use std::collections::HashMap;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use base64::Engine;
use serde_json::json;
use tokio::sync::watch;
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

use super::super::testutil::*;
use super::super::{default_retry_delays, RunState};
use super::*;

const TICKET: &str = "ticket-ok";

#[derive(Default)]
struct Up {
    data: Vec<u8>,
    length: u64,
    metadata: HashMap<String, String>,
}

#[derive(Default)]
struct TusState {
    uploads: Mutex<HashMap<String, Up>>,
    creates: AtomicUsize,
    heads: AtomicUsize,
    patch_count: AtomicUsize,
    patch_offsets: Mutex<Vec<u64>>,
    deleted: Mutex<Vec<String>>,
    /// (index of the PATCH, bytes of its body to accept, then stall instead of dropping)
    cut: Mutex<Option<(usize, usize, bool)>>,
    refuse_create: Mutex<Option<(u16, &'static str)>>,
    refuse_patch: Mutex<Option<u16>>,
    content_lengths_ok: AtomicUsize,
}

struct Tus {
    port: u16,
    st: Arc<TusState>,
}

fn parse_metadata(h: &str) -> HashMap<String, String> {
    h.split(',')
        .filter_map(|kv| kv.trim().split_once(' '))
        .map(|(k, v)| {
            let val = base64::engine::general_purpose::STANDARD
                .decode(v)
                .map(|b| String::from_utf8_lossy(&b).to_string())
                .unwrap_or_default();
            (k.to_owned(), val)
        })
        .collect()
}

async fn tus() -> Tus {
    let st = Arc::new(TusState::default());
    let st2 = st.clone();
    let port = serve(move |mut s| {
        let st = st2.clone();
        async move {
            let st_limit = st.clone();
            let Some(req) = read_request_with(&mut s, move |method, _| {
                if method != "PATCH" {
                    return None;
                }
                let idx = st_limit.patch_count.fetch_add(1, Ordering::SeqCst);
                match *st_limit.cut.lock().unwrap() {
                    Some((i, bytes, _)) if i == idx => Some(bytes),
                    _ => None,
                }
            })
            .await
            else {
                return;
            };
            let id = req.path.rsplit('/').next().unwrap_or("").to_owned();
            match req.method.as_str() {
                "POST" => {
                    st.creates.fetch_add(1, Ordering::SeqCst);
                    let refuse = *st.refuse_create.lock().unwrap();
                    if let Some((status, code)) = refuse {
                        let body = json!({"code": code, "message": "nein"}).to_string();
                        respond(
                            &mut s,
                            status,
                            &[("Content-Type", "application/json".into())],
                            body.as_bytes(),
                        )
                        .await;
                        return;
                    }
                    let md = parse_metadata(req.header("upload-metadata").unwrap_or(""));
                    if md.get("ticket").map(String::as_str) != Some(TICKET) {
                        respond(&mut s, 403, &[], br#"{"code":"ticket_invalid"}"#).await;
                        return;
                    }
                    let length: u64 = req.header("upload-length").unwrap().parse().unwrap();
                    let uid = format!("u{}", st.creates.load(Ordering::SeqCst));
                    st.uploads.lock().unwrap().insert(
                        uid.clone(),
                        Up {
                            data: vec![],
                            length,
                            metadata: md,
                        },
                    );
                    respond(
                        &mut s,
                        201,
                        &[
                            ("Location", format!("/upload/{uid}")),
                            ("Tus-Resumable", "1.0.0".into()),
                        ],
                        b"",
                    )
                    .await;
                }
                "HEAD" => {
                    st.heads.fetch_add(1, Ordering::SeqCst);
                    let found = st
                        .uploads
                        .lock()
                        .unwrap()
                        .get(&id)
                        .map(|u| (u.data.len(), u.length));
                    match found {
                        Some((len, total)) => {
                            let h = [
                                ("Upload-Offset", len.to_string()),
                                ("Upload-Length", total.to_string()),
                                ("Tus-Resumable", "1.0.0".into()),
                            ];
                            respond(&mut s, 200, &h, b"").await;
                        }
                        None => respond(&mut s, 404, &[], b"").await,
                    }
                }
                "PATCH" => {
                    if req.header("content-length").is_some()
                        && req.header("transfer-encoding").is_none()
                    {
                        st.content_lengths_ok.fetch_add(1, Ordering::SeqCst);
                    }
                    let offset: u64 = req.header("upload-offset").unwrap().parse().unwrap();
                    st.patch_offsets.lock().unwrap().push(offset);
                    let refuse = *st.refuse_patch.lock().unwrap();
                    if let Some(status) = refuse {
                        respond(&mut s, status, &[], b"").await;
                        return;
                    }
                    let outcome: Result<usize, u16> = {
                        let mut ups = st.uploads.lock().unwrap();
                        match ups.get_mut(&id) {
                            None => Err(404),
                            Some(u) if offset != u.data.len() as u64 => Err(409),
                            Some(u) => {
                                // Partial bodies are kept too: that makes the offset unaligned.
                                u.data.extend_from_slice(&req.body);
                                Ok(u.data.len())
                            }
                        }
                    };
                    let new_len = match outcome {
                        Ok(n) => n,
                        Err(code) => {
                            respond(&mut s, code, &[], b"").await;
                            return;
                        }
                    };
                    if !req.complete {
                        let stall = st.cut.lock().unwrap().is_some_and(|c| c.2);
                        if stall {
                            tokio::time::sleep(std::time::Duration::from_secs(30)).await;
                        }
                        return; // cut: no response, the connection drops
                    }
                    respond(
                        &mut s,
                        204,
                        &[
                            ("Upload-Offset", new_len.to_string()),
                            ("Tus-Resumable", "1.0.0".into()),
                        ],
                        b"",
                    )
                    .await;
                }
                "DELETE" => {
                    st.deleted.lock().unwrap().push(id.clone());
                    st.uploads.lock().unwrap().remove(&id);
                    respond(&mut s, 204, &[], b"").await;
                }
                _ => respond(&mut s, 405, &[], b"").await,
            }
        }
    })
    .await;
    Tus { port, st }
}

#[derive(Default)]
struct Rec {
    progress: Mutex<Vec<(u64, u64)>>,
    phases: Mutex<Vec<Phase>>,
}
impl Reporter for Rec {
    fn phase(&self, p: Phase) {
        self.phases.lock().unwrap().push(p);
    }
    fn progress(&self, d: u64, t: u64) {
        self.progress.lock().unwrap().push((d, t));
    }
}

struct Rig {
    tus: Tus,
    api_server: MockServer,
    dir: tempfile::TempDir,
    store: Arc<UploadStore>,
    content: Vec<u8>,
    spec: UploadSpec,
    tickets: Arc<AtomicUsize>,
}

async fn rig(size: usize, chunk: u64) -> Rig {
    let tus = tus().await;
    let api_server = MockServer::start().await;
    let tickets = Arc::new(AtomicUsize::new(0));
    let t2 = tickets.clone();
    let endpoint = format!("http://127.0.0.1:{}/upload/", tus.port);
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/events/e/files/uploads/ticket"))
        .respond_with(move |_: &wiremock::Request| {
            t2.fetch_add(1, Ordering::SeqCst);
            ResponseTemplate::new(200).set_body_json(json!({
                "endpoint": endpoint, "ticket": TICKET, "chunkSize": chunk,
                "expiresIn": 900, "name": "game.zip", "reservationId": 1
            }))
        })
        .mount(&api_server)
        .await;
    let dir = tempfile::tempdir().unwrap();
    let content = data(size);
    let file = dir.path().join("game.zip");
    std::fs::write(&file, &content).unwrap();
    let store = Arc::new(UploadStore::open(dir.path().join("uploads.json")));
    let spec = UploadSpec {
        event: "e".into(),
        path: file.canonicalize().unwrap(),
        name: "game.zip".into(),
        mime: Some("application/zip".into()),
        size: size as u64,
        mtime_secs: 1,
    };
    Rig {
        tus,
        api_server,
        dir,
        store,
        content,
        spec,
        tickets,
    }
}

impl Rig {
    fn api(&self) -> ApiClient {
        ApiClient::new(&self.api_server.uri())
            .unwrap()
            .with_token("t")
    }

    async fn run(&self, delays: &[Duration], rec: &Arc<Rec>) -> Result<UploadOutcome, Stop> {
        let (_tx, mut ctrl) = watch::channel(RunState::Running);
        let r: Arc<dyn Reporter> = rec.clone();
        upload(
            &self.api(),
            &reqwest::Client::new(),
            &self.spec,
            &self.store,
            delays,
            &r,
            &mut ctrl,
        )
        .await
    }

    fn server_data(&self) -> Vec<u8> {
        self.tus
            .st
            .uploads
            .lock()
            .unwrap()
            .values()
            .next()
            .map(|u| u.data.clone())
            .unwrap_or_default()
    }
}

fn fast() -> Vec<Duration> {
    vec![
        Duration::ZERO,
        Duration::from_millis(10),
        Duration::from_millis(10),
        Duration::from_millis(10),
    ]
}

#[tokio::test]
async fn uploads_in_ticket_sized_chunks_with_metadata_and_progress() {
    let r = rig(250_000, 100_000).await;
    let rec = Arc::new(Rec::default());
    let out = r.run(&fast(), &rec).await.unwrap();
    assert!(!out.resumed);
    assert_eq!(r.server_data(), r.content);
    let st = &r.tus.st;
    assert_eq!(st.creates.load(Ordering::SeqCst), 1);
    assert_eq!(
        st.patch_offsets.lock().unwrap().clone(),
        vec![0, 100_000, 200_000]
    );
    assert_eq!(
        st.content_lengths_ok.load(Ordering::SeqCst),
        3,
        "PATCH is length-delimited"
    );
    let md = st
        .uploads
        .lock()
        .unwrap()
        .values()
        .next()
        .unwrap()
        .metadata
        .clone();
    assert_eq!(md["filename"], "game.zip");
    assert_eq!(md["filetype"], "application/zip");
    assert_eq!(md["ticket"], TICKET);
    let prog = rec.progress.lock().unwrap().clone();
    assert_eq!(*prog.last().unwrap(), (250_000, 250_000));
    assert!(
        prog.iter().any(|p| p.0 > 0 && p.0 < 100_000),
        "progress inside a chunk"
    );
    assert!(r.store.is_empty(), "the fingerprint is removed on success");
    assert_eq!(r.tickets.load(Ordering::SeqCst), 1);
}

#[tokio::test]
async fn a_connection_cut_mid_chunk_resumes_from_the_servers_unaligned_offset() {
    let r = rig(250_000, 100_000).await;
    *r.tus.st.cut.lock().unwrap() = Some((1, 40_000, false));
    let rec = Arc::new(Rec::default());
    let out = r.run(&fast(), &rec).await.unwrap();
    assert!(out.resumed);
    assert_eq!(r.server_data(), r.content, "no byte lost or duplicated");
    let offsets = r.tus.st.patch_offsets.lock().unwrap().clone();
    assert_eq!(offsets, vec![0, 100_000, 140_000, 240_000], "{offsets:?}");
    assert!(r.tus.st.heads.load(Ordering::SeqCst) >= 1);
    assert_eq!(
        r.tus.st.creates.load(Ordering::SeqCst),
        1,
        "no new upload was created"
    );
    assert!(rec.phases.lock().unwrap().contains(&Phase::Reconnecting));
}

#[tokio::test]
async fn refusals_are_final_and_carry_the_servers_code() {
    // Ticket problems on creation.
    let r = rig(1_000, 500).await;
    *r.tus.st.refuse_create.lock().unwrap() = Some((403, "ticket_expired"));
    match r.run(&fast(), &Arc::new(Rec::default())).await {
        Err(Stop::Failed(e)) => assert_eq!(e.code(), Some("ticket_expired")),
        other => panic!("{other:?}"),
    }
    assert_eq!(r.tus.st.creates.load(Ordering::SeqCst), 1, "not retried");
    assert!(r.store.is_empty());

    // 413 and 507 on a chunk.
    for (status, code) in [
        (413u16, "too_large"),
        (507, "host_full"),
        (403, "forbidden"),
    ] {
        let r = rig(1_000, 500).await;
        *r.tus.st.refuse_patch.lock().unwrap() = Some(status);
        match r.run(&fast(), &Arc::new(Rec::default())).await {
            Err(Stop::Failed(e)) => assert_eq!(e.code(), Some(code), "{status}"),
            other => panic!("{other:?}"),
        }
        assert_eq!(
            r.tus.st.patch_offsets.lock().unwrap().len(),
            1,
            "{status}: not retried"
        );
        assert!(r.store.is_empty());
    }
}

#[tokio::test]
async fn a_ticket_error_from_the_app_stops_before_any_upload() {
    let r = rig(1_000, 500).await;
    r.api_server.reset().await;
    Mock::given(method("POST"))
        .and(path("/api/voice-client/v1/events/e/files/uploads/ticket"))
        .respond_with(
            ResponseTemplate::new(422).set_body_json(
                json!({"code": "quota_exceeded", "message": "Dein Speicher ist voll."}),
            ),
        )
        .mount(&r.api_server)
        .await;
    match r.run(&fast(), &Arc::new(Rec::default())).await {
        Err(Stop::Failed(e)) => {
            assert_eq!(e.code(), Some("quota_exceeded"));
            assert!(e.to_string().contains("Speicher"));
        }
        other => panic!("{other:?}"),
    }
    assert_eq!(r.tus.st.creates.load(Ordering::SeqCst), 0);
}

#[tokio::test]
async fn an_interrupted_upload_resumes_after_an_app_restart_via_the_fingerprint_file() {
    let r = rig(250_000, 100_000).await;
    *r.tus.st.cut.lock().unwrap() = Some((1, 40_000, false));
    // First "session": no retries allowed, so the cut ends it with a network failure.
    match r.run(&[], &Arc::new(Rec::default())).await {
        Err(Stop::Failed(e)) => assert_eq!(e.code(), Some("network")),
        other => panic!("{other:?}"),
    }
    assert_eq!(r.store.len(), 1, "the record stays for the resume");
    // "App restart": a new store instance over the same file.
    let store2 = Arc::new(UploadStore::open(r.dir.path().join("uploads.json")));
    assert_eq!(store2.len(), 1);
    let (_tx, mut ctrl) = watch::channel(RunState::Running);
    let rec: Arc<dyn Reporter> = Arc::new(Rec::default());
    let out = upload(
        &r.api(),
        &reqwest::Client::new(),
        &r.spec,
        &store2,
        &fast(),
        &rec,
        &mut ctrl,
    )
    .await
    .unwrap();
    assert!(out.resumed);
    assert_eq!(r.server_data(), r.content);
    assert_eq!(r.tus.st.creates.load(Ordering::SeqCst), 1);
    assert_eq!(
        r.tickets.load(Ordering::SeqCst),
        1,
        "no second ticket for a resume"
    );
    assert_eq!(
        r.tus
            .st
            .patch_offsets
            .lock()
            .unwrap()
            .iter()
            .filter(|o| **o == 140_000)
            .count(),
        1
    );
}

#[tokio::test]
async fn pause_keeps_the_upload_and_resume_continues_it() {
    let r = rig(250_000, 100_000).await;
    *r.tus.st.cut.lock().unwrap() = Some((1, 40_000, true)); // stalls mid-chunk
    let (tx, mut ctrl) = watch::channel(RunState::Running);
    let rec = Arc::new(Rec::default());
    let rep: Arc<dyn Reporter> = rec.clone();
    let api = r.api();
    let spec = r.spec.clone();
    let store = r.store.clone();
    let job = tokio::spawn(async move {
        upload(
            &api,
            &reqwest::Client::new(),
            &spec,
            &store,
            &fast(),
            &rep,
            &mut ctrl,
        )
        .await
    });
    for _ in 0..300 {
        if rec.progress.lock().unwrap().iter().any(|p| p.0 > 100_000) {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    tx.send(RunState::Paused).unwrap();
    assert!(matches!(job.await.unwrap(), Err(Stop::Paused)));
    assert_eq!(r.store.len(), 1);
    // Resume: no cut this time.
    *r.tus.st.cut.lock().unwrap() = None;
    let out = r.run(&fast(), &Arc::new(Rec::default())).await.unwrap();
    assert!(out.resumed);
    assert_eq!(r.server_data(), r.content);
    assert_eq!(r.tus.st.creates.load(Ordering::SeqCst), 1);
}

#[tokio::test]
async fn cancel_terminates_the_upload_on_the_host_and_forgets_it() {
    let r = rig(250_000, 100_000).await;
    *r.tus.st.cut.lock().unwrap() = Some((0, 20_000, true));
    let (tx, mut ctrl) = watch::channel(RunState::Running);
    let rec = Arc::new(Rec::default());
    let rep: Arc<dyn Reporter> = rec.clone();
    let (api, spec, store) = (r.api(), r.spec.clone(), r.store.clone());
    let job = tokio::spawn(async move {
        upload(
            &api,
            &reqwest::Client::new(),
            &spec,
            &store,
            &fast(),
            &rep,
            &mut ctrl,
        )
        .await
    });
    for _ in 0..300 {
        if rec.progress.lock().unwrap().iter().any(|p| p.0 > 0) {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    tx.send(RunState::Cancelled).unwrap();
    assert!(matches!(job.await.unwrap(), Err(Stop::Cancelled)));
    assert!(r.store.is_empty());
    assert_eq!(r.tus.st.deleted.lock().unwrap().len(), 1, "DELETE sent");
}

#[tokio::test]
async fn a_lost_record_falls_back_to_a_fresh_upload_and_empty_files_are_refused() {
    let r = rig(10_000, 4_000).await;
    // A record that points to an upload the server does not know.
    let key = fingerprint(&r.spec.event, &r.spec.path, r.spec.size, r.spec.mtime_secs);
    r.store.put(
        &key,
        Record {
            upload_url: format!("http://127.0.0.1:{}/upload/ghost", r.tus.port),
            chunk_size: 4_000,
            size: 10_000,
            name: "game.zip".into(),
            saved_at: now_secs(),
        },
    );
    let out = r.run(&fast(), &Arc::new(Rec::default())).await.unwrap();
    assert!(!out.resumed);
    assert_eq!(r.server_data(), r.content);
    assert_eq!(r.tus.st.creates.load(Ordering::SeqCst), 1);

    let mut empty = r.spec.clone();
    empty.size = 0;
    let (_tx, mut ctrl) = watch::channel(RunState::Running);
    let rep: Arc<dyn Reporter> = Arc::new(Rec::default());
    match upload(
        &r.api(),
        &reqwest::Client::new(),
        &empty,
        &r.store,
        &fast(),
        &rep,
        &mut ctrl,
    )
    .await
    {
        Err(Stop::Failed(e)) => assert_eq!(e.code(), Some("empty_file")),
        other => panic!("{other:?}"),
    }
}

#[test]
fn the_default_retry_schedule_matches_the_web_app() {
    let d: Vec<u64> = default_retry_delays().iter().map(|d| d.as_secs()).collect();
    assert_eq!(d, vec![0, 1, 3, 5, 10, 20, 30]);
}

#[test]
fn debug_output_of_the_ticket_hides_it() {
    let t = UploadTicket {
        endpoint: "https://h/upload/".into(),
        ticket: "secret-ticket".into(),
        ..Default::default()
    };
    assert!(!format!("{t:?}").contains("secret-ticket"));
}
