//! Wire-type tolerance: a newer server may omit optional RFC 8628 fields, add
//! fields, or hand out a string id. None of that may break an installed
//! launcher.

use launcher_core::types::{DeviceStart, Os, PlanFile, RecipePlan, TokenGrant};
use serde_json::json;

#[test]
fn device_start_defaults_interval_and_ttl() {
    let device: DeviceStart = serde_json::from_value(json!({
        "device_code": "d",
        "user_code": "ABCD-EFGH",
        "verification_uri": "https://lan.test/mcp/connect"
    }))
    .unwrap();

    assert_eq!(device.interval, 5);
    assert_eq!(device.expires_in, 600);
    assert!(device.verification_uri_complete.is_none());
}

#[test]
fn token_grant_ignores_unknown_fields_and_redacts_debug() {
    let grant: TokenGrant = serde_json::from_value(json!({
        "access_token": "secret",
        "token_type": "Bearer",
        "expires_in": 3600,
        "scope": "mcp",
        "refresh_token": "never"
    }))
    .unwrap();

    assert_eq!(grant.access_token, "secret");
    assert_eq!(grant.token_type, "Bearer");
    assert!(!format!("{grant:?}").contains("secret"));
}

#[test]
fn a_plan_with_only_an_external_archive_is_still_downloadable() {
    let plan: RecipePlan = serde_json::from_value(json!({
        "recipe": {"slug": "ut2004", "title": "UT2004"},
        "target_dir": "~/Games/ut2004",
        "files": [
            {
                "requirement": "archive",
                "status": "external",
                "label": "Portable build",
                "fallback_url": "https://files.example.test/ut2004.zip"
            },
            {
                "requirement": "patch",
                "status": "manual",
                "label": "Optional patch"
            }
        ]
    }))
    .unwrap();

    // `matched` is the LAN-host predicate; `downloadable` also accepts the
    // documented external status.
    assert!(plan.matched("archive").is_none());
    let archive = plan
        .downloadable("archive")
        .expect("an external archive is usable");
    assert_eq!(archive.status, "external");
    assert_eq!(
        archive.fallback_url.as_deref(),
        Some("https://files.example.test/ut2004.zip")
    );
    assert!(plan.downloadable("patch").is_none());
    assert!(plan.missing_required().is_empty());
}

#[test]
fn a_file_without_any_url_is_not_downloadable() {
    let plan: RecipePlan = serde_json::from_value(json!({
        "recipe": {"slug": "ut2004", "title": "UT2004"},
        "target_dir": "~/Games/ut2004",
        "files": [
            {"requirement": "iso", "status": "matched", "label": "ISO", "required": true}
        ]
    }))
    .unwrap();

    assert!(plan.matched("iso").is_none());
    assert!(plan.downloadable("iso").is_none());
    // `matched` without a source is still not "missing": the server decides.
    assert!(plan.missing_required().is_empty());
    assert!(plan.files[0].required);
}

#[test]
fn plan_files_keep_unknown_fields_out_but_all_known_ones_in() {
    let file: PlanFile = serde_json::from_value(json!({
        "requirement": "iso",
        "status": "matched",
        "label": "Retail ISO",
        "os": "any",
        "name": "ut2004.iso",
        "required": true,
        "size_bytes": 5_368_709_120_u64,
        "sha256": null,
        "download_url": "https://lan.test/api/v1/files/42/download",
        "fallback_url": null,
        "source": "lan",
        "shared_file_id": 42,
        "recipe_file_id": 1
    }))
    .unwrap();

    assert_eq!(file.requirement, "iso");
    assert_eq!(file.status, "matched");
    assert_eq!(file.size_bytes, Some(5_368_709_120));
    assert_eq!(file.os.as_deref(), Some("any"));
    assert!(file.sha256.is_none());
    assert!(file.download_url.is_some());
}

#[test]
fn os_round_trips_through_its_wire_names() {
    for os in [Os::Windows, Os::Linux, Os::Macos] {
        let wire = serde_json::to_value(os).unwrap();
        assert_eq!(wire, json!(os.as_str()));
        assert_eq!(serde_json::from_value::<Os>(wire).unwrap(), os);
    }
}
