//! The game library ("Spiele"): what the server offers (`GET recipes`), where its files are
//! (`GET recipes/{slug}/plan?dry_run=1`) and what is on this machine (`installs`).
//!
//! Browsing never writes an audit entry on the server: every plan request made here carries
//! `dry_run=1`. The server rate-limits plans to 30 a minute per token, so availability is looked
//! up with a budget, at most [`PLAN_PARALLEL`] at a time, and cached for a few minutes. A recipe
//! whose plan was not fetched (yet, or because of the budget) is `unknown`; asking again fills
//! the gaps. Documented for the UI in `docs/commands.md` ("Games").

use std::collections::{HashMap, VecDeque};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use serde::Serialize;
use time::format_description::well_known::Rfc3339;
use time::OffsetDateTime;
use voice_core::api::{
    current_os, ApiClient, Availability, RecipeDetail, RecipePlan, RecipeSummary, StoreIds,
};
use voice_core::Error;

use super::{lock, Features};
use crate::engine::UiError;
use crate::installs::LocalView;

/// The store scan reads the Windows registry and the stores' folders; stage 1 has no other OS.
const LOCAL_SCAN_PLATFORM: bool = cfg!(windows);

/// Whether this build can scan: Windows, or a debug build run with a scan fixture (screenshots).
pub(super) fn local_scan() -> bool {
    LOCAL_SCAN_PLATFORM || super::library::fixture_scan().is_some()
}

impl Features {
    /// Test seam: how many plan answers the cache holds.
    #[cfg(test)]
    pub(super) fn plans_cached(&self) -> usize {
        lock(&self.games).plans.len()
    }
}

/// Whether this build can scan the PC for store games. Local only: no pairing, no network.
#[must_use]
pub fn local_scan_available() -> bool {
    local_scan()
}

/// Plan requests per minute this client allows itself (the server's limit is 30 per token).
const PLAN_BUDGET_PER_MINUTE: usize = 24;
/// Plan requests in flight at once.
const PLAN_PARALLEL: usize = 4;
/// How long a looked-up availability is reused.
const PLAN_TTL: Duration = Duration::from_secs(300);
/// How long a failed lookup is not repeated.
const PLAN_FAILED_TTL: Duration = Duration::from_secs(60);

