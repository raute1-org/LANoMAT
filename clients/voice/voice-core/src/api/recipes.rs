//! Game library and install plan (module GameRecipes, `features.launcher`): the catalogue, one
//! recipe and its plan, over the same bearer client as everything else. See "Recipes" in
//! `docs/infra/native-voice-client-api.md`.
//!
//! Rules this module enforces:
//! - A field the server did not send is unknown, never "no" (`installable`, `launcher_supported`
//!   are `Option<bool>`); unknown fields are ignored.
//! - A plan with a `plan_version` above [`SUPPORTED_PLAN_VERSION`] is refused before anything else
//!   is read from it ([`Error::PlanVersionUnsupported`]): "update the app".
//! - A plan carries data only. The server's `steps[].commands` and `verify` lists are not even
//!   parsed: the client never runs a command from a recipe.
//! - Signed download links are secrets. `Debug` of a [`PlanFile`] shows the URL without its query
//!   and no error text contains one.

use std::fmt;

use serde::{Deserialize, Serialize};
use serde_json::Value;
use sha2::{Digest, Sha256};

use super::{seg, ApiClient};
use crate::error::{Error, Result};

/// The newest `plan_version` this client understands. A breaking plan format raises it on the
/// server; additive changes do not.
pub const SUPPORTED_PLAN_VERSION: u32 = 1;

/// The operating system a plan is requested for (`os` query parameter).
pub fn current_os() -> &'static str {
    if cfg!(windows) {
        "windows"
    } else if cfg!(target_os = "macos") {
        "macos"
    } else {
        "linux"
    }
}

/// The store ids a recipe is known by (`metadata.store_ids`): the explicit link between a recipe
/// and a game installed through a store. Absent or malformed means no link.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq, Eq)]
#[serde(default)]
pub struct StoreIds {
    pub steam: Option<String>,
    pub epic: Option<String>,
    pub gog: Option<String>,
    pub ubisoft: Option<String>,
}

/// A server that sends `[]` (an empty PHP array) or anything but an object has no store ids.
fn lenient_store_ids<'de, D>(d: D) -> std::result::Result<StoreIds, D::Error>
where
    D: serde::Deserializer<'de>,
{
    let v = Value::deserialize(d)?;
    Ok(serde_json::from_value(v).unwrap_or_default())
}

/// One catalogue entry (`GET recipes`) and the head of a [`RecipeDetail`].
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct RecipeSummary {
    pub slug: String,
    pub title: String,
    pub summary: Option<String>,
    pub version: Option<String>,
    pub status: Option<String>,
    pub platforms: Vec<String>,
    pub genre: Option<String>,
    /// Text such as `"2-16"`.
    pub players: Option<String>,
    pub players_max: Option<u32>,
    /// The sum of the file sizes the recipe declares; `None` when it declares none.
    pub size_bytes: Option<u64>,
    /// The recipe carries everything an install needs. `None`: an older server, unknown.
    pub installable: Option<bool>,
    /// The server's verdict; whether this client build can drive the recipe is the client's call.
    /// `None`: unknown.
    pub launcher_supported: Option<bool>,
    /// `None`: the server does not say.
    pub in_lineup: Option<bool>,
    /// A browser recipe's entry point; it has nothing to install.
    pub play_url: Option<String>,
    pub updated_at: Option<String>,
    #[serde(deserialize_with = "lenient_store_ids")]
    pub store_ids: StoreIds,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct LaunchTarget {
    pub id: String,
    pub label: Option<String>,
    pub os: Option<String>,
    pub target: Option<String>,
}

/// `GET recipes/{slug}`: the summary plus the long form.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct RecipeDetail {
    #[serde(flatten)]
    pub summary: RecipeSummary,
    pub description: Option<String>,
    pub aliases: Vec<String>,
    pub homepage: Option<String>,
    pub license: Option<String>,
    pub portable: Option<bool>,
    pub supports_multiplayer: Option<bool>,
    pub launch_target: Option<String>,
    pub launch_targets: Vec<LaunchTarget>,
}

