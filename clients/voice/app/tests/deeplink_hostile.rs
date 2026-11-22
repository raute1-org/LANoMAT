//! SPIKE (all-in-one client, phase 0): hostile `lanomat://` inputs against the parser that was
//! copied from the launcher. Browser-supplied strings must never produce an action unless they are
//! exactly install|launch|join (+ the `play` alias) with a `[a-z0-9-]{1,120}` slug.

use lanomat_voice_app::deeplink::{parse_deep_link, DeepLinkAction};

#[test]
fn hostile_inputs_are_refused() {
    for raw in [
        "lanomat://install/ut2004\r\nHost: evil",
        "lanomat://install/ut2004\0",
        "lanomat://install//ut2004",
        "lanomat://install/ut2004;calc.exe",
        "lanomat://install/ut2004&x=1",
        "lanomat://install/%75t2004",
        "lanomat://install/ut2004%2f..%2f",
        "lanomat://install/C:\\Windows\\system32",
        "lanomat://install/-rf",
        "lanomat://exec/ut2004",
        "lanomat://install@evil.example/ut2004",
        "javascript:lanomat://install/ut2004",
        "lanomat-x://install/ut2004",
        "lanomat\u{200b}://install/ut2004",
    ] {
        // `-rf` is a syntactically valid slug (the server decides if it exists); all others must fail.
        if raw == "lanomat://install/-rf" {
            assert!(parse_deep_link(raw).is_some());
            continue;
        }
        assert!(parse_deep_link(raw).is_none(), "{raw:?} must be refused");
    }
}

#[test]
fn a_hostile_join_payload_is_dropped_but_the_slug_survives() {
    let link = parse_deep_link(
        "lanomat://join/ut2004?address=1.2.3.4%3A7777%3Bcalc&url=file%3A%2F%2F%2Fc%3A%2Fx",
    )
    .unwrap();
    assert_eq!(link.action, DeepLinkAction::Join);
    assert_eq!(link.address, None);
    assert_eq!(link.url, None);
}

#[test]
fn an_overlong_input_does_not_panic() {
    let long = format!("lanomat://install/{}", "a".repeat(100_000));
    assert!(parse_deep_link(&long).is_none());
}
