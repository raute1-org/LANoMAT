//! Is this client good enough for the server it talks to? A pure decision over the `client` block
//! of `GET config`; the UI shows a banner for anything but [`UpdateStatus::UpToDate`].
//!
//! LANoMAT release versions are `git describe` strings (`m12-463-g0a3b6ff`, optionally
//! `-dirty`) or, with an explicit release label, `1.4.2`. The comparison is deliberately
//! forgiving and never blocks on something it cannot read:
//!
//! * equal strings are up to date;
//! * `dev` and anything that is neither `m<number>[-<commits>-g<hash>]` nor dotted numbers
//!   cannot be ordered, so it is never reported as incompatible or outdated;
//! * so are two versions of different schemes (a describe string against `1.2.3`);
//! * `1.2.3` style versions compare numerically per dot component (`v` prefix, `-suffix` and
//!   `+build` ignored);
//! * describe strings compare as `(milestone, commits since)`, so `m13-0-g..` is newer than
//!   `m12-900-g..`; a `-dirty` suffix is ignored.
//!
//! A client is `Incompatible` only when it is older than the server's `min_client_version`, and
//! an update is offered only when it is older than `latest_client_version`.

use std::cmp::Ordering;

use serde::Serialize;

use crate::api::ClientInfo;
use crate::error::{Error, Result};

/// Outcome of [`assess`].
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "status", rename_all = "snake_case")]
pub enum UpdateStatus {
    /// Nothing to do (or nothing can be told).
    UpToDate,
    /// A newer client build exists on the server.
    UpdateAvailable { latest: String },
    /// The server refuses to be talked to by something this old.
    Incompatible { min: String },
}

/// A parsed, orderable version.
#[derive(Debug, Clone, PartialEq, Eq)]
enum Parsed {
    /// `1.2.3`
    Numeric(Vec<u64>),
    /// `m12-463-g0a3b6ff[-dirty]`
    Describe { milestone: u64, since: u64 },
}

fn parse(raw: &str) -> Option<Parsed> {
    let v = raw.trim();
    if let Some(rest) = v.strip_prefix('m') {
        let mut parts = rest.split('-');
        let milestone = parts.next()?.parse::<u64>().ok()?;
        let since = match parts.next() {
            None => 0,
            Some(n) => n.parse::<u64>().ok()?,
        };
        return Some(Parsed::Describe { milestone, since });
    }
    let v = v.strip_prefix('v').unwrap_or(v);
    let core = v.split(['-', '+']).next()?;
    let nums: Option<Vec<u64>> = core.split('.').map(|p| p.parse::<u64>().ok()).collect();
    nums.filter(|n| !n.is_empty()).map(Parsed::Numeric)
}

fn order(a: &Parsed, b: &Parsed) -> Option<Ordering> {
    match (a, b) {
        (Parsed::Numeric(x), Parsed::Numeric(y)) => {
            for i in 0..x.len().max(y.len()) {
                let (p, q) = (
                    x.get(i).copied().unwrap_or(0),
                    y.get(i).copied().unwrap_or(0),
                );
                if p != q {
                    return Some(p.cmp(&q));
                }
            }
            Some(Ordering::Equal)
        }
        (
            Parsed::Describe {
                milestone: m1,
                since: s1,
            },
            Parsed::Describe {
                milestone: m2,
                since: s2,
            },
        ) => Some((m1, s1).cmp(&(m2, s2))),
        _ => None,
    }
}

/// `Some(Less)` when `client` is older than `other`; `None` when that cannot be told.
fn compare(client: &str, other: &str) -> Option<Ordering> {
    if client == other {
        return Some(Ordering::Equal);
    }
    order(&parse(client)?, &parse(other)?)
}

/// Decides what the client should tell its user. `client_version` is
/// [`crate::build_info`]`().version`, `info` the `client` block of the config (`None` from an
/// older server: nothing to compare, so up to date).
pub fn assess(client_version: &str, info: Option<&ClientInfo>) -> UpdateStatus {
    let Some(info) = info else {
        return UpdateStatus::UpToDate;
    };
    let usable = |s: &Option<String>| s.clone().filter(|v| !v.trim().is_empty());
    if let Some(min) = usable(&info.min_client_version) {
        if compare(client_version, &min) == Some(Ordering::Less) {
            return UpdateStatus::Incompatible { min };
        }
    }
    if let Some(latest) = usable(&info.latest_client_version) {
        if compare(client_version, &latest) == Some(Ordering::Less) {
            return UpdateStatus::UpdateAvailable { latest };
        }
    }
    UpdateStatus::UpToDate
}