#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PlanRecipe {
    pub slug: String,
    pub title: String,
    pub version: Option<String>,
    pub launch_target: Option<String>,
    pub launch_targets: Vec<LaunchTarget>,
    pub play_url: Option<String>,
}

/// A step is shown, never executed: only its title and text are read.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PlanStep {
    pub id: String,
    pub title: String,
    pub os: Option<String>,
    pub optional: bool,
    pub detail: Option<String>,
}

/// One file of a plan. `status` is `matched` (a file on the LAN host), `external` (the recipe's
/// own public URL), `manual` (no download) or `missing`.
#[derive(Clone, Default, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct PlanFile {
    pub recipe_file_id: Option<u64>,
    pub requirement: String,
    pub label: Option<String>,
    pub os: Option<String>,
    pub status: String,
    pub required: bool,
    /// `lan`, `external` or `manual`; `None` for a file nothing provides.
    pub source: Option<String>,
    pub name: Option<String>,
    pub size_bytes: Option<u64>,
    /// 64 hex characters. `None` for a LAN file the server has not hashed yet, or an external
    /// file the recipe does not pin. The install engine refuses an external file without it.
    pub sha256: Option<String>,
    pub shared_file_id: Option<u64>,
    /// A signed, short-lived file-host URL (LAN) or the recipe's public URL (external).
    pub download_url: Option<String>,
    /// When the signed link stops working (RFC 3339): fetch the plan again before a long queue.
    pub download_expires_at: Option<String>,
    pub fallback_url: Option<String>,
}

impl fmt::Debug for PlanFile {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("PlanFile")
            .field("requirement", &self.requirement)
            .field("label", &self.label)
            .field("status", &self.status)
            .field("required", &self.required)
            .field("source", &self.source)
            .field("name", &self.name)
            .field("size_bytes", &self.size_bytes)
            .field("sha256", &self.sha256)
            .field(
                "download_url",
                &self.download_url.as_deref().map(redact_url),
            )
            .field("download_expires_at", &self.download_expires_at)
            .field(
                "fallback_url",
                &self.fallback_url.as_deref().map(redact_url),
            )
            .finish()
    }
}

/// The URL without query and fragment: a signed link carries its signature there.
fn redact_url(url: &str) -> String {
    let end = url.find(['?', '#']).unwrap_or(url.len());
    format!("{}<redacted>", &url[..end])
}

impl PlanFile {
    /// The name the UI shows for this file.
    pub fn display_name(&self) -> String {
        self.label
            .clone()
            .or_else(|| self.name.clone())
            .unwrap_or_else(|| self.requirement.clone())
    }

    fn on_lan(&self) -> bool {
        self.source.as_deref() == Some("lan") && self.status == "matched"
    }
}

/// Where a recipe's required files are, as far as the plan says.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Availability {
    /// Every required file is on the LAN file host.
    OnLan,
    /// At least one required file is missing.
    Missing,
    /// No plan, a plan without required files, or files that come from outside the LAN.
    Unknown,
}

#[derive(Debug, Clone, Deserialize, Serialize, PartialEq)]
#[serde(default)]
pub struct RecipePlan {
    pub plan_version: u32,
    pub recipe: PlanRecipe,
    pub os: String,
    pub target_dir: String,
    pub downloads_dir: Option<String>,
    pub steps: Vec<PlanStep>,
    pub files: Vec<PlanFile>,
    pub missing: Vec<String>,
    pub generated_at: Option<String>,
}

impl Default for RecipePlan {
    fn default() -> Self {
        Self {
            plan_version: 1,
            recipe: PlanRecipe::default(),
            os: String::new(),
            target_dir: String::new(),
            downloads_dir: None,
            steps: Vec::new(),
            files: Vec::new(),
            missing: Vec::new(),
            generated_at: None,
        }
    }
}

