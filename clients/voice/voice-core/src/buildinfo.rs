//! Which build this is. The release script (`scripts/ops/build-client.sh`) passes the LANoMAT
//! version and git commit through `LANOMAT_BUILD_VERSION` and `LANOMAT_BUILD_COMMIT` at compile
//! time, so a client built for a server reports the very string that server reports. A plain
//! `cargo build` falls back to `dev`.

use std::sync::OnceLock;

/// Identity of this binary.
#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Serialize)]
pub struct BuildInfo {
    /// The LANoMAT release version this client was built for (for example `m12-463-g0a3b6ff`),
    /// or `dev` for a local build.
    pub version: &'static str,
    /// The git commit it was built from, or `unknown`.
    pub commit: &'static str,
}

const VERSION: &str = match option_env!("LANOMAT_BUILD_VERSION") {
    Some(v) if !v.is_empty() => v,
    _ => "dev",
};

const COMMIT: &str = match option_env!("LANOMAT_BUILD_COMMIT") {
    Some(c) if !c.is_empty() => c,
    _ => "unknown",
};

/// The compile-time identity of this build.
pub const fn build_info() -> BuildInfo {
    BuildInfo {
        version: VERSION,
        commit: COMMIT,
    }
}

/// `lanomat-voice/<version>`, the `User-Agent` of every request.
pub fn user_agent() -> &'static str {
    static UA: OnceLock<String> = OnceLock::new();
    UA.get_or_init(|| format!("lanomat-voice/{VERSION}"))
}

/// The text of `--version`: `<version> (<commit>)`.
pub fn long_version() -> &'static str {
    static TEXT: OnceLock<String> = OnceLock::new();
    TEXT.get_or_init(|| format!("{VERSION} ({COMMIT})"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn build_info_is_never_empty_and_feeds_the_user_agent() {
        let info = build_info();
        assert!(!info.version.is_empty());
        assert!(!info.commit.is_empty());
        assert_eq!(user_agent(), format!("lanomat-voice/{}", info.version));
        assert!(long_version().starts_with(info.version));
        assert!(long_version().contains(info.commit));
    }
}
