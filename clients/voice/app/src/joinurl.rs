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

const NO_VOICE: &str = "Dieser Beitritts-Link ist nicht erlaubt.";

/// Letters, digits and a few separators: what the server lets a voice name or channel be (see
/// `VoiceJoinLink` there: names are cut to letters, digits, spaces and ` _.@|()[]-`).
fn name_char(c: char, extra: &str) -> bool {
    c.is_alphanumeric() || extra.contains(c)
}

const USER_EXTRA: &str = " _.@|()[]-";
const CHANNEL_EXTRA: &str = " _-.:()[]|+,!'&";

/// Strict percent decoding: every `%` is followed by two hex digits and the result is UTF-8.
/// A value without `%` is taken as it is (the server writes channel names raw).
fn decode(raw: &str) -> Option<String> {
    if !raw.contains('%') {
        return Some(raw.to_owned());
    }
    let b = raw.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%' {
            let hex = raw.get(i + 1..i + 3)?;
            if !hex.bytes().all(|h| h.is_ascii_hexdigit()) {
                return None;
            }
            out.push(u8::from_str_radix(hex, 16).ok()?);
            i += 3;
        } else {
            out.push(b[i]);
            i += 1;
        }
    }
    String::from_utf8(out).ok()
}

/// Everything but the unreserved characters is written as `%XX`: the link that is opened never
/// contains a space or any other character that could end the URL or add an option.
fn encode(text: &str) -> String {
    let mut out = String::with_capacity(text.len() * 2);
    for b in text.bytes() {
        if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'.' | b'_' | b'~') {
            out.push(char::from(b));
        } else {
            out.push_str(&format!("%{b:02X}"));
        }
    }
    out
}

fn voice_name(raw: &str, min: usize) -> Option<String> {
    let name = decode(raw)?;
    let n = name.chars().count();
    let ok = (min..=30).contains(&n)
        && name.trim() == name
        && name.chars().all(|c| name_char(c, USER_EXTRA));
    ok.then_some(name)
}

fn channel_segment(raw: &str) -> Option<String> {
    let seg = decode(raw)?;
    let ok = !seg.is_empty()
        && seg.chars().count() <= 64
        && seg.trim() == seg
        && seg != "."
        && seg != ".."
        && seg.chars().all(|c| name_char(c, CHANNEL_EXTRA));
    ok.then_some(seg)
}

/// A channel path as TeamSpeak carries it in one query value (`Parent/Child`, the server writes
/// the slash as `%2F`): every segment is checked like a Mumble channel segment.
fn channel_path(raw: &str) -> Option<String> {
    let path = decode(raw)?;
    let segments = path
        .split('/')
        .map(channel_segment)
        .collect::<Option<Vec<_>>>()?;
    Some(segments.join("/"))
}

/// `mumble://[name@]host[:port]/channel[/subchannel]`: no password (only the name the server put
/// in), no query, no fragment. Returns the link rewritten with every name percent-encoded.
fn validate_mumble(rest: &str) -> Option<String> {
    let (authority, path) = rest.split_once('/')?;
    let (user, hostport) = match authority.split_once('@') {
        Some((u, h)) => (Some(voice_name(u, 1)?), h),
        None => (None, authority),
    };
    let (host, port) = match hostport.rsplit_once(':') {
        Some((h, p)) => (h, Some(p)),
        None => (hostport, None),
    };
    if !valid_host(host) || port.is_some_and(|p| !valid_port(p)) {
        return None;
    }
    let segments = path
        .split('/')
        .map(channel_segment)
        .collect::<Option<Vec<_>>>()?;
    let mut out = String::from("mumble://");
    if let Some(u) = user {
        out.push_str(&encode(&u));
        out.push('@');
    }
    out.push_str(host);
    if let Some(p) = port {
        out.push(':');
        out.push_str(p);
    }
    for s in segments {
        out.push('/');
        out.push_str(&encode(&s));
    }
    Some(out)
}

