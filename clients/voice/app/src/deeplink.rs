//! The `lanomat://` link grammar, kept pure so it can be tested without Tauri.
//!
//! Three actions exist: `lanomat://install/<slug>`, `lanomat://launch/<slug>`
//! (`play` is accepted as an alias for `launch`, which is what the web's
//! Starten button emits) and `lanomat://join/<slug>`. A join may carry
//! `?address=host:port` (the argument UT2004 launches with) or
//! `?url=<encoded join URL>` (a `steam://`/`ut2004://` handler the game
//! registered). The slug is exactly what the recipe API accepts,
//! `[a-z0-9-]{1,120}`, so anything else is refused before it can reach the
//! shell state or a URL.

use launcher_core::types::is_allowed_join_url;
use serde::Serialize;

pub const SCHEME: &str = "lanomat:";
pub const MAX_SLUG_LEN: usize = 120;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DeepLinkAction {
    Install,
    Launch,
    Join,
}

impl DeepLinkAction {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Install => "install",
            Self::Launch => "launch",
            Self::Join => "join",
        }
    }

    fn parse(raw: &str) -> Option<Self> {
        if raw.eq_ignore_ascii_case("install") {
            Some(Self::Install)
        } else if raw.eq_ignore_ascii_case("launch") || raw.eq_ignore_ascii_case("play") {
            Some(Self::Launch)
        } else if raw.eq_ignore_ascii_case("join") {
            Some(Self::Join)
        } else {
            None
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DeepLink {
    pub action: DeepLinkAction,
    pub slug: String,
    /// `?address=host:port` — the argument the game launches with (UT2004).
    pub address: Option<String>,
    /// `?url=<encoded>` — a game join handler (steam://, ut2004://, http(s)).
    pub url: Option<String>,
}

/// The stable event payload the UI receives as `deep-link`.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DeepLinkEvent {
    pub action: String,
    pub slug: String,
    // Absent for install/launch links, so the old payload shape stays exact.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub address: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub url: Option<String>,
}

impl From<DeepLink> for DeepLinkEvent {
    fn from(link: DeepLink) -> Self {
        Self {
            action: link.action.as_str().to_string(),
            slug: link.slug,
            address: link.address,
            url: link.url,
        }
    }
}

/// `[a-z0-9-]{1,120}`, the only shape a recipe slug may have.
pub fn is_valid_slug(slug: &str) -> bool {
    let len = slug.len();

    (1..=MAX_SLUG_LEN).contains(&len)
        && slug
            .bytes()
            .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
}

/// A `host:port` the game can be launched with. No scheme, no spaces: only
/// the characters a host name, IPv4, or bracketed IPv6 needs.
pub fn is_valid_join_address(address: &str) -> bool {
    let len = address.len();

    if !(3..=255).contains(&len) {
        return false;
    }

    if let Some(rest) = address.strip_prefix('[') {
        let Some((host, port)) = rest.split_once("]:") else {
            return false;
        };

        return !host.is_empty()
            && host
                .bytes()
                .all(|byte| byte.is_ascii_hexdigit() || matches!(byte, b'.' | b':'))
            && is_port(port);
    }

    let Some((host, port)) = address.rsplit_once(':') else {
        return false;
    };

    !host.is_empty()
        && !host.contains(':')
        && host
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-' | b'_'))
        && is_port(port)
}

fn is_port(port: &str) -> bool {
    (1..=5).contains(&port.len())
        && port.bytes().all(|byte| byte.is_ascii_digit())
        && port
            .parse::<u32>()
            .is_ok_and(|value| (1..=65535).contains(&value))
}

/// Parse `lanomat://install|launch|join/<slug>`.
///
/// Tolerates `lanomat:install/<slug>`, a missing `//`, a trailing slash, and a
/// fragment. A join may carry `?address=…` and/or `?url=…`; both are validated
/// here, so a malformed or non-allowlisted value is dropped rather than handed
/// to the shell. Anything that is not one of the three actions with one valid
/// slug yields `None`; this function never panics.
pub fn parse_deep_link(raw: &str) -> Option<DeepLink> {
    let rest = strip_prefix_ci(raw.trim(), SCHEME)?;
    let rest = rest.trim_start_matches('/');
    let rest = rest.split('#').next().unwrap_or_default();
    let (path, query) = match rest.split_once('?') {
        Some((path, query)) => (path, query),
        None => (rest, ""),
    };
    let path = path.trim_end_matches('/');
    let (action, slug) = path.split_once('/')?;

    let action = DeepLinkAction::parse(action)?;
    let slug = slug.trim_end_matches('/');

    if !is_valid_slug(slug) {
        return None;
    }

    let address = query_value(query, "address").filter(|value| is_valid_join_address(value));
    let url = query_value(query, "url").filter(|value| is_allowed_join_url(value));

    Some(DeepLink {
        action,
        slug: slug.to_string(),
        address,
        url,
    })
}

/// The decoded value of one query parameter, if present and non-empty.
fn query_value(query: &str, key: &str) -> Option<String> {
    query.split('&').find_map(|pair| {
        let (name, value) = pair.split_once('=')?;

        if !name.eq_ignore_ascii_case(key) {
            return None;
        }

        let value = percent_decode(value);

        (!value.is_empty()).then_some(value)
    })
}

/// Minimal percent-decoding for the two join values. Invalid escapes are kept
/// verbatim; `+` stays a plus (an address never contains a space).
fn percent_decode(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;

    while index < bytes.len() {
        if bytes[index] == b'%' && index + 2 < bytes.len() {
            let high = (bytes[index + 1] as char).to_digit(16);
            let low = (bytes[index + 2] as char).to_digit(16);

            if let (Some(high), Some(low)) = (high, low) {
                out.push((high * 16 + low) as u8);
                index += 3;
                continue;
            }
        }

        out.push(bytes[index]);
        index += 1;
    }

    String::from_utf8_lossy(&out).into_owned()
}

fn strip_prefix_ci<'a>(value: &'a str, prefix: &str) -> Option<&'a str> {
    value
        .get(..prefix.len())
        .filter(|head| head.eq_ignore_ascii_case(prefix))
        .map(|_| &value[prefix.len()..])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_the_documented_slugs() {
        assert!(is_valid_slug("unreal-tournament-2004"));
        assert!(is_valid_slug("ut2004"));
        assert!(is_valid_slug("a"));
        assert!(is_valid_slug(&"a".repeat(MAX_SLUG_LEN)));
    }

    #[test]
    fn rejects_anything_that_is_not_a_recipe_slug() {
        for slug in [
            "",
            "UT2004",
            "ut2004 ",
            " ut2004",
            "ut_2004",
            "ut.2004",
            "../etc/passwd",
            "ut2004/extra",
            "ut2004%2Fsystem",
            "üt2004",
            "ut\u{0}2004",
            "ut2004\n",
        ] {
            assert!(!is_valid_slug(slug), "{slug:?} must be rejected");
        }

        assert!(!is_valid_slug(&"a".repeat(MAX_SLUG_LEN + 1)));
    }

    #[test]
    fn parses_both_actions() {
        let install = parse_deep_link("lanomat://install/unreal-tournament-2004").unwrap();
        assert_eq!(install.action, DeepLinkAction::Install);
        assert_eq!(install.slug, "unreal-tournament-2004");

        let launch = parse_deep_link("lanomat://launch/ut2004").unwrap();
        assert_eq!(launch.action, DeepLinkAction::Launch);
        assert_eq!(launch.slug, "ut2004");

        // The web's Starten button speaks `play`.
        let play = parse_deep_link("lanomat://play/ut2004").unwrap();
        assert_eq!(play.action, DeepLinkAction::Launch);
        assert_eq!(play.slug, "ut2004");
    }

    #[test]
    fn tolerates_the_harmless_url_variants() {
        for raw in [
            "lanomat:install/ut2004",
            "lanomat:///install/ut2004",
            "LANOMAT://INSTALL/ut2004",
            " lanomat://install/ut2004 ",
            "lanomat://install/ut2004/",
            "lanomat://install/ut2004?from=web",
            "lanomat://install/ut2004#top",
        ] {
            let link = parse_deep_link(raw).unwrap_or_else(|| panic!("{raw} must parse"));
            assert_eq!(link.action, DeepLinkAction::Install);
            assert_eq!(link.slug, "ut2004");
        }
    }

    #[test]
    fn refuses_bad_links_without_panicking() {
        for raw in [
            "",
            "lanomat:",
            "lanomat://",
            "lanomat://install",
            "lanomat://install/",
            "lanomat://remove/ut2004",
            "lanomat://install/UT2004",
            "lanomat://install/ut2004/extra",
            "lanomat://install/../etc/passwd",
            "lanomat://install/ut2004%0aut2004",
            "https://lanomat.test/install/ut2004",
            "not a url",
            "lanomat://install/\u{4e16}\u{754c}",
        ] {
            assert!(parse_deep_link(raw).is_none(), "{raw:?} must be refused");
        }

        assert!(parse_deep_link(&"ü".repeat(4)).is_none());
        assert!(parse_deep_link(&format!(
            "lanomat://install/{}",
            "a".repeat(MAX_SLUG_LEN + 1)
        ))
        .is_none());
    }

    #[test]
    fn the_ui_event_payload_is_stable() {
        let link = parse_deep_link("lanomat://launch/ut2004").unwrap();
        let event = DeepLinkEvent::from(link);
        let json = serde_json::to_value(&event).unwrap();

        assert_eq!(
            json,
            serde_json::json!({ "action": "launch", "slug": "ut2004" })
        );
    }

    #[test]
    fn parses_a_one_click_join_with_the_server_address() {
        let link =
            parse_deep_link("lanomat://join/unreal-tournament-2004?address=203.0.113.9%3A7777")
                .unwrap();

        assert_eq!(link.action, DeepLinkAction::Join);
        assert_eq!(link.slug, "unreal-tournament-2004");
        assert_eq!(link.address.as_deref(), Some("203.0.113.9:7777"));
        assert_eq!(link.url, None);

        let json = serde_json::to_value(DeepLinkEvent::from(link)).unwrap();

        assert_eq!(
            json,
            serde_json::json!({
                "action": "join",
                "slug": "unreal-tournament-2004",
                "address": "203.0.113.9:7777"
            })
        );
    }

    #[test]
    fn keeps_an_allowlisted_join_url_and_drops_the_rest() {
        let link = parse_deep_link(
            "lanomat://join/ut2004?url=steam%3A%2F%2Frun%2F13230%2F%2F1.2.3.4%3A7777",
        )
        .unwrap();

        assert_eq!(link.url.as_deref(), Some("steam://run/13230//1.2.3.4:7777"));

        let unsafe_link =
            parse_deep_link("lanomat://join/ut2004?url=file%3A%2F%2F%2Fetc%2Fpasswd").unwrap();

        assert_eq!(unsafe_link.url, None);
    }

    #[test]
    fn validates_join_addresses_without_touching_the_slug_rule() {
        assert!(is_valid_join_address("203.0.113.9:7777"));
        assert!(is_valid_join_address("[2001:db8::1]:7777"));
        assert!(is_valid_join_address("lan.local:20922"));

        for address in [
            "",
            "203.0.113.9",
            "host:port:extra",
            "a b:1",
            "1.2.3.4:7777;rm",
        ] {
            assert!(
                !is_valid_join_address(address),
                "{address:?} must be rejected"
            );
        }

        // A bad address is dropped, the slug and action still parse.
        let link = parse_deep_link("lanomat://join/ut2004?address=not%20an%20address").unwrap();

        assert_eq!(link.address, None);
    }
}
