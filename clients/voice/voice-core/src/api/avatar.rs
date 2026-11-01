//! Avatars: the server stores a small square copy of each person's avatar and serves it behind the
//! bearer token (`GET api/voice-client/v1/avatars/<user id>/<content hash>`). The DTOs carry that
//! path (as `avatarUrl` / `avatar_url`), or `null` when the person has none. The path comes from
//! the server, so it is parsed strictly here: only that one route shape is ever requested, never
//! an arbitrary URL. The bytes are accepted only when they really are a PNG, JPEG or WebP and not
//! larger than [`MAX_AVATAR_BYTES`].

use base64::Engine as _;
use reqwest::Method;

use super::client::{error_from_response, net_err};
use super::ApiClient;
use crate::error::{Error, Result};

/// The server's copies are 256 px WebP files of a few KB; anything above this is not one.
pub const MAX_AVATAR_BYTES: usize = 512 * 1024;

/// A parsed avatar reference: whose avatar, and which version of it (the content hash).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AvatarRef {
    pub user_id: u64,
    pub hash: String,
}

impl AvatarRef {
    /// Parses what the DTOs carry: `/api/voice-client/v1/avatars/<id>/<hash>`, or the browser form
    /// `/avatars/<id>/<hash>` (the realtime chat event is shared with the browser). A full URL, a
    /// query, extra segments, dots or a hash that is not lower-case hex are all refused.
    pub fn parse(path: &str) -> Result<Self> {
        let bad = || Error::Usage(format!("not an avatar path: {path:?}"));
        let rest = path
            .strip_prefix("/api/voice-client/v1/avatars/")
            .or_else(|| path.strip_prefix("/avatars/"))
            .ok_or_else(bad)?;
        let mut parts = rest.split('/');
        let (id, hash) = (parts.next(), parts.next());
        if parts.next().is_some() {
            return Err(bad());
        }
        let user_id = id
            .filter(|s| !s.is_empty() && s.len() <= 18 && s.bytes().all(|b| b.is_ascii_digit()))
            .and_then(|s| s.parse::<u64>().ok())
            .ok_or_else(bad)?;
        let hash = hash
            .filter(|h| {
                (8..=64).contains(&h.len())
                    && h.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
            })
            .ok_or_else(bad)?;
        Ok(Self {
            user_id,
            hash: hash.to_owned(),
        })
    }

    /// The route below `api/voice-client/v1/`.
    fn route(&self) -> String {
        format!("avatars/{}/{}", self.user_id, self.hash)
    }
}

/// The image type by its magic bytes, or `None` when it is not a PNG, JPEG or WebP.
pub fn sniff_mime(bytes: &[u8]) -> Option<&'static str> {
    if bytes.starts_with(b"\x89PNG\r\n\x1a\n") {
        Some("image/png")
    } else if bytes.starts_with(&[0xFF, 0xD8, 0xFF]) {
        Some("image/jpeg")
    } else if bytes.len() >= 12 && &bytes[0..4] == b"RIFF" && &bytes[8..12] == b"WEBP" {
        Some("image/webp")
    } else {
        None
    }
}

/// `data:<mime>;base64,<bytes>` for bytes that really are an image of a sane size, else `None`.
pub fn data_uri(bytes: &[u8]) -> Option<String> {
    if bytes.is_empty() || bytes.len() > MAX_AVATAR_BYTES {
        return None;
    }
    let mime = sniff_mime(bytes)?;
    Some(format!(
        "data:{mime};base64,{}",
        base64::engine::general_purpose::STANDARD.encode(bytes)
    ))
}

