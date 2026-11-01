//! Runs downloads and uploads in the background with progress events, pause, resume and cancel.
//!
//! `status` of a transfer: `queued`, `preparing`, `active`, `reconnecting`, `paused`, `done`,
//! `error`, `cancelled`. At most two uploads and three downloads run at a time (the rest wait in
//! `queued`).

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};
use std::time::{Duration, Instant};

use serde::Serialize;
use tokio::sync::{watch, Semaphore};

use super::download::{download, part_path, DownloadSpec};
use super::store::{fingerprint, UploadStore};
use super::tus::{upload, UploadSpec};
use super::{interrupted, Ctrl, Phase, Reporter, RunState, Stop};
use crate::api::ApiClient;
use crate::error::{Error, Result};

const MIN_EMIT_GAP: Duration = Duration::from_millis(150);
const SPEED_WINDOW: Duration = Duration::from_millis(500);

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct TransferError {
    pub code: String,
    pub message: String,
    pub retryable: bool,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct TransferView {
    pub id: u64,
    /// `download` or `upload`.
    pub kind: &'static str,
    pub name: String,
    pub status: &'static str,
    pub bytes_done: u64,
    /// 0 while unknown.
    pub total: u64,
    /// Smoothed transfer speed in bytes per second.
    pub speed_bps: u64,
    pub eta_secs: Option<u64>,
    /// An interrupted transfer continued where it stopped.
    pub resumed: bool,
    /// Final path of a finished download.
    pub path: Option<String>,
    pub error: Option<TransferError>,
}

impl TransferView {
    fn terminal(&self) -> bool {
        matches!(self.status, "done" | "error" | "cancelled")
    }
}

pub type ViewSink = Arc<dyn Fn(&TransferView) + Send + Sync>;
/// Turns a core error into the text the person sees.
pub type Describe = Arc<dyn Fn(&Error) -> String + Send + Sync>;

#[derive(Clone)]
enum Job {
    Download {
        api: ApiClient,
        file_id: u64,
        name_hint: String,
        dir: PathBuf,
    },
    Upload {
        api: ApiClient,
        spec: UploadSpec,
    },
}

struct Entry {
    view: TransferView,
    job: Job,
    ctrl: watch::Sender<RunState>,
    /// Bumped on every (re)start; callbacks of an earlier run are ignored.
    generation: u64,
    speed: SpeedMeter,
    last_emit: Instant,
}

struct Inner {
    http: reqwest::Client,
    store: Arc<UploadStore>,
    sink: ViewSink,
    describe: Describe,
    delays: Vec<Duration>,
    entries: Mutex<BTreeMap<u64, Entry>>,
    next_id: AtomicU64,
    uploads: Arc<Semaphore>,
    downloads: Arc<Semaphore>,
    deny_dirs: Vec<PathBuf>,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

#[derive(Clone)]
pub struct TransferManager {
    inner: Arc<Inner>,
}

/// Smoothed speed from throttled samples (exponential moving average over >= 500 ms windows).
#[derive(Debug, Clone, Default)]
pub struct SpeedMeter {
    last: Option<(Instant, u64)>,
    bps: f64,
}

impl SpeedMeter {
    pub fn sample(&mut self, now: Instant, done: u64) -> u64 {
        match self.last {
            None => self.last = Some((now, done)),
            Some((t, d)) => {
                let dt = now.saturating_duration_since(t);
                if dt >= SPEED_WINDOW {
                    let instant = done.saturating_sub(d) as f64 / dt.as_secs_f64();
                    self.bps = if self.bps == 0.0 {
                        instant
                    } else {
                        self.bps * 0.7 + instant * 0.3
                    };
                    self.last = Some((now, done));
                }
            }
        }
        self.bps as u64
    }

    pub fn reset(&mut self) {
        *self = Self::default();
    }
}

pub fn eta(speed_bps: u64, done: u64, total: u64) -> Option<u64> {
    (speed_bps > 0 && total > done).then(|| (total - done).div_ceil(speed_bps))
}

/// A local file may be uploaded when it is a regular file outside the app's own config
/// directory (credentials, settings). Returns the canonical path, the size and the mtime.
pub fn check_upload_path(path: &Path, deny_dirs: &[PathBuf]) -> Result<(PathBuf, u64, u64)> {
    let canon = std::fs::canonicalize(path).map_err(|e| Error::io(path, e))?;
    let meta = std::fs::metadata(&canon).map_err(|e| Error::io(&canon, e))?;
    if !meta.is_file() {
        return Err(Error::Usage("Das ist keine Datei.".into()));
    }
    for d in deny_dirs {
        let d = std::fs::canonicalize(d).unwrap_or_else(|_| d.clone());
        if canon.starts_with(&d) {
            return Err(Error::Usage(
                "Diese Datei gehört zur App selbst und kann nicht hochgeladen werden.".into(),
            ));
        }
    }
    let mtime = meta
        .modified()
        .ok()
        .and_then(|m| m.duration_since(std::time::UNIX_EPOCH).ok())
        .map(|d| d.as_secs())
        .unwrap_or(0);
    Ok((canon, meta.len(), mtime))
}

impl TransferManager {
    pub fn new(
        store: Arc<UploadStore>,
        sink: ViewSink,
        describe: Describe,
        delays: Vec<Duration>,
        deny_dirs: Vec<PathBuf>,
    ) -> Result<Self> {
        // No total timeout (large files): only connecting and stalls are bounded.
        let http = reqwest::Client::builder()
            .user_agent(crate::buildinfo::user_agent())
            .connect_timeout(Duration::from_secs(10))
            .read_timeout(Duration::from_secs(60))
            .build()
            .map_err(|e| Error::Network(e.to_string()))?;
        Ok(Self {
            inner: Arc::new(Inner {
                http,
                store,
                sink,
                describe,
                delays,
                entries: Mutex::new(BTreeMap::new()),
                next_id: AtomicU64::new(1),
                uploads: Arc::new(Semaphore::new(2)),
                downloads: Arc::new(Semaphore::new(3)),
                deny_dirs,
            }),
        })
    }

    pub fn list(&self) -> Vec<TransferView> {
        lock(&self.inner.entries)
            .values()
            .map(|e| e.view.clone())
            .collect()
    }

    pub fn get(&self, id: u64) -> Option<TransferView> {
        lock(&self.inner.entries).get(&id).map(|e| e.view.clone())
    }

    fn add(&self, name: String, job: Job, total: u64) -> u64 {
        let id = self.inner.next_id.fetch_add(1, Ordering::SeqCst);
        let kind = match job {
            Job::Download { .. } => "download",
            Job::Upload { .. } => "upload",
        };
        let (ctrl, _rx) = watch::channel(RunState::Running);
        let view = TransferView {
            id,
            kind,
            name,
            status: "queued",
            bytes_done: 0,
            total,
            speed_bps: 0,
            eta_secs: None,
            resumed: false,
            path: None,
            error: None,
        };
        (self.inner.sink)(&view);
        lock(&self.inner.entries).insert(
            id,
            Entry {
                view,
                job,
                ctrl,
                generation: 0,
                speed: SpeedMeter::default(),
                last_emit: Instant::now(),
            },
        );
        self.spawn_run(id);
        id
    }

    /// Starts downloading a shared file into `dir`. The signed link is requested when the job
    /// starts (and again on every resume, as the link is short lived).
    pub fn start_download(
        &self,
        api: ApiClient,
        file_id: u64,
        name_hint: String,
        dir: PathBuf,
    ) -> u64 {
        let name = super::sanitize_filename(&name_hint);
        self.add(
            name,
            Job::Download {
                api,
                file_id,
                name_hint,
                dir,
            },
            0,
        )
    }

    /// Starts uploading a local file. The path is checked first (see [`check_upload_path`]).
    pub fn start_upload(&self, api: ApiClient, event: String, path: &Path) -> Result<u64> {
        let (canon, size, mtime) = check_upload_path(path, &self.inner.deny_dirs)?;
        let name = canon
            .file_name()
            .map(|n| n.to_string_lossy().to_string())
            .unwrap_or_else(|| "upload".into());
        let spec = UploadSpec {
            event,
            path: canon,
            name: name.clone(),
            mime: None,
            size,
            mtime_secs: mtime,
        };
        Ok(self.add(name, Job::Upload { api, spec }, size))
    }

    fn running(view: &TransferView) -> bool {
        matches!(
            view.status,
            "queued" | "preparing" | "active" | "reconnecting"
        )
    }

    /// Pauses a running transfer (the partial data is kept).
    pub fn pause(&self, id: u64) -> Result<()> {
        let g = lock(&self.inner.entries);
        let e = g
            .get(&id)
            .ok_or_else(|| Error::NotFound("transfer".into()))?;
        if Self::running(&e.view) {
            let _ = e.ctrl.send(RunState::Paused);
        }
        Ok(())
    }

    /// Continues a paused transfer, or retries one that failed for a retryable reason.
    pub fn resume(&self, id: u64) -> Result<()> {
        {
            let mut g = lock(&self.inner.entries);
            let e = g
                .get_mut(&id)
                .ok_or_else(|| Error::NotFound("transfer".into()))?;
            let retry =
                e.view.status == "error" && e.view.error.as_ref().is_some_and(|x| x.retryable);
            if e.view.status != "paused" && !retry {
                return Ok(());
            }
            let (ctrl, _rx) = watch::channel(RunState::Running);
            e.ctrl = ctrl;
            e.view.status = "queued";
            e.view.error = None;
            e.speed.reset();
            e.view.speed_bps = 0;
            e.view.eta_secs = None;
            (self.inner.sink)(&e.view);
        }
        self.spawn_run(id);
        Ok(())
    }

    /// Cancels a transfer: a download's partial file is deleted, an upload is terminated on the
    /// host and its fingerprint forgotten.
    pub fn cancel(&self, id: u64) -> Result<()> {
        let (running, job, name) = {
            let g = lock(&self.inner.entries);
            let e = g
                .get(&id)
                .ok_or_else(|| Error::NotFound("transfer".into()))?;
            if e.view.terminal() {
                return Ok(());
            }
            let running = Self::running(&e.view);
            if running {
                let _ = e.ctrl.send(RunState::Cancelled);
            }
            (running, e.job.clone(), e.view.name.clone())
        };
        if running {
            return Ok(()); // the running job cleans up and reports `cancelled`
        }
        // Paused (or failed): nothing is running, clean up here.
        match &job {
            Job::Download { dir, .. } => {
                let _ = std::fs::remove_file(part_path(dir, &name));
            }
            Job::Upload { spec, .. } => {
                let key = fingerprint(&spec.event, &spec.path, spec.size, spec.mtime_secs);
                if let Some(rec) = self.inner.store.get(&key) {
                    self.inner.store.remove(&key);
                    let http = self.inner.http.clone();
                    tokio::spawn(async move {
                        let _ = http
                            .delete(&rec.upload_url)
                            .header("Tus-Resumable", "1.0.0")
                            .timeout(Duration::from_secs(5))
                            .send()
                            .await;
                    });
                }
            }
        }
        self.finish_with(id, None, Err(Stop::Cancelled));
        Ok(())
    }

    /// Drops a finished transfer from the list.
    pub fn forget(&self, id: u64) {
        let mut g = lock(&self.inner.entries);
        if g.get(&id).is_some_and(|e| e.view.terminal()) {
            g.remove(&id);
        }
    }

    fn spawn_run(&self, id: u64) {
        let inner = self.inner.clone();
        let generation = {
            let mut g = lock(&inner.entries);
            let Some(e) = g.get_mut(&id) else { return };
            e.generation += 1;
            e.generation
        };
        tokio::spawn(run(inner, id, generation));
    }

    fn finish_with(&self, id: u64, path: Option<PathBuf>, res: std::result::Result<bool, Stop>) {
        finish(&self.inner, id, None, path, res);
    }
}

struct EntryReporter {
    inner: Arc<Inner>,
    id: u64,
    generation: u64,
}

impl EntryReporter {
    fn with_entry(&self, f: impl FnOnce(&mut Entry)) {
        let mut g = lock(&self.inner.entries);
        if let Some(e) = g.get_mut(&self.id) {
            if e.generation == self.generation && !e.view.terminal() && e.view.status != "paused" {
                f(e);
            }
        }
    }
}

impl Reporter for EntryReporter {
    fn phase(&self, phase: Phase) {
        self.with_entry(|e| {
            e.view.status = match phase {
                Phase::Preparing => "preparing",
                Phase::Active => "active",
                Phase::Reconnecting => "reconnecting",
            };
            e.last_emit = Instant::now();
            (self.inner.sink)(&e.view);
        });
    }

    fn progress(&self, done: u64, total: u64) {
        self.with_entry(|e| {
            let now = Instant::now();
            e.view.bytes_done = done;
            if total > 0 {
                e.view.total = total;
            }
            e.view.speed_bps = e.speed.sample(now, done);
            e.view.eta_secs = eta(e.view.speed_bps, done, e.view.total);
            if now.duration_since(e.last_emit) >= MIN_EMIT_GAP {
                e.last_emit = now;
                (self.inner.sink)(&e.view);
            }
        });
    }
}

async fn run(inner: Arc<Inner>, id: u64, generation: u64) {
    let (job, mut ctrl): (Job, Ctrl) = {
        let g = lock(&inner.entries);
        let Some(e) = g.get(&id) else { return };
        (e.job.clone(), e.ctrl.subscribe())
    };
    let sem = match job {
        Job::Download { .. } => inner.downloads.clone(),
        Job::Upload { .. } => inner.uploads.clone(),
    };
    let permit = tokio::select! {
        stop = interrupted(&mut ctrl) => {
            finish(&inner, id, Some(generation), None, Err(stop));
            return;
        }
        p = sem.acquire_owned() => p,
    };
    let Ok(_permit) = permit else { return };
    let rep = Arc::new(EntryReporter {
        inner: inner.clone(),
        id,
        generation,
    });
    rep.phase(Phase::Preparing);
    let rep_dyn: Arc<dyn Reporter> = rep.clone();

    let result: std::result::Result<(Option<PathBuf>, bool), Stop> = match job {
        Job::Download {
            api,
            file_id,
            name_hint,
            dir,
        } => {
            let link = tokio::select! {
                stop = interrupted(&mut ctrl) => Err(stop),
                l = api.file_download_link(file_id) => l.map_err(Stop::Failed),
            };
            match link {
                Err(s) => Err(s),
                Ok(link) => {
                    let name = if link.name.is_empty() {
                        name_hint
                    } else {
                        link.name.clone()
                    };
                    {
                        let mut g = lock(&inner.entries);
                        if let Some(e) = g.get_mut(&id) {
                            e.view.name = super::sanitize_filename(&name);
                            if let Some(s) = link.size_bytes {
                                e.view.total = s;
                            }
                        }
                    }
                    let bearer = link
                        .bearer_required
                        .then(|| api.bearer_token().map(str::to_owned))
                        .flatten();
                    let spec = DownloadSpec {
                        url: link.url,
                        name,
                        expected_size: link.size_bytes,
                        bearer,
                        dir,
                    };
                    download(
                        &inner.http,
                        &spec,
                        &inner.delays,
                        rep_dyn.as_ref(),
                        &mut ctrl,
                    )
                    .await
                    .map(|p| (Some(p), false))
                }
            }
        }
        Job::Upload { api, spec } => upload(
            &api,
            &inner.http,
            &spec,
            &inner.store,
            &inner.delays,
            &rep_dyn,
            &mut ctrl,
        )
        .await
        .map(|o| (None, o.resumed)),
    };
    match result {
        Ok((path, resumed)) => finish(&inner, id, Some(generation), path, Ok(resumed)),
        Err(stop) => finish(&inner, id, Some(generation), None, Err(stop)),
    }
}

fn finish(
    inner: &Arc<Inner>,
    id: u64,
    generation: Option<u64>,
    path: Option<PathBuf>,
    res: std::result::Result<bool, Stop>,
) {
    let mut g = lock(&inner.entries);
    let Some(e) = g.get_mut(&id) else { return };
    if generation.is_some_and(|gen| gen != e.generation) {
        return;
    }
    match res {
        Ok(resumed) => {
            e.view.status = "done";
            e.view.resumed |= resumed;
            if e.view.total > 0 {
                e.view.bytes_done = e.view.total;
            }
            e.view.path = path.map(|p| p.display().to_string());
            e.view.error = None;
        }
        Err(Stop::Paused) => e.view.status = "paused",
        Err(Stop::Cancelled) => e.view.status = "cancelled",
        Err(Stop::Failed(err)) => {
            e.view.status = "error";
            e.view.error = Some(TransferError {
                code: err.code().unwrap_or("error").to_owned(),
                message: (inner.describe)(&err),
                retryable: err.retryable(),
            });
        }
    }
    e.view.speed_bps = 0;
    e.view.eta_secs = None;
    (inner.sink)(&e.view);
}

#[cfg(test)]
mod tests {
    use std::sync::atomic::AtomicUsize;

    use serde_json::json;
    use tokio::io::AsyncWriteExt;
    use wiremock::matchers::{method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    use super::super::testutil::*;
    use super::*;

    struct Rig {
        mgr: TransferManager,
        views: Arc<Mutex<Vec<TransferView>>>,
        api: ApiClient,
        _server: MockServer,
        dir: tempfile::TempDir,
    }

    async fn rig(link_for: impl Fn(u16) -> serde_json::Value, host_port: u16) -> Rig {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/files/21/download"))
            .respond_with(ResponseTemplate::new(200).set_body_json(link_for(host_port)))
            .mount(&server)
            .await;
        let dir = tempfile::tempdir().unwrap();
        let views: Arc<Mutex<Vec<TransferView>>> = Arc::default();
        let v2 = views.clone();
        let mgr = TransferManager::new(
            Arc::new(UploadStore::open(dir.path().join("uploads.json"))),
            Arc::new(move |v| v2.lock().unwrap().push(v.clone())),
            Arc::new(|e| e.to_string()),
            vec![Duration::ZERO, Duration::from_millis(10)],
            vec![dir.path().join("cfg")],
        )
        .unwrap();
        let api = ApiClient::new(&server.uri()).unwrap().with_token("t");
        Rig {
            mgr,
            views,
            api,
            _server: server,
            dir,
        }
    }

    async fn wait_status(r: &Rig, id: u64, status: &str) -> TransferView {
        for _ in 0..400 {
            if let Some(v) = r.mgr.get(id) {
                if v.status == status {
                    return v;
                }
            }
            tokio::time::sleep(Duration::from_millis(25)).await;
        }
        panic!("transfer {id} never reached {status}: {:?}", r.mgr.get(id));
    }

    fn link(port: u16) -> serde_json::Value {
        json!({"url": format!("http://127.0.0.1:{port}/dl/x"), "name": "maps.zip",
               "size_bytes": 200000, "expires_at": "2026-11-06T18:05:00+00:00"})
    }

    #[tokio::test]
    async fn a_download_runs_to_done_with_ordered_events_and_the_final_path() {
        let content = data(200_000);
        let c2 = content.clone();
        let port = serve(move |mut s| {
            let c = c2.clone();
            async move {
                let _ = read_request(&mut s, None).await;
                respond(&mut s, 200, &[], &c).await;
            }
        })
        .await;
        let r = rig(link, port).await;
        let target = r.dir.path().join("dl");
        let id = r
            .mgr
            .start_download(r.api.clone(), 21, "hint.bin".into(), target.clone());
        let v = wait_status(&r, id, "done").await;
        assert_eq!(v.kind, "download");
        assert_eq!(v.name, "maps.zip", "the server's name wins over the hint");
        assert_eq!(
            v.path.as_deref(),
            Some(target.join("maps.zip").to_str().unwrap())
        );
        assert_eq!(std::fs::read(target.join("maps.zip")).unwrap(), content);
        assert_eq!(v.bytes_done, 200_000);
        let seq: Vec<&str> = r.views.lock().unwrap().iter().map(|v| v.status).collect();
        assert_eq!(seq[0], "queued");
        assert_eq!(*seq.last().unwrap(), "done");
        assert!(seq.contains(&"preparing"));
        assert!(!seq
            .iter()
            .skip_while(|s| **s != "done")
            .any(|s| *s != "done"));
    }

    #[tokio::test]
    async fn pause_then_resume_continues_with_a_range_and_cancel_removes_the_part() {
        let content = data(200_000);
        let hits = Arc::new(AtomicUsize::new(0));
        let (c2, h2) = (content.clone(), hits.clone());
        let ranges: Arc<Mutex<Vec<Option<String>>>> = Arc::default();
        let r2 = ranges.clone();
        let port = serve(move |mut s| {
            let (c, h, ranges) = (c2.clone(), h2.clone(), r2.clone());
            async move {
                let Some(req) = read_request(&mut s, None).await else {
                    return;
                };
                let range = req.header("range").map(str::to_owned);
                ranges.lock().unwrap().push(range.clone());
                let n = h.fetch_add(1, Ordering::SeqCst);
                let start: usize = range
                    .as_deref()
                    .map(|r| {
                        r.trim_start_matches("bytes=")
                            .trim_end_matches('-')
                            .parse()
                            .unwrap()
                    })
                    .unwrap_or(0);
                let body = &c[start..];
                let (st, extra) = if start > 0 {
                    (
                        206,
                        format!(
                            "Content-Range: bytes {start}-{}/{}\r\n",
                            c.len() - 1,
                            c.len()
                        ),
                    )
                } else {
                    (200, String::new())
                };
                let head = format!(
                    "HTTP/1.1 {st} X\r\nContent-Length: {}\r\n{extra}\r\n",
                    body.len()
                );
                let _ = s.write_all(head.as_bytes()).await;
                if n == 0 {
                    let _ = s.write_all(&body[..60_000]).await;
                    let _ = s.flush().await;
                    tokio::time::sleep(Duration::from_secs(30)).await; // stall
                } else {
                    let _ = s.write_all(body).await;
                    let _ = s.shutdown().await;
                }
            }
        })
        .await;
        let r = rig(link, port).await;
        let target = r.dir.path().join("dl");
        let id = r
            .mgr
            .start_download(r.api.clone(), 21, "x".into(), target.clone());
        for _ in 0..200 {
            if r.mgr.get(id).is_some_and(|v| v.bytes_done >= 60_000) {
                break;
            }
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
        r.mgr.pause(id).unwrap();
        wait_status(&r, id, "paused").await;
        assert!(target.join("maps.zip.part").exists());
        r.mgr.resume(id).unwrap();
        let v = wait_status(&r, id, "done").await;
        assert_eq!(std::fs::read(target.join("maps.zip")).unwrap(), content);
        assert_eq!(ranges.lock().unwrap()[1].as_deref(), Some("bytes=60000-"));
        assert_eq!(v.bytes_done, 200_000);

        // A second download, cancelled while it stalls: no part file stays.
        hits.store(0, Ordering::SeqCst);
        let target2 = r.dir.path().join("dl2");
        let id2 = r
            .mgr
            .start_download(r.api.clone(), 21, "x".into(), target2.clone());
        for _ in 0..200 {
            if r.mgr.get(id2).is_some_and(|v| v.bytes_done >= 60_000) {
                break;
            }
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
        r.mgr.cancel(id2).unwrap();
        wait_status(&r, id2, "cancelled").await;
        assert!(!target2.join("maps.zip.part").exists());
    }

    #[tokio::test]
    async fn a_failing_download_reports_a_coded_error_that_resume_can_retry() {
        let port = serve(|mut s| async move {
            let _ = read_request(&mut s, None).await;
            respond(&mut s, 403, &[], b"").await;
        })
        .await;
        let r = rig(link, port).await;
        let id = r
            .mgr
            .start_download(r.api.clone(), 21, "x".into(), r.dir.path().join("dl"));
        let v = wait_status(&r, id, "error").await;
        let e = v.error.unwrap();
        assert_eq!(e.code, "link_expired");
        assert!(e.retryable, "resume asks for a fresh link");
        assert!(!e.message.contains("127.0.0.1"));
    }

    #[tokio::test]
    async fn upload_paths_are_checked() {
        let r = rig(link, 1).await;
        let cfg = r.dir.path().join("cfg");
        std::fs::create_dir_all(&cfg).unwrap();
        let secret = cfg.join("credentials.json");
        std::fs::write(&secret, b"{}").unwrap();
        let ok = r.dir.path().join("ok.bin");
        std::fs::write(&ok, b"abc").unwrap();

        let deny = vec![cfg.clone()];
        assert!(matches!(
            check_upload_path(&secret, &deny),
            Err(Error::Usage(_))
        ));
        // A symlink into the config directory resolves to it and is refused too.
        #[cfg(unix)]
        {
            let link = r.dir.path().join("innocent.txt");
            std::os::unix::fs::symlink(&secret, &link).unwrap();
            assert!(matches!(
                check_upload_path(&link, &deny),
                Err(Error::Usage(_))
            ));
        }
        assert!(matches!(
            check_upload_path(r.dir.path(), &deny),
            Err(Error::Usage(_))
        ));
        assert!(matches!(
            check_upload_path(&r.dir.path().join("missing"), &deny),
            Err(Error::Io { .. })
        ));
        let (canon, size, _) = check_upload_path(&ok, &deny).unwrap();
        assert_eq!((canon, size), (ok.canonicalize().unwrap(), 3));
        assert!(r
            .mgr
            .start_upload(r.api.clone(), "e".into(), &secret)
            .is_err());
    }

    #[test]
    fn speed_and_eta() {
        let mut m = SpeedMeter::default();
        let t0 = Instant::now();
        assert_eq!(m.sample(t0, 0), 0);
        assert_eq!(
            m.sample(t0 + Duration::from_millis(100), 1_000),
            0,
            "window too short"
        );
        assert_eq!(m.sample(t0 + Duration::from_secs(1), 1_000_000), 1_000_000);
        assert_eq!(eta(1_000_000, 1_000_000, 5_000_000), Some(4));
        assert_eq!(eta(0, 1, 10), None);
        assert_eq!(eta(10, 10, 10), None);
    }
}