/// `ts3server://host[?port=N][&nickname=NAME][&channel=NAME]`: only these three keys, each at
/// most once, in any order.
fn validate_teamspeak(rest: &str) -> Option<String> {
    let (host, query) = match rest.split_once('?') {
        Some((h, q)) => (h, Some(q)),
        None => (rest, None),
    };
    if !valid_host(host) {
        return None;
    }
    let (mut port, mut nickname, mut channel) = (None, None, None);
    if let Some(q) = query {
        for pair in q.split('&') {
            let (k, v) = pair.split_once('=')?;
            let slot_taken = match k {
                "port" => port.is_some(),
                "nickname" => nickname.is_some(),
                "channel" => channel.is_some(),
                _ => return None,
            };
            if slot_taken {
                return None;
            }
            match k {
                "port" => port = Some(valid_port(v).then(|| v.to_owned())?),
                "nickname" => nickname = Some(voice_name(v, 3)?),
                _ => channel = Some(channel_path(v)?),
            }
        }
    }
    let mut parts = Vec::new();
    if let Some(p) = port {
        parts.push(format!("port={p}"));
    }
    if let Some(n) = nickname {
        parts.push(format!("nickname={}", encode(&n)));
    }
    if let Some(c) = channel {
        parts.push(format!("channel={}", encode(&c)));
    }
    let mut out = format!("ts3server://{host}");
    if !parts.is_empty() {
        out.push('?');
        out.push_str(&parts.join("&"));
    }
    Some(out)
}

/// The voice links of the people list: `mumble://` and `ts3server://`. Anything unusual is
/// refused (credentials beyond the name, queries, fragments, control characters, a port that is
/// not a number, an odd host or channel). The link that comes back is rewritten with every name
/// percent-encoded, so it is what gets opened, never the original text.
pub fn validate_voice_join(url: &str) -> Result<String, &'static str> {
    if url.len() > 512 || url.chars().any(char::is_control) {
        return Err(NO_VOICE);
    }
    let out = if let Some(rest) = url.strip_prefix("mumble://") {
        validate_mumble(rest)
    } else if let Some(rest) = url.strip_prefix("ts3server://") {
        validate_teamspeak(rest)
    } else {
        None
    };
    out.ok_or(NO_VOICE)
}