/// Stable error codes of the games commands (`UiError.code`).
pub mod code {
    pub const NOT_PAIRED: &str = "not_paired";
    pub const SERVER_ERROR: &str = "server_error";
    pub const PLAN_VERSION_UNSUPPORTED: &str = "plan_version_unsupported";
    pub const OFFLINE: &str = "offline";
    /// The GameRecipes module is off (or the server predates it): the routes answer 404.
    pub const MODULE_OFF: &str = "module_off";
    pub const NOT_FOUND: &str = "not_found";
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct GamesState {
    pub enabled: bool,
    /// `module_off`, `server_too_old`, `not_paired`, `offline` or `server_error`; `null` while
    /// enabled.
    pub reason: Option<&'static str>,
    /// This build can scan the PC for store games (stage 1: Windows only).
    pub local: bool,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct GameView {
    pub slug: String,
    pub title: String,
    pub summary: Option<String>,
    pub genre: Option<String>,
    pub players: Option<String>,
    pub players_max: Option<u32>,
    pub platforms: Vec<String>,
    pub size_bytes: Option<u64>,
    pub installable: Option<bool>,
    pub launcher_supported: Option<bool>,
    pub in_lineup: bool,
    pub availability: Availability,
    pub local: LocalView,
    pub store_ids: StoreIds,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct GamesList {
    pub recipes: Vec<GameView>,
    pub fetched_at: String,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct GameDetailRecipe {
    #[serde(flatten)]
    pub game: GameView,
    pub description: Option<String>,
    pub aliases: Vec<String>,
    pub homepage: Option<String>,
    pub license: Option<String>,
    pub portable: Option<bool>,
    pub supports_multiplayer: Option<bool>,
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct PlanFileView {
    pub label: String,
    pub size_bytes: Option<u64>,
    /// `lan`, `external`, `manual`, or `none` for a file nothing provides.
    pub source: String,
    pub required: bool,
    pub status: String,
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct PlanSummary {
    pub plan_version: u32,
    pub files: Vec<PlanFileView>,
    pub missing: Vec<String>,
}

#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct GameDetail {
    pub recipe: GameDetailRecipe,
    pub plan_summary: Option<PlanSummary>,
    pub local: LocalView,
}

/// A looked-up plan, reduced to its availability. Neither the plan nor a signed link is kept.
#[derive(Clone)]
struct PlanEntry {
    until: Instant,
    availability: Availability,
}

#[derive(Default)]
pub(super) struct GamesCache {
    plans: HashMap<String, PlanEntry>,
    /// When the plan requests of the last minute were made.
    requests: VecDeque<Instant>,
    /// Bumped by `clear` (logout, another account): a plan lookup that started before it must
    /// not write its answer into the new account's cache.
    generation: u64,
}

impl GamesCache {
    pub(super) fn clear(&mut self) {
        self.generation += 1;
        self.plans.clear();
        self.requests.clear();
    }

    fn fresh(&self, slug: &str, now: Instant) -> Option<&PlanEntry> {
        self.plans.get(slug).filter(|e| e.until > now)
    }

    /// How many plan requests may still be made right now.
    fn budget(&mut self, now: Instant) -> usize {
        while self
            .requests
            .front()
            .is_some_and(|t| now.duration_since(*t) >= Duration::from_secs(60))
        {
            self.requests.pop_front();
        }
        PLAN_BUDGET_PER_MINUTE.saturating_sub(self.requests.len())
    }
}

fn summarize(plan: &RecipePlan) -> PlanSummary {
    PlanSummary {
        plan_version: plan.plan_version,
        files: plan
            .files
            .iter()
            .map(|f| PlanFileView {
                label: f.display_name(),
                size_bytes: f.size_bytes,
                source: f.source.clone().unwrap_or_else(|| "none".into()),
                required: f.required,
                status: f.status.clone(),
            })
            .collect(),
        missing: plan.missing.clone(),
    }
}

/// Only a recipe the server says this app can install has a plan worth asking for.
fn wants_plan(r: &RecipeSummary) -> bool {
    r.installable != Some(false) && r.launcher_supported != Some(false) && r.play_url.is_none()
}

pub(super) fn now_rfc3339() -> String {
    OffsetDateTime::now_utc()
        .format(&Rfc3339)
        .unwrap_or_default()
}

impl Features {
    /// Maps a core error of a games call: the stable code goes into `UiError.code`, the kind and
    /// the German text stay those of every other command. A rejected token also tells the windows.
    fn games_error(&self, e: Error, module_off_on_404: bool) -> UiError {
        let code = match &e {
            Error::NotLoggedIn | Error::Unauthorized => code::NOT_PAIRED,
            Error::Network(_) => code::OFFLINE,
            Error::PlanVersionUnsupported { .. } => code::PLAN_VERSION_UNSUPPORTED,
            Error::NotFound(_) if module_off_on_404 => code::MODULE_OFF,
            Error::NotFound(_) => code::NOT_FOUND,
            _ => code::SERVER_ERROR,
        };
        let mut ui = self.fail(e);
        ui.code = Some(code.to_owned());

        ui
    }

    fn games_api(&self) -> Result<ApiClient, UiError> {
        self.api().map_err(|mut e| {
            e.code = Some(code::NOT_PAIRED.to_owned());
            e
        })
    }

    fn game_view(&self, r: &RecipeSummary, availability: Availability) -> GameView {
        GameView {
            slug: r.slug.clone(),
            title: r.title.clone(),
            summary: r.summary.clone(),
            genre: r.genre.clone(),
            players: r.players.clone(),
            players_max: r.players_max,
            platforms: r.platforms.clone(),
            size_bytes: r.size_bytes,
            installable: r.installable,
            launcher_supported: r.launcher_supported,
            // A server that does not say lists only what the library shows.
            in_lineup: r.in_lineup.unwrap_or(true),
            availability,
            local: self.installs.local(&r.slug),
            store_ids: r.store_ids.clone(),
        }
    }

    /// Whether the library is available: the device is paired and the server runs the module.
    pub async fn games_state(&self) -> Result<GamesState, UiError> {
        let Ok(api) = self.api() else {
            return Ok(GamesState {
                enabled: false,
                reason: Some("not_paired"),
                local: local_scan(),
            });
        };
        // A server that drops packets must not hold the tab for the client's 10-20 s timeouts.
        let bound = self.library_env().recipe_timeout;
        let config = match tokio::time::timeout(bound, api.config()).await {
            Err(_) => {
                return Ok(GamesState {
                    enabled: false,
                    reason: Some("offline"),
                    local: local_scan(),
                });
            }
            Ok(Ok(config)) => config,
            // A rejected token is the session's problem (and every window is told); anything
            // else only means the library has no server today: the local part still works.
            Ok(Err(e @ (Error::NotLoggedIn | Error::Unauthorized))) => {
                return Err(self.games_error(e, false));
            }
            Ok(Err(e)) => {
                let reason = if matches!(e, Error::Network(_)) {
                    "offline"
                } else {
                    "server_error"
                };
                return Ok(GamesState {
                    enabled: false,
                    reason: Some(reason),
                    local: local_scan(),
                });
            }
        };

        Ok(match config.launcher_feature() {
            Some(true) => GamesState {
                enabled: true,
                reason: None,
                local: local_scan(),
            },
            Some(false) => GamesState {
                enabled: false,
                reason: Some("module_off"),
                local: local_scan(),
            },
            None => GamesState {
                enabled: false,
                reason: Some("server_too_old"),
                local: local_scan(),
            },
        })
    }

    /// The catalogue with availability and local state, in the server's order.
    pub async fn games_list(&self) -> Result<GamesList, UiError> {
        self.games_list_with(true).await
    }

    /// How the library asks: only the recipe list is awaited. The plan lookups run detached
    /// (`spawn_plan_fill`), so slow ones neither hold the list back nor are cut off by its time
    /// bound; the next list shows what they found.
    pub(super) async fn games_list_detached(&self) -> Result<GamesList, UiError> {
        self.games_list_with(false).await
    }

    async fn games_list_with(&self, inline_fill: bool) -> Result<GamesList, UiError> {
        let api = self.games_api()?;
        let recipes = api.recipes().await.map_err(|e| self.games_error(e, true))?;

        if inline_fill {
            self.fill_plan_cache(&api, &recipes).await;
        } else {
            self.spawn_plan_fill(api, recipes.clone());
        }

        let now = Instant::now();
        let cache = lock(&self.games);
        let views = recipes
            .iter()
            .map(|r| {
                let availability = cache
                    .fresh(&r.slug, now)
                    .map_or(Availability::Unknown, |e| e.availability);
                self.game_view(r, availability)
            })
            .collect();

        Ok(GamesList {
            recipes: views,
            fetched_at: now_rfc3339(),
        })
    }

    /// Starts the plan fill in the background, unless one is already running. Same budget and
    /// parallelism as the inline fill.
    fn spawn_plan_fill(&self, api: ApiClient, recipes: Vec<RecipeSummary>) {
        let Some(this) = self.this.upgrade() else {
            return;
        };
        // The running fill is tracked by generation (+1, 0 = none): after a logout the new
        // account's first fill must not be blocked by the old one still winding down.
        let generation = lock(&this.games).generation + 1;
        let running = &this.plan_fill_running;
        let previous = running.load(Ordering::SeqCst);
        if previous == generation
            || running
                .compare_exchange(previous, generation, Ordering::SeqCst, Ordering::SeqCst)
                .is_err()
        {
            return;
        }
        // Cleared on every way out of the task, a panic included, unless a newer generation has
        // taken the slot meanwhile.
        struct Running(Arc<Features>, u64);
        impl Drop for Running {
            fn drop(&mut self) {
                let _ = self.0.plan_fill_running.compare_exchange(
                    self.1,
                    0,
                    Ordering::SeqCst,
                    Ordering::SeqCst,
                );
            }
        }
        tokio::spawn(async move {
            let _running = Running(this.clone(), generation);
            this.fill_plan_cache(&api, &recipes).await;
        });
    }

    /// Looks up the plans (`dry_run=1`) of recipes that have no fresh answer, within the budget.
    async fn fill_plan_cache(&self, api: &ApiClient, recipes: &[RecipeSummary]) {
        let (generation, wanted): (u64, Vec<String>) = {
            let now = Instant::now();
            let mut cache = lock(&self.games);
            let budget = cache.budget(now);
            let wanted: Vec<String> = recipes
                .iter()
                .filter(|r| wants_plan(r) && cache.fresh(&r.slug, now).is_none())
                .take(budget)
                .map(|r| r.slug.clone())
                .collect();
            // Reserve the budget up front: two lists at once must not overrun it together.
            for _ in &wanted {
                cache.requests.push_back(now);
            }
            (cache.generation, wanted)
        };
        if wanted.is_empty() {
            return;
        }

        let os = current_os();
        let semaphore = Arc::new(tokio::sync::Semaphore::new(PLAN_PARALLEL));
        let throttled = Arc::new(AtomicBool::new(false));
        let mut tasks = tokio::task::JoinSet::new();
        for slug in wanted {
            let (api, semaphore, throttled) = (api.clone(), semaphore.clone(), throttled.clone());
            tasks.spawn(async move {
                let _permit = semaphore.acquire_owned().await.ok()?;
                if throttled.load(Ordering::SeqCst) {
                    return None;
                }
                let result = api.recipe_plan(&slug, os, None, true).await;
                if matches!(result, Err(Error::RateLimited { .. })) {
                    throttled.store(true, Ordering::SeqCst);
                }
                Some((slug, result))
            });
        }

        while let Some(joined) = tasks.join_next().await {
            let Ok(Some((slug, result))) = joined else {
                continue;
            };
            let now = Instant::now();
            let entry = match result {
                Ok(plan) => PlanEntry {
                    until: now + PLAN_TTL,
                    availability: plan.availability(),
                },
                Err(Error::Unauthorized) => {
                    let _ = self.fail(Error::Unauthorized);
                    continue;
                }
                // Throttled: ask again on the next list; anything else: not for a minute.
                Err(Error::RateLimited { .. }) => continue,
                Err(_) => PlanEntry {
                    until: now + PLAN_FAILED_TTL,
                    availability: Availability::Unknown,
                },
            };
            let mut cache = lock(&self.games);
            // The account changed while this lookup ran: its answer is not for this cache.
            if cache.generation == generation {
                cache.plans.insert(slug, entry);
            }
        }
    }

    /// One recipe in full, with a fresh plan summary (`dry_run=1`).
    pub async fn game_detail(&self, slug: &str) -> Result<GameDetail, UiError> {
        let api = self.games_api()?;
        let detail: RecipeDetail = api
            .recipe(slug)
            .await
            .map_err(|e| self.games_error(e, false))?;

        let (availability, plan_summary) = if wants_plan(&detail.summary) {
            match api.recipe_plan(slug, current_os(), None, true).await {
                Ok(plan) => {
                    let summary = summarize(&plan);
                    let availability = plan.availability();
                    lock(&self.games).plans.insert(
                        slug.to_owned(),
                        PlanEntry {
                            until: Instant::now() + PLAN_TTL,
                            availability,
                        },
                    );
                    (availability, Some(summary))
                }
                Err(e @ (Error::PlanVersionUnsupported { .. } | Error::Unauthorized)) => {
                    return Err(self.games_error(e, false));
                }
                // The description is still worth showing without the file list.
                Err(_) => (Availability::Unknown, None),
            }
        } else {
            (Availability::Unknown, None)
        };

        let game = self.game_view(&detail.summary, availability);
        let local = game.local.clone();

        Ok(GameDetail {
            recipe: GameDetailRecipe {
                game,
                description: detail.description,
                aliases: detail.aliases,
                homepage: detail.homepage,
                license: detail.license,
                portable: detail.portable,
                supports_multiplayer: detail.supports_multiplayer,
            },
            plan_summary,
            local,
        })
    }
}