impl RecipePlan {
    /// Parses a plan document. The version is checked on the raw JSON first, so a plan in a
    /// future shape is refused for what it is and not as a parse error.
    pub fn parse(bytes: &[u8]) -> Result<Self> {
        let value: serde_json::Value = serde_json::from_slice(bytes)
            .map_err(|e| Error::BadResponse(format!("plan is not JSON: {e}")))?;
        // A plan without the field predates versioning: version 1.
        let found = match value.get("plan_version") {
            None | Some(serde_json::Value::Null) => 1,
            Some(v) => v
                .as_u64()
                .and_then(|n| u32::try_from(n).ok())
                .ok_or_else(|| Error::BadResponse("plan_version is not a whole number".into()))?,
        };
        if found > SUPPORTED_PLAN_VERSION {
            return Err(Error::PlanVersionUnsupported {
                found,
                supported: SUPPORTED_PLAN_VERSION,
            });
        }
        let mut plan: RecipePlan = serde_json::from_value(value)
            .map_err(|e| Error::BadResponse(format!("unreadable plan: {e}")))?;
        plan.plan_version = found;
        Ok(plan)
    }

    /// See [`Availability`]. A required file counts as on the LAN when its source is `lan` and it
    /// is matched; anything the plan lists as missing makes the answer `Missing`.
    pub fn availability(&self) -> Availability {
        let required: Vec<&PlanFile> = self.files.iter().filter(|f| f.required).collect();
        if !self.missing.is_empty() || required.iter().any(|f| f.status == "missing") {
            return Availability::Missing;
        }
        if !required.is_empty() && required.iter().all(|f| f.on_lan()) {
            return Availability::OnLan;
        }
        Availability::Unknown
    }

    /// A digest of what the plan installs: which files with which checksums. Signed URLs, expiry
    /// and `generated_at` are left out, so two plans for the same content hash the same.
    pub fn content_hash(&self) -> String {
        let mut hasher = Sha256::new();
        let mut line = |parts: &[&str]| {
            for p in parts {
                hasher.update(p.as_bytes());
                hasher.update([0]);
            }
            hasher.update([b'\n']);
        };
        line(&[
            &self.recipe.slug,
            self.recipe.version.as_deref().unwrap_or(""),
            &self.os,
        ]);
        let mut files: Vec<_> = self
            .files
            .iter()
            .map(|f| {
                (
                    f.requirement.clone(),
                    f.name.clone().unwrap_or_default(),
                    f.size_bytes.unwrap_or(0).to_string(),
                    f.sha256.clone().unwrap_or_default(),
                    f.source.clone().unwrap_or_default(),
                )
            })
            .collect();
        files.sort();
        for (a, b, c, d, e) in &files {
            line(&[a, b, c, d, e]);
        }
        hasher
            .finalize()
            .iter()
            .map(|b| format!("{b:02x}"))
            .collect()
    }
}

#[derive(Deserialize)]
struct RecipesResponse {
    #[serde(default)]
    recipes: Vec<RecipeSummary>,
}

#[derive(Deserialize)]
struct RecipeResponse {
    recipe: RecipeDetail,
}

impl ApiClient {
    /// `GET recipes`: published recipes in the lineup order. `NotFound` means the GameRecipes
    /// module is off (or the server predates it).
    pub async fn recipes(&self) -> Result<Vec<RecipeSummary>> {
        let r: RecipesResponse = self.get_v1("recipes", &[]).await?;
        Ok(r.recipes)
    }

    /// `GET recipes/{slug}`. Only the exact slug resolves (no aliases).
    pub async fn recipe(&self, slug: &str) -> Result<RecipeDetail> {
        let r: RecipeResponse = self.get_v1(&format!("recipes/{}", seg(slug)?), &[]).await?;
        Ok(r.recipe)
    }

    /// `GET recipes/{slug}/plan`. With `dry_run` the server does not record the request in the
    /// orga's install audit: always set it for browsing, never for a real install. Rate limited
    /// to 30 per minute per token. A plan newer than [`SUPPORTED_PLAN_VERSION`] is an error.
    pub async fn recipe_plan(
        &self,
        slug: &str,
        os: &str,
        event_slug: Option<&str>,
        dry_run: bool,
    ) -> Result<RecipePlan> {
        let mut query = vec![("os", os.to_owned())];
        if let Some(event) = event_slug.filter(|e| !e.is_empty()) {
            query.push(("event_slug", seg(event)?));
        }
        if dry_run {
            query.push(("dry_run", "1".to_owned()));
        }
        let resp = self
            .raw_v1(
                reqwest::Method::GET,
                &format!("recipes/{}/plan", seg(slug)?),
            )?
            .query(&query)
            .send()
            .await
            .map_err(super::client::net_err)?;
        let bytes = super::client::read_ok_body(resp).await?;
        RecipePlan::parse(&bytes)
    }
}

