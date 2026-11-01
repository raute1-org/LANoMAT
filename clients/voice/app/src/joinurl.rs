//! Strict validation of the game server join link before it is handed to the system's URL
//! handler. The webview is untrusted: the only thing it may make the OS open is
//! `steam://connect/<host>:<port>` or `steam://connect/<host>:<port>/<password>` with a plain
//! host name or IPv4 address, a numeric port and an unreserved-character password. Anything else
//! (other schemes, other steam commands such as `steam://run/...` or `steam://install/...`,
//! queries, fragments, userinfo, whitespace, encodings, option-looking strings) is refused.

const PREFIX: &str = "steam://connect/";

fn valid_host(h: &str) -> bool {
    if h.is_empty() || h.len() > 253 {
        return false;
    }
    h.split('.').all(|label| {
        !label.is_empty()
            && label.len() <= 63
            && !label.starts_with('-')
            && !label.ends_with('-')
            && label
                .bytes()
                .all(|b| b.is_ascii_alphanumeric() || b == b'-')
    })
}

fn valid_port(p: &str) -> bool {
    !p.is_empty()
        && p.len() <= 5
        && p.bytes().all(|b| b.is_ascii_digit())
        && p.parse::<u32>().is_ok_and(|n| (1..=65535).contains(&n))
}

fn valid_password(p: &str) -> bool {
    !p.is_empty()
        && p.len() <= 64
        && p.bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'~' | b'-'))
}

/// Returns the link unchanged when it is acceptable, else a German reason.
pub fn validate_steam_connect(url: &str) -> Result<&str, &'static str> {
    const NO: &str = "Dieser Beitritts-Link ist nicht erlaubt.";
    let rest = url.strip_prefix(PREFIX).ok_or(NO)?;
    let (hostport, password) = match rest.split_once('/') {
        Some((hp, pw)) => (hp, Some(pw)),
        None => (rest, None),
    };
    let (host, port) = hostport.rsplit_once(':').ok_or(NO)?;
    if !valid_host(host) || !valid_port(port) {
        return Err(NO);
    }
    if let Some(pw) = password {
        if !valid_password(pw) {
            return Err(NO);
        }
    }
    Ok(url)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_the_links_the_server_sends() {
        for ok in [
            "steam://connect/203.0.113.5:27015",
            "steam://connect/game.lan.example:27016",
            "steam://connect/10.0.0.7:7777/hunter2",
            "steam://connect/my-host:1/p.w_d~x-1",
        ] {
            assert_eq!(validate_steam_connect(ok), Ok(ok), "{ok}");
        }
    }

    #[test]
    fn refuses_everything_else() {
        for bad in [
            "",
            "steam://connect/",
            "steam://connect/203.0.113.5",
            "steam://connect/203.0.113.5:",
            "steam://connect/203.0.113.5:0",
            "steam://connect/203.0.113.5:65536",
            "steam://connect/203.0.113.5:12ab",
            "steam://connect/203.0.113.5:+80",
            "steam://run/730",
            "steam://install/730",
            "steam://store/730",
            "steam://rungameid/730",
            "STEAM://connect/1.2.3.4:27015",
            "steam:connect/1.2.3.4:27015",
            "steam://connect//1.2.3.4:27015",
            "steam://connect/1.2.3.4:27015?x=1",
            "steam://connect/1.2.3.4:27015#f",
            "steam://connect/user@1.2.3.4:27015",
            "steam://connect/1.2.3.4:27015/pw word",
            "steam://connect/1.2.3.4:27015/pw%20x",
            "steam://connect/1.2.3.4:27015/",
            "steam://connect/1.2.3.4:27015/a/b",
            "steam://connect/-bad.example:27015",
            "steam://connect/bad..example:27015",
            "steam://connect/[::1]:27015",
            "steam://connect/1.2.3.4:27015\n",
            "steam://connect/1.2.3.4:27015 --option",
            "https://example.org/steam://connect/1.2.3.4:27015",
            "file:///etc/passwd",
            "--help",
        ] {
            assert!(validate_steam_connect(bad).is_err(), "{bad:?}");
        }
        let long = format!("steam://connect/{}:27015", "a".repeat(300));
        assert!(validate_steam_connect(&long).is_err());
        let long_pw = format!("steam://connect/1.2.3.4:27015/{}", "a".repeat(65));
        assert!(validate_steam_connect(&long_pw).is_err());
    }
}
