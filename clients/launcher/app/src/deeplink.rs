//! The `lanomat://` link grammar, kept pure so it can be tested without Tauri.
//!
//! Two actions exist in the pilot: `lanomat://install/<slug>` and
//! `lanomat://launch/<slug>`. The slug is exactly what the recipe API accepts,
//! `[a-z0-9-]{1,120}`, so anything else is refused before it can reach the
//! shell state or a URL.

use serde::Serialize;

pub const SCHEME: &str = "lanomat:";
pub const MAX_SLUG_LEN: usize = 120;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DeepLinkAction {
    Install,
    Launch,
}

impl DeepLinkAction {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Install => "install",
            Self::Launch => "launch",
        }
    }

    fn parse(raw: &str) -> Option<Self> {
        if raw.eq_ignore_ascii_case("install") {
            Some(Self::Install)
        } else if raw.eq_ignore_ascii_case("launch") {
            Some(Self::Launch)
        } else {
            None
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DeepLink {
    pub action: DeepLinkAction,
    pub slug: String,
}

/// The stable event payload the UI receives as `deep-link`.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DeepLinkEvent {
    pub action: String,
    pub slug: String,
}

impl From<DeepLink> for DeepLinkEvent {
    fn from(link: DeepLink) -> Self {
        Self {
            action: link.action.as_str().to_string(),
            slug: link.slug,
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

/// Parse `lanomat://install/<slug>` and `lanomat://launch/<slug>`.
///
/// Tolerates `lanomat:install/<slug>`, a missing `//`, a trailing slash, a
/// query string and a fragment. Anything that is not one of the two actions
/// with one valid slug yields `None`; this function never panics.
pub fn parse_deep_link(raw: &str) -> Option<DeepLink> {
    let rest = strip_prefix_ci(raw.trim(), SCHEME)?;
    let rest = rest.trim_start_matches('/');
    let rest = rest.split(['?', '#']).next().unwrap_or_default();
    let rest = rest.trim_end_matches('/');
    let (action, slug) = rest.split_once('/')?;

    let action = DeepLinkAction::parse(action)?;
    let slug = slug.trim_end_matches('/');

    if !is_valid_slug(slug) {
        return None;
    }

    Some(DeepLink {
        action,
        slug: slug.to_string(),
    })
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
}