#[cfg(test)]
mod tests {
    use serde_json::{json, Value};
    use wiremock::matchers::{header, method, path, query_param};
    use wiremock::{Mock, MockServer, ResponseTemplate};

    use super::*;

    /// The shapes of `tests/Feature/WebVoice/NativeClient/RecipesApiTest.php`, with example hosts.
    fn summary_json() -> Value {
        json!({
            "slug": "ut2004-test", "title": "UT Test", "summary": "Arena shooter",
            "version": "1.0", "status": "published", "platforms": ["windows", "linux"],
            "genre": "fps", "players": "2-16", "players_max": 16, "size_bytes": 4096,
            "installable": true, "launcher_supported": true, "in_lineup": true,
            "play_url": null, "updated_at": "2026-11-22T11:50:00+00:00"
        })
    }

    fn plan_json() -> Value {
        json!({
            "plan_version": 1,
            "recipe": {"slug": "ut2004-test", "title": "UT Test", "version": "1.0",
                "skill": "install-ut2004-test", "launch_target": "{{target}}/Game.exe",
                "launch_targets": [], "launch_command": "...", "play_url": null},
            "os": "windows", "target_dir": "<dir>", "downloads_dir": "<dir>/.lanomat-downloads",
            "steps": [{"id": "check-install", "title": "Check", "os": "any", "optional": false,
                "detail": "...", "commands": [{"shell": "rm -rf /"}], "verify": []}],
            "files": [{
                "recipe_file_id": 3, "requirement": "base", "label": "Portable archive",
                "os": "any", "status": "matched", "required": true, "source": "lan",
                "name": "game-portable.zip", "size_bytes": 4096, "sha256": "a".repeat(64),
                "shared_file_id": 21,
                "download_url": "https://files.example/p?expires=1790000000&sig=SECRETSIG",
                "download_expires_at": "2026-11-22T12:00:00+00:00", "fallback_url": null,
                "future_field": 1
            }],
            "downloads": [], "avatars": [], "missing": [],
            "generated_at": "2026-11-22T11:50:00+00:00", "another_future_field": {"x": 1}
        })
    }

    async fn client(server: &MockServer) -> ApiClient {
        ApiClient::new(&server.uri())
            .unwrap()
            .with_token("lvc_secret")
    }

    #[test]
    fn recipe_summary_parses_store_ids() {
        let mut v = summary_json();
        v["store_ids"] = json!({"steam": "10", "gog": "1207658924"});
        let s: RecipeSummary = serde_json::from_value(v).unwrap();
        assert_eq!(s.store_ids.steam.as_deref(), Some("10"));
        assert_eq!(s.store_ids.gog.as_deref(), Some("1207658924"));
        assert_eq!(s.store_ids.epic, None);
    }

    #[test]
    fn recipe_summary_without_store_ids_defaults_empty() {
        let s: RecipeSummary = serde_json::from_value(summary_json()).unwrap();
        assert_eq!(s.store_ids, StoreIds::default());
        // An empty PHP array arrives as `[]`: still no ids, never a parse failure.
        let mut v = summary_json();
        v["store_ids"] = json!([]);
        let s: RecipeSummary = serde_json::from_value(v).unwrap();
        assert_eq!(s.store_ids, StoreIds::default());
    }

    #[tokio::test]
    async fn lists_recipes_and_reads_missing_fields_as_unknown() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes"))
            .and(header("authorization", "Bearer lvc_secret"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({
                "recipes": [summary_json(), {"slug": "old", "title": "Old", "brand_new": true}]
            })))
            .mount(&server)
            .await;

