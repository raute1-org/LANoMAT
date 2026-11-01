//! Streaming download of a signed file-host link to disk: a temporary `.part` file, resume with
//! HTTP `Range` when the host answers `206`, retries with backoff, atomic rename at the end.

use std::path::{Path, PathBuf};
use std::time::Duration;

use futures_util::StreamExt;
use reqwest::header::{CONTENT_RANGE, RANGE};
use reqwest::StatusCode;
use tokio::io::AsyncWriteExt;

use super::{
    interrupted, sanitize_filename, sleep_or_stop, transfer_err, Ctrl, Phase, Reporter, Stop,
};

#[derive(Clone)]
pub struct DownloadSpec {
    /// The signed link (a credential: never logged).
    pub url: String,
    /// The server's file name; sanitised before use.
    pub name: String,
    pub expected_size: Option<u64>,
    /// Only for a same-origin download streamed by the app itself (legacy store).
    pub bearer: Option<String>,
    pub dir: PathBuf,
}

impl std::fmt::Debug for DownloadSpec {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DownloadSpec")
            .field("name", &self.name)
            .field("expected_size", &self.expected_size)
            .field("dir", &self.dir)
            .finish_non_exhaustive()
    }
}

/// `<dir>/<name>`, or `<dir>/<stem> (n).<ext>` when that exists already.
pub fn unique_path(dir: &Path, name: &str) -> PathBuf {
    let first = dir.join(name);
    if !first.exists() {
        return first;
    }
    let (stem, ext) = match name.rfind('.') {
        Some(i) if i > 0 => (&name[..i], &name[i..]),
        _ => (name, ""),
    };
    (1..10_000)
        .map(|n| dir.join(format!("{stem} ({n}){ext}")))
        .find(|p| !p.exists())
        .unwrap_or_else(|| dir.join(format!("{stem} (copy){ext}")))
}

/// The path of the temporary file a download of `name` into `dir` uses.
pub fn part_path(dir: &Path, name: &str) -> PathBuf {
    dir.join(format!("{}.part", sanitize_filename(name)))
}

/// `bytes <start>-<end>/<total>` or `bytes */<total>`: (start, total).
fn parse_content_range(v: &str) -> (Option<u64>, Option<u64>) {
    let Some(rest) = v.trim().strip_prefix("bytes ") else {
        return (None, None);
    };
    let (range, total) = rest.split_once('/').unwrap_or((rest, ""));
    let start = range.split('-').next().and_then(|s| s.trim().parse().ok());
    (start, total.trim().parse().ok())
}

fn io_stop(path: &Path, e: std::io::Error) -> Stop {
    Stop::Failed(crate::Error::io(path, e))
}