/// The one path the client accepts as the download page of its server (`config.client.download_path`).
pub const DOWNLOAD_PATH: &str = "/talk/client";

/// The address of the download page of the server at `base_url`, for the system browser. The
/// announced path must be exactly [`DOWNLOAD_PATH`] (a missing one means that path); the result is
/// `base_url` with that path below its own base path, so it stays on the logged-in origin. https
/// only, no credentials, query or fragment. Anything else is [`Error::Usage`].
pub fn client_download_url(base_url: &str, announced: Option<&str>) -> Result<String> {
    check_download_url(base_url, announced, false)
}

/// The same check for the headless UI smoke (`selftest` builds only): a stand-in on loopback
/// speaks plain http, so that one origin may use `http`. Nothing else is relaxed.
pub fn client_download_url_loopback_http(
    base_url: &str,
    announced: Option<&str>,
) -> Result<String> {
    check_download_url(base_url, announced, true)
}

fn check_download_url(
    base_url: &str,
    announced: Option<&str>,
    loopback_http: bool,
) -> Result<String> {
    let no = || Error::Usage("Diese Adresse darf die App nicht öffnen.".into());
    if announced.unwrap_or(DOWNLOAD_PATH) != DOWNLOAD_PATH {
        return Err(no());
    }
    let base = reqwest::Url::parse(base_url).map_err(|_| no())?;
    let scheme_ok = |u: &reqwest::Url| {
        u.scheme() == "https"
            || (loopback_http
                && u.scheme() == "http"
                && matches!(u.host_str(), Some("127.0.0.1" | "localhost")))
    };
    if !scheme_ok(&base)
        || !base.username().is_empty()
        || base.password().is_some()
        || base.query().is_some()
        || base.fragment().is_some()
    {
        return Err(no());
    }
    let mut out = base.clone();
    out.set_path(&format!(
        "{}{DOWNLOAD_PATH}",
        base.path().trim_end_matches('/')
    ));
    Ok(out.into())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_download_page_is_built_on_the_login_origin_only() {
        let ok = |base, announced| client_download_url(base, announced).unwrap();
        assert_eq!(
            ok("https://lan.example.test", Some("/talk/client")),
            "https://lan.example.test/talk/client"
        );
        assert_eq!(
            ok("https://lan.example.test/", None),
            "https://lan.example.test/talk/client"
        );
        assert_eq!(
            ok("https://lan.example.test/lan/", Some("/talk/client")),
            "https://lan.example.test/lan/talk/client"
        );
    }

    #[test]
    fn anything_but_the_one_path_or_a_foreign_address_is_refused() {
        let base = "https://lan.example.test";
        for announced in [
            "/admin",
            "/talk/client/",
            "/talk/client?x=1",
            "/talk/client#x",
            "/talk/../admin",
            "/talk/client/../x",
            "//evil.example/talk/client",
            "https://evil.example/talk/client",
            "/talk/clientx",
            "",
        ] {
            assert!(
                matches!(
                    client_download_url(base, Some(announced)),
                    Err(Error::Usage(_))
                ),
                "{announced} must be refused"
            );
        }
    }

    #[test]
    fn the_base_must_be_a_plain_https_login_without_credentials_or_query() {
        let refused = |base: &str| client_download_url(base, None).is_err();
        assert!(
            refused("http://lan.example.test"),
            "plain http off loopback"
        );
        assert!(refused("ftp://lan.example.test"));
        assert!(refused("https://user:pw@lan.example.test"));
        assert!(refused("https://lan.example.test/?next=/x"));
        assert!(refused("not a url"));
        assert!(refused(""));
    }

    #[test]
    fn the_selftest_variant_allows_loopback_http_and_nothing_more() {
        let loopback = |base: &str| client_download_url_loopback_http(base, None);
        assert_eq!(
            loopback("http://127.0.0.1:8095").unwrap(),
            "http://127.0.0.1:8095/talk/client"
        );
        assert!(loopback("http://192.0.2.7:8095").is_err());
        assert!(loopback("http://lan.example.test").is_err());
    }

    fn info(min: Option<&str>, latest: Option<&str>) -> ClientInfo {
        ClientInfo {
            server_version: Some("m12-470-gabc1234".into()),
            commit: None,
            min_client_version: min.map(str::to_owned),
            latest_client_version: latest.map(str::to_owned),
            download_path: Some("/talk/client".into()),
        }
    }

    #[test]
    fn an_old_server_without_the_block_means_up_to_date() {
        assert_eq!(assess("m12-1-gaaaaaaa", None), UpdateStatus::UpToDate);
    }

    #[test]
    fn equal_versions_are_up_to_date() {
        let i = info(Some("m12-470-gabc1234"), Some("m12-470-gabc1234"));
        assert_eq!(assess("m12-470-gabc1234", Some(&i)), UpdateStatus::UpToDate);
    }

    #[test]
    fn older_describe_build_gets_an_update() {
        let i = info(None, Some("m12-470-gabc1234"));
        assert_eq!(
            assess("m12-463-g0a3b6ff", Some(&i)),
            UpdateStatus::UpdateAvailable {
                latest: "m12-470-gabc1234".into()
            }
        );
        assert_eq!(
            assess(
                "m12-900-gaaaaaaa",
                Some(&info(None, Some("m13-0-gbbbbbbb")))
            ),
            UpdateStatus::UpdateAvailable {
                latest: "m13-0-gbbbbbbb".into()
            }
        );
    }

    #[test]
    fn newer_client_than_latest_is_up_to_date() {
        let i = info(None, Some("m12-400-gaaaaaaa"));
        assert_eq!(assess("m12-463-g0a3b6ff", Some(&i)), UpdateStatus::UpToDate);
    }

    #[test]
    fn below_the_minimum_is_incompatible_and_beats_an_update() {
        let i = info(Some("m12-460-gaaaaaaa"), Some("m12-470-gabc1234"));
        assert_eq!(
            assess("m12-100-gbbbbbbb", Some(&i)),
            UpdateStatus::Incompatible {
                min: "m12-460-gaaaaaaa".into()
            }
        );
    }

    #[test]
    fn dirty_suffixes_are_ignored() {
        let i = info(Some("m12-460-gaaaaaaa"), Some("m12-470-gabc1234"));
        assert_eq!(
            assess("m12-470-gabc1234-dirty", Some(&i)),
            UpdateStatus::UpToDate
        );
        assert_eq!(
            assess("m12-463-g0a3b6ff-dirty", Some(&i)),
            UpdateStatus::UpdateAvailable {
                latest: "m12-470-gabc1234".into()
            }
        );
    }

    #[test]
    fn numeric_versions_compare_per_component() {
        let i = info(Some("1.2.0"), Some("1.10.0"));
        assert_eq!(
            assess("1.9.5", Some(&i)),
            UpdateStatus::UpdateAvailable {
                latest: "1.10.0".into()
            }
        );
        assert_eq!(
            assess("v1.1.9", Some(&i)),
            UpdateStatus::Incompatible {
                min: "1.2.0".into()
            }
        );
        assert_eq!(assess("1.10", Some(&i)), UpdateStatus::UpToDate);
        assert_eq!(assess("1.10.0+build5", Some(&i)), UpdateStatus::UpToDate);
    }

    #[test]
    fn dev_and_other_schemes_fail_open() {
        let i = info(Some("m12-460-gaaaaaaa"), Some("m12-470-gabc1234"));
        assert_eq!(assess("dev", Some(&i)), UpdateStatus::UpToDate);
        assert_eq!(assess("", Some(&i)), UpdateStatus::UpToDate);
        assert_eq!(assess("0.1.0", Some(&i)), UpdateStatus::UpToDate);
        assert_eq!(assess("custom", Some(&i)), UpdateStatus::UpToDate);
    }

    #[test]
    fn garbage_and_empty_fields_are_ignored() {
        let i = info(Some(""), Some("not a version"));
        assert_eq!(assess("m12-463-g0a3b6ff", Some(&i)), UpdateStatus::UpToDate);
    }
}
