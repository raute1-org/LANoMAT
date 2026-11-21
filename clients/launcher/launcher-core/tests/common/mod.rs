//! Shared fixtures for the launcher-core integration tests.
//!
//! Every JSON body below is copied from the documented server contract
//! (`POST /api/mcp/device`, `POST /api/mcp/token`, `GET /api/v1/me`,
//! `GET /api/v1/recipes`, `POST /api/v1/recipes/{slug}/plan`), including the
//! fields the launcher ignores.

#![allow(dead_code)]

use serde_json::{json, Value};
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

pub const DEVICE_CODE: &str = "dev-123-do-not-log";
pub const USER_CODE: &str = "ABCD-EFGH";
pub const ACCESS_TOKEN: &str = "launcher-secret-token";

/// The server's base URL for these tests, including a path prefix so every
/// request also proves the prefix survives normalisation.
pub fn base_url(server: &MockServer) -> String {
    format!("{}/lanomat", server.uri())
}

pub fn endpoint(route: &str) -> String {
    format!("/lanomat{route}")
}

pub fn device_start_body() -> Value {
    json!({
        "device_code": DEVICE_CODE,
        "user_code": USER_CODE,
        "verification_uri": "https://lan.test/mcp/connect",
        "verification_uri_complete": "https://lan.test/mcp/connect?code=ABCD-EFGH",
        "expires_in": 600,
        "interval": 5
    })
}

pub fn token_body() -> Value {
    json!({
        "access_token": ACCESS_TOKEN,
        "token_type": "Bearer",
        "expires_in": 2_592_000
    })
}

pub fn me_body() -> Value {
    json!({
        "user": {
            "id": 7,
            "name": "Ada",
            "role": "participant",
            "is_orga": false
        },
        "server": {
            "name": "LANoMAT",
            "url": "https://lan.test/",
            "version": "1.4.0",
            "mcp_url": "https://lan.test/mcp",
            "api_version": "1"
        },
        "agent": { "default_model": "local-agent" },
        "modules": ["GameRecipes", "Files", "Tournaments"]
    })
}

pub fn recipes_body() -> Value {
    json!({
        "data": [
            {
                "slug": "unreal-tournament-2004",
                "title": "Unreal Tournament 2004",
                "summary": "Arena shooter from 2004.",
                "version": "3374",
                "status": "published",
                "platforms": ["windows", "linux"],
                "updated_at": "2026-11-20T10:00:00+00:00"
            },
            {
                "slug": "unreal-tournament-99",
                "title": "Unreal Tournament",
                "summary": null,
                "version": null,
                "status": "draft",
                "platforms": []
            }
        ]
    })
}

/// The documented `/api/v1/servers` aggregate: one event server with a query
/// answer and one permanent offline server, plus fields the launcher ignores.
pub fn servers_body() -> Value {
    json!({
        "events": [
            {
                "slug": "lan-2026",
                "name": "LAN 2026",
                "status": "live",
                "starts_at": "2026-11-20T18:00:00+00:00"
            }
        ],
        "servers": [
            {
                "id": 11,
                "title": "Survival",
                "game": "Minecraft",
                "matchLabel": null,
                "description": "Whitelist off",
                "address": "203.0.113.9",
                "port": 25565,
                "connectString": "203.0.113.9:25565",
                "connectIsLink": false,
                "status": "ready",
                "query": {
                    "serverName": "Survival",
                    "map": "world",
                    "players": 3,
                    "maxPlayers": 20,
                    "checkedAt": "2026-11-21T12:00:00+00:00"
                },
                "estimate": { "ramMb": 2048, "maxRamMb": 8192, "overCap": false },
                "installHint": null,
                "joinOptions": [],
                "permanent": false,
                "event": { "slug": "lan-2026", "name": "LAN 2026", "status": "live" },
                "source": null
            },
            {
                "id": 12,
                "title": "UT2004 Dauerwelt",
                "game": "Unreal Tournament 2004",
                "address": "203.0.113.10",
                "port": 7777,
                "connectString": "steam://connect/203.0.113.10:7777",
                "connectIsLink": true,
                "status": "offline",
                "query": null,
                "joinOptions": [
                    {
                        "key": "steam",
                        "label": "Mit Steam beitreten",
                        "url": "steam://connect/203.0.113.10:7777",
                        "hint": "Startet Steam und verbindet."
                    }
                ],
                "permanent": true,
                "event": null,
                "source": "external",
                "description": null
            }
        ]
    })
}