pub async fn download(
    http: &reqwest::Client,
    spec: &DownloadSpec,
    delays: &[Duration],
    rep: &dyn Reporter,
    ctrl: &mut Ctrl,
) -> Result<PathBuf, Stop> {
    let name = sanitize_filename(&spec.name);
    tokio::fs::create_dir_all(&spec.dir)
        .await
        .map_err(|e| io_stop(&spec.dir, e))?;
    let part = part_path(&spec.dir, &name);
    let mut retry = 0usize;
    let mut total = spec.expected_size;
    rep.phase(Phase::Preparing);

    'attempt: loop {
        let existing = tokio::fs::metadata(&part)
            .await
            .map(|m| m.len())
            .unwrap_or(0);
        let mut req = http.get(&spec.url);
        if let Some(t) = &spec.bearer {
            req = req.bearer_auth(t);
        }
        if existing > 0 {
            req = req.header(RANGE, format!("bytes={existing}-"));
        }
        let resp = tokio::select! {
            stop = interrupted(ctrl) => return Err(cancel_cleanup(stop, &part).await),
            r = req.send() => r,
        };
        let resp = match resp {
            Ok(r) => r,
            Err(e) => {
                // `without_url`: the signed link must not leak into messages.
                let msg = e.without_url().to_string();
                retry_or_fail(&mut retry, delays, rep, ctrl, &part, &msg).await?;
                continue 'attempt;
            }
        };

        let status = resp.status();
        let append;
        match status {
            StatusCode::PARTIAL_CONTENT => {
                let cr = resp
                    .headers()
                    .get(CONTENT_RANGE)
                    .and_then(|v| v.to_str().ok())
                    .unwrap_or("");
                let (start, t) = parse_content_range(cr);
                if start != Some(existing) {
                    // Not the bytes we asked for: start over rather than corrupt the file.
                    let _ = tokio::fs::remove_file(&part).await;
                    continue 'attempt;
                }
                total = t.or(total);
                append = true;
            }
            StatusCode::OK => {
                if existing > 0 {
                    // The host ignored the Range header: the whole file comes again.
                    let _ = tokio::fs::remove_file(&part).await;
                }
                total = resp.content_length().or(spec.expected_size);
                append = false;
            }
            StatusCode::RANGE_NOT_SATISFIABLE => {
                let cr = resp
                    .headers()
                    .get(CONTENT_RANGE)
                    .and_then(|v| v.to_str().ok())
                    .unwrap_or("");
                let (_, t) = parse_content_range(cr);
                if existing > 0 && t == Some(existing) {
                    break 'attempt; // the part file is already complete
                }
                let _ = tokio::fs::remove_file(&part).await;
                if existing == 0 {
                    return Err(transfer_err(
                        "download_failed",
                        "the file host refused the range",
                    ));
                }
                continue 'attempt;
            }
            s if s == StatusCode::UNAUTHORIZED
                || s == StatusCode::FORBIDDEN
                || s == StatusCode::NOT_FOUND
                || s == StatusCode::GONE =>
            {
                return Err(transfer_err(
                    "link_expired",
                    "the download link is not valid any more; request it again",
                ));
            }
            s if s.is_server_error() || s == StatusCode::TOO_MANY_REQUESTS => {
                let msg = format!("HTTP {}", s.as_u16());
                retry_or_fail(&mut retry, delays, rep, ctrl, &part, &msg).await?;
                continue 'attempt;
            }
            s => {
                return Err(transfer_err(
                    "download_failed",
                    format!("unexpected answer from the file host (HTTP {})", s.as_u16()),
                ));
            }
        }

        let mut file = tokio::fs::OpenOptions::new()
            .create(true)
            .write(true)
            .append(append)
            .truncate(!append)
            .open(&part)
            .await
            .map_err(|e| io_stop(&part, e))?;
        let mut done = if append { existing } else { 0 };
        rep.phase(Phase::Active);
        rep.progress(done, total.unwrap_or(0));

        let mut stream = resp.bytes_stream();
        let mut broke: Option<String> = None;
        loop {
            tokio::select! {
                stop = interrupted(ctrl) => {
                    let _ = file.flush().await;
                    return Err(cancel_cleanup(stop, &part).await);
                }
                chunk = stream.next() => match chunk {
                    None => break,
                    Some(Ok(b)) => {
                        file.write_all(&b).await.map_err(|e| io_stop(&part, e))?;
                        done += b.len() as u64;
                        retry = 0;
                        rep.progress(done, total.unwrap_or(0));
                    }
                    Some(Err(e)) => {
                        broke = Some(e.without_url().to_string());
                        break;
                    }
                },
            }
        }
        file.flush().await.map_err(|e| io_stop(&part, e))?;
        drop(file);
        let size = tokio::fs::metadata(&part)
            .await
            .map(|m| m.len())
            .unwrap_or(0);
        if let Some(msg) = broke {
            retry_or_fail(&mut retry, delays, rep, ctrl, &part, &msg).await?;
            continue 'attempt;
        }
        match total {
            Some(t) if size < t => {
                retry_or_fail(
                    &mut retry,
                    delays,
                    rep,
                    ctrl,
                    &part,
                    "connection closed early",
                )
                .await?;
                continue 'attempt;
            }
            Some(t) if size > t => {
                let _ = tokio::fs::remove_file(&part).await;
                return Err(transfer_err(
                    "download_failed",
                    "the file is larger than announced",
                ));
            }
            _ => break 'attempt,
        }
    }

    let final_path = unique_path(&spec.dir, &name);
    tokio::fs::rename(&part, &final_path)
        .await
        .map_err(|e| io_stop(&final_path, e))?;
    if let Ok(m) = tokio::fs::metadata(&final_path).await {
        rep.progress(m.len(), m.len());
    }
    Ok(final_path)
}

/// Cancel removes the part file; pause keeps it for the resume.
async fn cancel_cleanup(stop: Stop, part: &Path) -> Stop {
    if matches!(stop, Stop::Cancelled) {
        let _ = tokio::fs::remove_file(part).await;
    }
    stop
}

