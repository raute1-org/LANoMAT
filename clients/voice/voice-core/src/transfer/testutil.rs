//! A tiny raw HTTP/1.1 server for the transfer tests: one request per connection, and the ability
//! to cut a connection in the middle of a request body (what a flaky network does), which no
//! mock-server library can do.

use std::collections::HashMap;
use std::future::Future;

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

pub struct Req {
    pub method: String,
    pub path: String,
    pub headers: HashMap<String, String>,
    pub body: Vec<u8>,
    /// False when the body was cut short (by `body_limit` or by the peer).
    pub complete: bool,
}

impl Req {
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers
            .get(&name.to_ascii_lowercase())
            .map(String::as_str)
    }
}

/// Reads one request, the body in full.
pub async fn read_request(s: &mut TcpStream, _unused: Option<usize>) -> Option<Req> {
    read_request_with(s, |_, _| None).await
}

/// Reads one request. `limit(method, headers)` may return a byte count: the body is then read
/// only up to that many bytes (the caller typically drops the stream, which cuts the connection).
pub async fn read_request_with(
    s: &mut TcpStream,
    limit: impl Fn(&str, &HashMap<String, String>) -> Option<usize>,
) -> Option<Req> {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 4096];
    let head_end = loop {
        if let Some(p) = buf.windows(4).position(|w| w == b"\r\n\r\n") {
            break p;
        }
        let n = s.read(&mut tmp).await.ok()?;
        if n == 0 {
            return None;
        }
        buf.extend_from_slice(&tmp[..n]);
    };
    let head = String::from_utf8_lossy(&buf[..head_end]).to_string();
    let mut body = buf[head_end + 4..].to_vec();
    let mut lines = head.lines();
    let mut first = lines.next()?.split_whitespace();
    let method = first.next()?.to_owned();
    let path = first.next()?.to_owned();
    let headers: HashMap<String, String> = lines
        .filter_map(|l| l.split_once(':'))
        .map(|(k, v)| (k.trim().to_ascii_lowercase(), v.trim().to_owned()))
        .collect();
    let want: usize = headers
        .get("content-length")
        .and_then(|v| v.parse().ok())
        .unwrap_or(0);
    let body_limit: Option<usize> = limit(&method, &headers);
    let target = body_limit.map_or(want, |l| l.min(want));
    while body.len() < target {
        let n = s.read(&mut tmp).await.unwrap_or(0);
        if n == 0 {
            break;
        }
        body.extend_from_slice(&tmp[..n]);
    }
    body.truncate(target);
    let complete = body.len() == want;
    Some(Req {
        method,
        path,
        headers,
        body,
        complete,
    })
}

pub async fn respond(s: &mut TcpStream, status: u16, headers: &[(&str, String)], body: &[u8]) {
    let mut out = format!("HTTP/1.1 {status} X\r\nConnection: close\r\n");
    let mut has_len = false;
    for (k, v) in headers {
        if k.eq_ignore_ascii_case("content-length") {
            has_len = true;
        }
        out.push_str(&format!("{k}: {v}\r\n"));
    }
    if !has_len {
        out.push_str(&format!("Content-Length: {}\r\n", body.len()));
    }
    out.push_str("\r\n");
    let _ = s.write_all(out.as_bytes()).await;
    let _ = s.write_all(body).await;
    let _ = s.shutdown().await;
}

/// Binds a loopback port and runs `handler` for every connection. Returns the port.
pub async fn serve<F, Fut>(handler: F) -> u16
where
    F: Fn(TcpStream) -> Fut + Send + Sync + 'static,
    Fut: Future<Output = ()> + Send + 'static,
{
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let handler = std::sync::Arc::new(handler);
    tokio::spawn(async move {
        loop {
            let Ok((s, _)) = listener.accept().await else {
                return;
            };
            let h = handler.clone();
            tokio::spawn(async move { h(s).await });
        }
    });
    port
}

/// Deterministic test data.
pub fn data(n: usize) -> Vec<u8> {
    (0..n).map(|i| (i % 251) as u8).collect()
}