/// The full plan shape `BuildInstallPlan` returns; `downloads` and
/// `generated_at` are fields the launcher does not model and must ignore.
pub fn plan_body() -> Value {
    json!({
        "recipe": {
            "slug": "unreal-tournament-2004",
            "title": "Unreal Tournament 2004",
            "version": "3374",
            "skill": "unreal-tournament-2004",
            "launch_target": "System/ut2004-bin-amd64",
            "launch_targets": [
                {
                    "id": "flat-linux",
                    "label": "Flat",
                    "os": "linux",
                    "command": "./ut2004-bin-amd64",
                    "target": "System/ut2004-bin-amd64",
                    "shell": "bash"
                }
            ],
            "launch_command": "./ut2004-bin-amd64",
            "play_url": "https://lan.test/games/unreal-tournament-2004"
        },
        "os": "linux",
        "target_dir": "~/Games/unreal-tournament-2004",
        "downloads_dir": "~/Games/unreal-tournament-2004/.lanomat-downloads",
        "steps": [
            {
                "id": "extract",
                "title": "Extract",
                "os": "any",
                "optional": false,
                "detail": "Unzip the portable archive.",
                "commands": [{ "shell": "bash", "run": "unzip" }],
                "verify": ["System/UT2004.exe"]
            }
        ],
        "files": [
            {
                "recipe_file_id": 1,
                "requirement": "iso",
                "label": "Retail ISO",
                "status": "matched",
                "required": true,
                "source": "lan",
                "name": "ut2004.iso",
                "size_bytes": 5_368_709_120_u64,
                "sha256": "a".repeat(64),
                "shared_file_id": 42,
                "download_url": "https://lan.test/api/v1/files/42/download",
                "fallback_url": null
            },
            {
                "recipe_file_id": 2,
                "requirement": "patch",
                "label": "OldUnreal 3374 patch",
                "status": "missing",
                "required": true,
                "source": null
            },
            {
                "recipe_file_id": 3,
                "requirement": "archive",
                "label": "Portable build",
                "status": "external",
                "required": false,
                "source": "external",
                "name": "ut2004-portable-3374.zip",
                "size_bytes": 1_200_000_000_u64,
                "sha256": null,
                "fallback_url": "https://files.example.test/ut2004-portable-3374.zip"
            }
        ],
        "downloads": [
            { "requirement": "iso", "status": "matched" }
        ],
        "avatars": [
            {
                "id": "hug-bot",
                "label": "Hug Bot",
                "size_bytes": 1024,
                "sha256": null,
                "download_url": "https://lan.test/api/v1/avatars/hug-bot.vrm",
                "available_on_lan": true
            }
        ],
        "missing": ["OldUnreal 3374 patch"],
        "generated_at": "2026-11-21T12:00:00+00:00"
    })
}

/// Mount a JSON response for one verb + route.
pub async fn mock_json(server: &MockServer, verb: &str, route: &str, status: u16, body: Value) {
    Mock::given(method(verb))
        .and(path(route))
        .respond_with(ResponseTemplate::new(status).set_body_json(body))
        .mount(server)
        .await;
}

/// The `Authorization` header of the only recorded request, when present.
pub fn bearer_of(request: &wiremock::Request) -> Option<String> {
    request
        .headers
        .get("authorization")
        .and_then(|value| value.to_str().ok())
        .map(str::to_string)
}