        let recipes = client(&server).await.recipes().await.unwrap();
        assert_eq!(recipes.len(), 2);
        assert_eq!(recipes[0].players_max, Some(16));
        assert_eq!(recipes[0].installable, Some(true));
        assert_eq!(
            recipes[1].installable, None,
            "older server: unknown, not no"
        );
        assert_eq!(recipes[1].launcher_supported, None);
        assert_eq!(recipes[1].in_lineup, None);
        assert!(recipes[1].platforms.is_empty());
    }

    #[tokio::test]
    async fn reads_one_recipe_with_the_long_fields() {
        let server = MockServer::start().await;
        let mut recipe = summary_json();
        recipe["description"] = json!("Long text");
        recipe["aliases"] = json!(["utx"]);
        recipe["portable"] = json!(true);
        recipe["supports_multiplayer"] = json!(true);
        recipe["launch_targets"] = json!([{"id": "game", "label": "Spielen", "os": "windows",
            "target": "{{target}}/Game.exe", "command": "ignored"}]);
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes/ut2004-test"))
            .respond_with(ResponseTemplate::new(200).set_body_json(json!({"recipe": recipe})))
            .mount(&server)
            .await;

        let r = client(&server).await.recipe("ut2004-test").await.unwrap();
        assert_eq!(r.summary.slug, "ut2004-test");
        assert_eq!(r.aliases, ["utx"]);
        assert_eq!(r.portable, Some(true));
        assert_eq!(
            r.launch_targets[0].target.as_deref(),
            Some("{{target}}/Game.exe")
        );
    }

    #[tokio::test]
    async fn a_hostile_slug_never_reaches_the_network() {
        let server = MockServer::start().await;
        let c = client(&server).await;
        for bad in ["../x", "a/b", "a?b", ""] {
            assert!(matches!(c.recipe(bad).await, Err(Error::Usage(_))), "{bad}");
            assert!(matches!(
                c.recipe_plan(bad, "linux", None, true).await,
                Err(Error::Usage(_))
            ));
        }
        assert!(server.received_requests().await.unwrap().is_empty());
    }

    #[tokio::test]
    async fn requests_a_plan_and_marks_dry_runs() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes/ut2004-test/plan"))
            .and(query_param("os", "windows"))
            .and(query_param("dry_run", "1"))
            .respond_with(ResponseTemplate::new(200).set_body_json(plan_json()))
            .mount(&server)
            .await;

        let c = client(&server).await;
        let plan = c
            .recipe_plan("ut2004-test", "windows", None, true)
            .await
            .unwrap();
        assert_eq!(plan.plan_version, 1);
        assert_eq!(
            plan.files[0].sha256.as_deref(),
            Some("a".repeat(64).as_str())
        );
        assert_eq!(
            plan.files[0].download_expires_at.as_deref(),
            Some("2026-11-22T12:00:00+00:00")
        );
        assert_eq!(plan.steps[0].title, "Check");
        assert_eq!(plan.availability(), Availability::OnLan);

        // Without dry_run the parameter is absent: the server records the install request.
        let no_dry = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes/ut2004-test/plan"))
            .respond_with(ResponseTemplate::new(200).set_body_json(plan_json()))
            .mount(&no_dry)
            .await;
        client(&no_dry)
            .await
            .recipe_plan("ut2004-test", "linux", Some("lan-2026"), false)
            .await
            .unwrap();
        let req = &no_dry.received_requests().await.unwrap()[0];
        let q = req.url.query().unwrap();
        assert!(q.contains("os=linux") && q.contains("event_slug=lan-2026"));
        assert!(!q.contains("dry_run"));
    }

    #[test]
    fn a_newer_plan_version_is_refused_before_anything_else() {
        let mut v = plan_json();
        v["plan_version"] = json!(2);
        // The v2 shape need not even match the v1 types.
        v["files"] = json!("not a list any more");
        let err = RecipePlan::parse(v.to_string().as_bytes()).unwrap_err();
        assert!(matches!(
            err,
            Error::PlanVersionUnsupported {
                found: 2,
                supported: 1
            }
        ));
    }

    #[test]
    fn a_plan_without_a_version_is_version_one_and_junk_is_a_bad_response() {
        let mut v = plan_json();
        v.as_object_mut().unwrap().remove("plan_version");
        assert_eq!(
            RecipePlan::parse(v.to_string().as_bytes())
                .unwrap()
                .plan_version,
            1
        );
        v["plan_version"] = json!("one");
        assert!(matches!(
            RecipePlan::parse(v.to_string().as_bytes()),
            Err(Error::BadResponse(_))
        ));
        assert!(matches!(
            RecipePlan::parse(b"<html>"),
            Err(Error::BadResponse(_))
        ));
    }

    #[test]
    fn availability_follows_the_required_files() {
        let mut plan = RecipePlan::parse(plan_json().to_string().as_bytes()).unwrap();
        assert_eq!(plan.availability(), Availability::OnLan);

        let mut optional_missing = plan.clone();
        optional_missing.files.push(PlanFile {
            requirement: "avatar".into(),
            status: "missing".into(),
            required: false,
            ..Default::default()
        });
        assert_eq!(optional_missing.availability(), Availability::OnLan);

        let mut required_missing = plan.clone();
        required_missing.files[0].status = "missing".into();
        required_missing.files[0].source = None;
        assert_eq!(required_missing.availability(), Availability::Missing);

        let mut listed = plan.clone();
        listed.missing = vec!["Base game".into()];
        assert_eq!(listed.availability(), Availability::Missing);

        plan.files[0].source = Some("external".into());
        plan.files[0].status = "external".into();
        assert_eq!(plan.availability(), Availability::Unknown);

        assert_eq!(RecipePlan::default().availability(), Availability::Unknown);
    }

    #[test]
    fn the_content_hash_ignores_signed_links_and_timestamps() {
        let a = RecipePlan::parse(plan_json().to_string().as_bytes()).unwrap();
        let mut v = plan_json();
        v["files"][0]["download_url"] = json!("https://files.example/p?sig=OTHER");
        v["files"][0]["download_expires_at"] = json!("2030-01-01T00:00:00+00:00");
        v["generated_at"] = json!("2030-01-01T00:00:00+00:00");
        let b = RecipePlan::parse(v.to_string().as_bytes()).unwrap();
        assert_eq!(a.content_hash(), b.content_hash());
        assert_eq!(a.content_hash().len(), 64);

        v["files"][0]["sha256"] = json!("b".repeat(64));
        let c = RecipePlan::parse(v.to_string().as_bytes()).unwrap();
        assert_ne!(a.content_hash(), c.content_hash());
    }

    #[tokio::test]
    async fn neither_debug_output_nor_errors_leak_the_token_or_a_signature() {
        let plan = RecipePlan::parse(plan_json().to_string().as_bytes()).unwrap();
        let debug = format!("{plan:?}");
        assert!(!debug.contains("SECRETSIG"), "{debug}");
        assert!(debug.contains("https://files.example/p<redacted>"));

        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes"))
            .respond_with(ResponseTemplate::new(500).set_body_string("lvc_secret oops"))
            .mount(&server)
            .await;
        let c = client(&server).await;
        assert!(!format!("{c:?}").contains("lvc_secret"));
        let err = c.recipes().await.unwrap_err();
        assert!(!err.to_string().contains("lvc_secret"), "{err}");
    }

    #[tokio::test]
    async fn error_statuses_keep_their_meaning() {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes"))
            .respond_with(ResponseTemplate::new(404).set_body_json(json!({"message": "x"})))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/api/voice-client/v1/recipes/a/plan"))
            .respond_with(
                ResponseTemplate::new(429)
                    .insert_header("Retry-After", "7")
                    .set_body_json(json!({"message": "Too Many Attempts."})),
            )
            .mount(&server)
            .await;
        let c = client(&server).await;
        assert!(matches!(c.recipes().await, Err(Error::NotFound(_))));
        assert!(matches!(
            c.recipe_plan("a", "linux", None, true).await,
            Err(Error::RateLimited {
                retry_after_secs: Some(7)
            })
        ));
    }
}