/// Every link `open_join_url` may open: a Steam connect link as it is, or a voice link rewritten
/// by [`validate_voice_join`]. The second value names the program for the error message.
pub fn validate_join_url(url: &str) -> Result<(String, &'static str), &'static str> {
    if url.starts_with("steam://") {
        return validate_steam_connect(url).map(|u| (u.to_owned(), "Steam"));
    }
    let program = if url.starts_with("mumble://") {
        "Mumble"
    } else {
        "TeamSpeak"
    };
    validate_voice_join(url).map(|u| (u, program))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_the_voice_links_the_server_builds() {
        // `VoiceJoinLink`: raw channel names (spaces, umlauts), a rawurlencoded user name.
        for (given, opened) in [
            (
                "mumble://Ada%20L@voice.example:64738/Team Alpha",
                "mumble://Ada%20L@voice.example:64738/Team%20Alpha",
            ),
            (
                "mumble://Ada@203.0.113.9:64738/Match: Rot gegen Blau",
                "mumble://Ada@203.0.113.9:64738/Match%3A%20Rot%20gegen%20Blau",
            ),
            (
                "mumble://voice.example/Lobby",
                "mumble://voice.example/Lobby",
            ),
            (
                "mumble://K%C3%A4the@voice.example:64738/Lounge/Zimmer 2",
                "mumble://K%C3%A4the@voice.example:64738/Lounge/Zimmer%202",
            ),
            (
                "mumble://Ada@voice.example:64738/B%C3%BCro",
                "mumble://Ada@voice.example:64738/B%C3%BCro",
            ),
            (
                "ts3server://203.0.113.9?port=9987&nickname=Ada%20L&channel=Team%20Alpha",
                "ts3server://203.0.113.9?port=9987&nickname=Ada%20L&channel=Team%20Alpha",
            ),
            (
                "ts3server://voice.example?port=9987&channel=Lobby",
                "ts3server://voice.example?port=9987&channel=Lobby",
            ),
            ("ts3server://voice.example", "ts3server://voice.example"),
            (
                "ts3server://voice.example?port=9987&channel=Team%20Alpha%2FZimmer%202",
                "ts3server://voice.example?port=9987&channel=Team%20Alpha%2FZimmer%202",
            ),
        ] {
            assert_eq!(validate_voice_join(given).as_deref(), Ok(opened), "{given}");
        }
        assert_eq!(
            validate_join_url("steam://connect/1.2.3.4:27015")
                .unwrap()
                .1,
            "Steam"
        );
        assert_eq!(
            validate_join_url("mumble://voice.example/Lobby").unwrap().1,
            "Mumble"
        );
        assert_eq!(
            validate_join_url("ts3server://voice.example").unwrap().1,
            "TeamSpeak"
        );
    }

    #[test]
    fn the_opened_voice_link_never_contains_whitespace() {
        let out = validate_voice_join("mumble://Ada@voice.example:64738/Team Alpha").unwrap();
        assert!(!out.contains(char::is_whitespace));
    }

    #[test]
    fn refuses_every_unusual_voice_link() {
        for bad in [
            "",
            "mumble://",
            "mumble://voice.example",
            "mumble://voice.example/",
            "mumble://Ada:secret@voice.example/Lobby",
            "mumble://:secret@voice.example/Lobby",
            "mumble://a@b@voice.example/Lobby",
            "mumble://Ada@voice.example:0/Lobby",
            "mumble://Ada@voice.example:65536/Lobby",
            "mumble://Ada@voice.example:12ab/Lobby",
            "mumble://Ada@voice.example:/Lobby",
            "mumble://Ada@-bad.example/Lobby",
            "mumble://Ada@bad..example/Lobby",
            "mumble://Ada@[::1]:64738/Lobby",
            "mumble://Ada@voice.example/Lobby?version=1.2.0",
            "mumble://Ada@voice.example/Lobby#frag",
            "mumble://Ada@voice.example/Lobby\\x",
            "mumble://Ada@voice.example/Lobby\n",
            "mumble://Ada@voice.example/Lobby\t",
            "mumble://Ada@voice.example/ Lobby",
            "mumble://Ada@voice.example/Lobby /x",
            "mumble://Ada@voice.example/../etc",
            "mumble://Ada@voice.example/./x",
            "mumble://Ada@voice.example//x",
            "mumble://Ada@voice.example/%2e%2e",
            "mumble://Ada@voice.example/%zz",
            "mumble://Ada@voice.example/%",
            "mumble://Ada@voice.example/%ff",
            "mumble://Ada@voice.example/a%00b",
            "mumble://Ada@voice.example/a<b",
            "mumble://Ada@voice.example/a;b",
            "mumble://Ada@voice.example/a\"b",
            "mumble://Ada@voice.example/a\\b",
            "MUMBLE://Ada@voice.example/Lobby",
            "mumble:Ada@voice.example/Lobby",
            "mumble://Ada%20@voice.example/Lobby",
            "mumble://Ad%2Fa@voice.example/Lobby",
            "ts3server://",
            "ts3server://voice.example?port=0",
            "ts3server://voice.example?port=99999",
            "ts3server://voice.example?port=9987&port=9988",
            "ts3server://voice.example?password=hunter2",
            "ts3server://voice.example?port=9987&addbookmark=x",
            "ts3server://voice.example?nickname=ab",
            "ts3server://voice.example?channel=a%2F..",
            "ts3server://voice.example?channel=a%2F%2Fb",
            "ts3server://voice.example?channel=%2Fa",
            "ts3server://voice.example?channel=",
            "ts3server://voice.example?port",
            "ts3server://voice.example?port=9987&",
            "ts3server://voice.example?port=9987#x",
            "ts3server://user@voice.example?port=9987",
            "ts3server://voice.example:9987?channel=Lobby",
            "ts3server://voice.example/path?port=9987",
            "ts3server://voice.example?channel=Lobby\n",
            "discord://discord.com/channels/1/2",
            "https://discord.com/channels/1/2",
            "file:///etc/passwd",
            "steam://connect/1.2.3.4:27015",
            "--help",
        ] {
            assert!(validate_voice_join(bad).is_err(), "{bad:?}");
        }
        let long = format!("mumble://voice.example/{}", "a".repeat(600));
        assert!(validate_voice_join(&long).is_err());
        let long_seg = format!("mumble://voice.example/{}", "a".repeat(65));
        assert!(validate_voice_join(&long_seg).is_err());
        let long_name = format!("mumble://{}@voice.example/Lobby", "a".repeat(31));
        assert!(validate_voice_join(&long_name).is_err());
        // Other steam commands and other schemes stay refused by the combined entry point.
        for bad in ["steam://run/730", "steam://connect/x", "ssh://host", "x"] {
            assert!(validate_join_url(bad).is_err(), "{bad}");
        }
    }

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