async fn retry_or_fail(
    retry: &mut usize,
    delays: &[Duration],
    rep: &dyn Reporter,
    ctrl: &mut Ctrl,
    part: &Path,
    msg: &str,
) -> Result<(), Stop> {
    let Some(delay) = delays.get(*retry).copied() else {
        // Out of retries: keep the part file, a manual resume continues from it.
        return Err(transfer_err(
            "network",
            format!("download interrupted: {msg}"),
        ));
    };
    *retry += 1;
    rep.phase(Phase::Reconnecting);
    if let Err(stop) = sleep_or_stop(ctrl, delay).await {
        return Err(cancel_cleanup(stop, part).await);
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::{Arc, Mutex};

    use tokio::io::AsyncWriteExt;
    use tokio::sync::watch;

    use super::super::testutil::*;
    use super::super::RunState;
    use super::*;

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

    #[derive(Default)]
    struct Behaviour {
        /// Cut the first connection after this many body bytes.
        drop_first_after: Option<usize>,
        ignore_range: bool,
        status: Option<u16>,
    }

    struct Host {
        port: u16,
        requests: Arc<Mutex<Vec<(String, Option<String>)>>>, // (path, Range)
        auth_seen: Arc<Mutex<Vec<Option<String>>>>,
    }

    async fn host(content: Vec<u8>, b: Behaviour) -> Host {
        let requests = Arc::new(Mutex::new(Vec::new()));
        let auth_seen = Arc::new(Mutex::new(Vec::new()));
        let counter = Arc::new(AtomicUsize::new(0));
        let (r2, a2) = (requests.clone(), auth_seen.clone());
        let b = Arc::new(b);
        let content = Arc::new(content);
        let port = serve(move |mut s| {
            let (requests, auth_seen, counter, b, content) = (
                r2.clone(),
                a2.clone(),
                counter.clone(),
                b.clone(),
                content.clone(),
            );
            async move {
                let Some(req) = read_request(&mut s, None).await else {
                    return;
                };
                let range = req.header("range").map(str::to_owned);
                requests
                    .lock()
                    .unwrap()
                    .push((req.path.clone(), range.clone()));
                auth_seen
                    .lock()
                    .unwrap()
                    .push(req.header("authorization").map(str::to_owned));
                let n = counter.fetch_add(1, Ordering::SeqCst);
                if let Some(st) = b.status {
                    respond(&mut s, st, &[], b"nope").await;
                    return;
                }
                let total = content.len();
                let (status, start) = match (&range, b.ignore_range) {
                    (Some(r), false) => {
                        let start: usize = r
                            .trim_start_matches("bytes=")
                            .trim_end_matches('-')
                            .parse()
                            .unwrap();
                        if start >= total {
                            respond(
                                &mut s,
                                416,
                                &[("Content-Range", format!("bytes */{total}"))],
                                b"",
                            )
                            .await;
                            return;
                        }
                        (206, start)
                    }
                    _ => (200, 0),
                };
                let body = &content[start..];
                let mut head = format!(
                    "HTTP/1.1 {status} X\r\nConnection: close\r\nContent-Length: {}\r\n",
                    body.len()
                );
                if status == 206 {
                    head.push_str(&format!(
                        "Content-Range: bytes {start}-{}/{total}\r\n",
                        total - 1
                    ));
                }
                head.push_str("\r\n");
                let _ = s.write_all(head.as_bytes()).await;
                if n == 0 {
                    if let Some(cut) = b.drop_first_after {
                        let _ = s.write_all(&body[..cut.min(body.len())]).await;
                        let _ = s.flush().await;
                        return; // connection cut mid-body
                    }
                }
                let _ = s.write_all(body).await;
                let _ = s.shutdown().await;
            }
        })
        .await;
        Host {
            port,
            requests,
            auth_seen,
        }
    }

    fn spec(h: &Host, dir: &Path, name: &str, size: Option<u64>) -> DownloadSpec {
        DownloadSpec {
            url: format!("http://127.0.0.1:{}/dl/p?expires=1&md5=sig", h.port),
            name: name.into(),
            expected_size: size,
            bearer: None,
            dir: dir.to_path_buf(),
        }
    }

    fn fast() -> Vec<Duration> {
        vec![
            Duration::ZERO,
            Duration::from_millis(10),
            Duration::from_millis(10),
        ]
    }

    async fn run(spec: &DownloadSpec, rec: &Rec) -> Result<PathBuf, Stop> {
        let (_tx, mut ctrl) = watch::channel(RunState::Running);
        let http = reqwest::Client::new();
        download(&http, spec, &fast(), rec, &mut ctrl).await
    }

    #[tokio::test]
    async fn downloads_to_the_target_directory_with_progress_and_no_part_file_left() {
        let content = data(300_000);
        let h = host(content.clone(), Behaviour::default()).await;
        let dir = tempfile::tempdir().unwrap();
        let rec = Rec::default();
        let p = run(&spec(&h, dir.path(), "maps.zip", Some(300_000)), &rec)
            .await
            .unwrap();
        assert_eq!(p, dir.path().join("maps.zip"));
        assert_eq!(std::fs::read(&p).unwrap(), content);
        assert!(!dir.path().join("maps.zip.part").exists());
        let prog = rec.progress.lock().unwrap().clone();
        assert!(prog.len() >= 2);
        assert_eq!(*prog.last().unwrap(), (300_000, 300_000));
        assert!(prog.windows(2).all(|w| w[0].0 <= w[1].0), "monotonic");
    }

    #[tokio::test]
    async fn a_dropped_connection_resumes_with_a_range_request() {
        let content = data(500_000);
        let h = host(
            content.clone(),
            Behaviour {
                drop_first_after: Some(120_000),
                ..Default::default()
            },
        )
        .await;
        let dir = tempfile::tempdir().unwrap();
        let rec = Rec::default();
        let p = run(&spec(&h, dir.path(), "big.bin", Some(500_000)), &rec)
            .await
            .unwrap();
        assert_eq!(std::fs::read(&p).unwrap(), content);
        let reqs = h.requests.lock().unwrap().clone();
        assert_eq!(reqs.len(), 2);
        assert_eq!(reqs[0].1, None);
        let second = reqs[1].1.clone().unwrap();
        let start: usize = second
            .trim_start_matches("bytes=")
            .trim_end_matches('-')
            .parse()
            .unwrap();
        assert!(start > 0 && start <= 120_000, "resumed at {start}");
        assert!(rec.phases.lock().unwrap().contains(&Phase::Reconnecting));
    }

    #[tokio::test]
    async fn a_host_that_ignores_range_restarts_cleanly() {
        let content = data(200_000);
        let h = host(
            content.clone(),
            Behaviour {
                drop_first_after: Some(50_000),
                ignore_range: true,
                ..Default::default()
            },
        )
        .await;
        let dir = tempfile::tempdir().unwrap();
        let p = run(
            &spec(&h, dir.path(), "x.bin", Some(200_000)),
            &Rec::default(),
        )
        .await
        .unwrap();
        assert_eq!(std::fs::read(&p).unwrap(), content);
    }

    #[tokio::test]
    async fn an_existing_part_file_is_continued_after_a_restart() {
        let content = data(100_000);
        let h = host(content.clone(), Behaviour::default()).await;
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(dir.path().join("r.bin.part"), &content[..40_000]).unwrap();
        let p = run(
            &spec(&h, dir.path(), "r.bin", Some(100_000)),
            &Rec::default(),
        )
        .await
        .unwrap();
        assert_eq!(std::fs::read(&p).unwrap(), content);
        assert_eq!(
            h.requests.lock().unwrap()[0].1.as_deref(),
            Some("bytes=40000-")
        );
    }

    #[tokio::test]
    async fn a_complete_part_file_is_finished_via_416() {
        let content = data(10_000);
        let h = host(content.clone(), Behaviour::default()).await;
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(dir.path().join("c.bin.part"), &content).unwrap();
        let p = run(&spec(&h, dir.path(), "c.bin", None), &Rec::default())
            .await
            .unwrap();
        assert_eq!(std::fs::read(&p).unwrap(), content);
    }

    #[tokio::test]
    async fn an_existing_final_file_is_never_overwritten() {
        let content = data(1_000);
        let h = host(content.clone(), Behaviour::default()).await;
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(dir.path().join("a.txt"), b"mine").unwrap();
        let p = run(&spec(&h, dir.path(), "a.txt", None), &Rec::default())
            .await
            .unwrap();
        assert_eq!(p, dir.path().join("a (1).txt"));
        assert_eq!(std::fs::read(dir.path().join("a.txt")).unwrap(), b"mine");
    }

    #[tokio::test]
    async fn the_server_supplied_name_cannot_leave_the_directory() {
        let h = host(data(10), Behaviour::default()).await;
        let dir = tempfile::tempdir().unwrap();
        let p = run(
            &spec(&h, dir.path(), "../../evil.sh", None),
            &Rec::default(),
        )
        .await
        .unwrap();
        assert_eq!(p, dir.path().join("evil.sh"));
    }

    #[tokio::test]
    async fn a_dead_link_is_link_expired_and_the_message_has_no_url() {
        let h = host(
            data(10),
            Behaviour {
                status: Some(403),
                ..Default::default()
            },
        )
        .await;
        let dir = tempfile::tempdir().unwrap();
        let s = spec(&h, dir.path(), "x", None);
        match run(&s, &Rec::default()).await {
            Err(Stop::Failed(e)) => {
                assert_eq!(e.code(), Some("link_expired"));
                assert!(!e.to_string().contains("127.0.0.1"));
            }
            other => panic!("{other:?}"),
        }
        assert_eq!(h.requests.lock().unwrap().len(), 1, "not retried");
    }

    #[tokio::test]
    async fn server_errors_are_retried_then_reported_without_the_url() {
        let h = host(
            data(10),
            Behaviour {
                status: Some(503),
                ..Default::default()
            },
        )
        .await;
        let dir = tempfile::tempdir().unwrap();
        let s = spec(&h, dir.path(), "x", None);
        match run(&s, &Rec::default()).await {
            Err(Stop::Failed(e)) => {
                assert!(e.retryable());
                assert!(!e.to_string().contains("127.0.0.1"));
            }
            other => panic!("{other:?}"),
        }
        assert_eq!(
            h.requests.lock().unwrap().len(),
            4,
            "first try plus three retries"
        );
    }

    #[tokio::test]
    async fn the_bearer_goes_only_where_it_is_asked_for() {
        let h = host(data(10), Behaviour::default()).await;
        let dir = tempfile::tempdir().unwrap();
        let mut s = spec(&h, dir.path(), "x", None);
        run(&s, &Rec::default()).await.unwrap();
        s.bearer = Some("tok".into());
        s.name = "y".into();
        run(&s, &Rec::default()).await.unwrap();
        let seen = h.auth_seen.lock().unwrap().clone();
        assert_eq!(seen, vec![None, Some("Bearer tok".to_owned())]);
    }

    #[tokio::test]
    async fn pause_keeps_the_part_file_and_cancel_removes_it() {
        // A host that sends part of the body and then stalls.
        let port = serve(|mut s| async move {
            let _ = read_request(&mut s, None).await;
            let head = "HTTP/1.1 200 X\r\nContent-Length: 1000000\r\n\r\n";
            let _ = s.write_all(head.as_bytes()).await;
            let _ = s.write_all(&data(50_000)).await;
            let _ = s.flush().await;
            tokio::time::sleep(Duration::from_secs(30)).await;
        })
        .await;
        for (state, part_stays) in [(RunState::Paused, true), (RunState::Cancelled, false)] {
            let dir = tempfile::tempdir().unwrap();
            let spec = DownloadSpec {
                url: format!("http://127.0.0.1:{port}/x"),
                name: "slow.bin".into(),
                expected_size: Some(1_000_000),
                bearer: None,
                dir: dir.path().to_path_buf(),
            };
            let (tx, mut ctrl) = watch::channel(RunState::Running);
            let rec = Arc::new(Rec::default());
            let r2 = rec.clone();
            let job = tokio::spawn(async move {
                let http = reqwest::Client::new();
                download(&http, &spec, &fast(), r2.as_ref(), &mut ctrl).await
            });
            for _ in 0..200 {
                if rec.progress.lock().unwrap().iter().any(|p| p.0 > 0) {
                    break;
                }
                tokio::time::sleep(Duration::from_millis(20)).await;
            }
            tx.send(state).unwrap();
            let res = job.await.unwrap();
            match (state, res) {
                (RunState::Paused, Err(Stop::Paused))
                | (RunState::Cancelled, Err(Stop::Cancelled)) => {}
                (_, other) => panic!("{other:?}"),
            }
            assert_eq!(dir.path().join("slow.bin.part").exists(), part_stays);
        }
    }

    #[test]
    fn content_range_parsing() {
        assert_eq!(
            parse_content_range("bytes 100-199/1000"),
            (Some(100), Some(1000))
        );
        assert_eq!(parse_content_range("bytes */1000"), (None, Some(1000)));
        assert_eq!(parse_content_range("nonsense"), (None, None));
    }
}