impl ApiClient {
    /// `GET avatars/<id>/<hash>`: the raw bytes of an avatar, at most [`MAX_AVATAR_BYTES`] and
    /// only if they are a PNG, JPEG or WebP. A 404 (the person changed their avatar, the hash is
    /// stale) is [`Error::NotFound`]; the caller shows the monogram.
    pub async fn avatar(&self, avatar: &AvatarRef) -> Result<Vec<u8>> {
        let mut resp = self
            .raw_v1(Method::GET, &avatar.route())?
            .send()
            .await
            .map_err(net_err)?;
        if !resp.status().is_success() {
            return Err(error_from_response(resp).await);
        }
        if resp
            .content_length()
            .is_some_and(|n| n > MAX_AVATAR_BYTES as u64)
        {
            return Err(Error::BadResponse("the avatar is too large".into()));
        }
        let mut bytes = Vec::new();
        while let Some(chunk) = resp.chunk().await.map_err(net_err)? {
            if bytes.len() + chunk.len() > MAX_AVATAR_BYTES {
                return Err(Error::BadResponse("the avatar is too large".into()));
            }
            bytes.extend_from_slice(&chunk);
        }
        if sniff_mime(&bytes).is_none() {
            return Err(Error::BadResponse("the avatar is not an image".into()));
        }
        Ok(bytes)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use wiremock::matchers::{header, method, path};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    const PNG: &[u8] = b"\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

    #[test]
    fn parses_the_bearer_and_the_browser_form_and_nothing_else() {
        let want = AvatarRef {
            user_id: 12,
            hash: "0123456789abcdef".into(),
        };
        assert_eq!(
            AvatarRef::parse("/api/voice-client/v1/avatars/12/0123456789abcdef").unwrap(),
            want
        );
        assert_eq!(
            AvatarRef::parse("/avatars/12/0123456789abcdef").unwrap(),
            want
        );
        for bad in [
            "",
            "https://evil.example/avatars/12/0123456789abcdef",
            "//evil.example/avatars/12/0123456789abcdef",
            "/avatars/12/0123456789abcdef?x=1",
            "/avatars/12/0123456789abcdef/extra",
            "/avatars/12/../0123456789abcdef",
            "/avatars/-1/0123456789abcdef",
            "/avatars/12/ABCDEF0123456789",
            "/avatars/12/short",
            "/avatars/abc/0123456789abcdef",
            "/api/voice-client/v1/me",
            "avatars/12/0123456789abcdef",
        ] {
            assert!(AvatarRef::parse(bad).is_err(), "{bad:?} must be refused");
        }
    }

    #[test]
    fn sniffs_png_jpeg_and_webp_by_magic_bytes_only() {
        assert_eq!(sniff_mime(PNG), Some("image/png"));
        assert_eq!(
            sniff_mime(&[0xFF, 0xD8, 0xFF, 0xE0, 0, 0]),
            Some("image/jpeg")
        );
        assert_eq!(sniff_mime(b"RIFF\x24\0\0\0WEBPVP8 "), Some("image/webp"));
        assert_eq!(sniff_mime(b"GIF89a......"), None);
        assert_eq!(sniff_mime(b"<svg xmlns='x'/>"), None);
        assert_eq!(sniff_mime(b"<html>"), None);
        assert_eq!(sniff_mime(b""), None);
        assert_eq!(sniff_mime(b"RIFF\x24\0\0\0WAVEfmt "), None);
    }

    #[test]
    fn builds_a_data_uri_only_for_small_real_images() {
        let uri = data_uri(PNG).unwrap();
        assert!(uri.starts_with("data:image/png;base64,"));
        assert_eq!(data_uri(b"not an image"), None);
        assert_eq!(data_uri(b""), None);
        let mut big = PNG.to_vec();
        big.resize(MAX_AVATAR_BYTES + 1, 0);
        assert_eq!(data_uri(&big), None);
        let mut edge = PNG.to_vec();
        edge.resize(MAX_AVATAR_BYTES, 0);
        assert!(data_uri(&edge).is_some());
    }

    fn client(server: &MockServer) -> ApiClient {
        ApiClient::new(&server.uri()).unwrap().with_token("lvc_tok")
    }

    fn avatar_ref() -> AvatarRef {
        AvatarRef::parse("/api/voice-client/v1/avatars/12/0123456789abcdef").unwrap()
    }

    #[tokio::test]
    async fn fetches_the_bytes_with_the_bearer_token() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/avatars/12/0123456789abcdef"))
            .and(header("authorization", "Bearer lvc_tok"))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(PNG))
            .mount(&server)
            .await;

        assert_eq!(client(&server).avatar(&avatar_ref()).await.unwrap(), PNG);
    }

    #[tokio::test]
    async fn refuses_non_images_oversized_bodies_and_error_statuses() {
        let server = MockServer::start().await;
        let route = "/api/voice-client/v1/avatars/12/0123456789abcdef";
        let c = client(&server);

        Mock::given(method("GET"))
            .and(path(route))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(&b"<html>hi</html>"[..]))
            .up_to_n_times(1)
            .mount(&server)
            .await;
        assert!(matches!(
            c.avatar(&avatar_ref()).await,
            Err(Error::BadResponse(_))
        ));

        let mut big = PNG.to_vec();
        big.resize(MAX_AVATAR_BYTES + 10, 0);
        Mock::given(method("GET"))
            .and(path(route))
            .respond_with(ResponseTemplate::new(200).set_body_bytes(big))
            .up_to_n_times(1)
            .mount(&server)
            .await;
        assert!(matches!(
            c.avatar(&avatar_ref()).await,
            Err(Error::BadResponse(_))
        ));

        Mock::given(method("GET"))
            .and(path(route))
            .respond_with(ResponseTemplate::new(404))
            .up_to_n_times(1)
            .mount(&server)
            .await;
        assert!(matches!(
            c.avatar(&avatar_ref()).await,
            Err(Error::NotFound(_))
        ));

        Mock::given(method("GET"))
            .and(path(route))
            .respond_with(ResponseTemplate::new(401))
            .mount(&server)
            .await;
        assert!(matches!(
            c.avatar(&avatar_ref()).await,
            Err(Error::Unauthorized)
        ));
    }

    #[tokio::test]
    async fn needs_a_token() {
        let server = MockServer::start().await;
        let c = ApiClient::new(&server.uri()).unwrap();
        assert!(matches!(
            c.avatar(&avatar_ref()).await,
            Err(Error::NotLoggedIn)
        ));
    }
}
