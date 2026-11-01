//! The notification policy of the UX concept ("Interrupt rarely, and only for the user's own
//! things"), as pure, time-injected code: which categories raise a system notification, grouping
//! (at most one per category per minute, the rest a count), do-not-disturb, and the screen a
//! notification opens. No I/O and no clock: `now` is a `Duration` on any monotonic timeline.

use std::collections::{BTreeMap, HashMap};
use std::time::Duration;

use serde::{Deserialize, Serialize};

/// One system notification per category and this long; the rest is counted.
pub const GROUP_WINDOW: Duration = Duration::from_secs(60);

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Category {
    Match,
    Catering,
    /// A chat message that mentions me (raised by the client, not the server).
    Direct,
    /// A vote of mine that is about to close (raised by the client from the "now" feed).
    Vote,
    Registration,
    Checkin,
    Teams,
    Friends,
    Schedule,
    OrgaPing,
    Discord,
    Account,
    System,
    Other,
}

impl Category {
    pub const ALL: [Category; 14] = [
        Category::Match,
        Category::Catering,
        Category::Direct,
        Category::Vote,
        Category::Registration,
        Category::Checkin,
        Category::Teams,
        Category::Friends,
        Category::Schedule,
        Category::OrgaPing,
        Category::Discord,
        Category::Account,
        Category::System,
        Category::Other,
    ];

    /// Maps the server's category string; anything unknown is `Other` (off by default).
    pub fn from_server(s: &str) -> Category {
        match s {
            "match" => Category::Match,
            "catering" => Category::Catering,
            "direct" => Category::Direct,
            "vote" => Category::Vote,
            "registration" => Category::Registration,
            "checkin" => Category::Checkin,
            "teams" => Category::Teams,
            "friends" => Category::Friends,
            "schedule" => Category::Schedule,
            "orga_ping" => Category::OrgaPing,
            "discord" => Category::Discord,
            "account" => Category::Account,
            "system" => Category::System,
            _ => Category::Other,
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Category::Match => "match",
            Category::Catering => "catering",
            Category::Direct => "direct",
            Category::Vote => "vote",
            Category::Registration => "registration",
            Category::Checkin => "checkin",
            Category::Teams => "teams",
            Category::Friends => "friends",
            Category::Schedule => "schedule",
            Category::OrgaPing => "orga_ping",
            Category::Discord => "discord",
            Category::Account => "account",
            Category::System => "system",
            Category::Other => "other",
        }
    }

    /// The UX default: on for what concerns me (match, food, direct mention, vote closing), off for
    /// the rest (it still lands in the bell list).
    pub fn default_on(self) -> bool {
        matches!(
            self,
            Category::Match | Category::Catering | Category::Direct | Category::Vote
        )
    }

    /// German label for the grouped "N weitere" text.
    pub fn label(self) -> &'static str {
        match self {
            Category::Match => "Match",
            Category::Catering => "Essen",
            Category::Direct => "Erwähnungen",
            Category::Vote => "Abstimmungen",
            Category::Registration => "Anmeldung",
            Category::Checkin => "Check-in",
            Category::Teams => "Teams",
            Category::Friends => "Freunde",
            Category::Schedule => "Programm",
            Category::OrgaPing => "Orga",
            Category::Discord => "Discord",
            Category::Account => "Konto",
            Category::System => "System",
            Category::Other => "Sonstiges",
        }
    }
}

/// Where a notification leads: one of the five tabs (`jetzt`, `sprache`, `spielen`, `essen`,
/// `mehr`) plus an optional card or section hint inside it. "Every notification opens the
/// matching screen or card, never just the app."
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Target {
    pub screen: String,
    pub card: Option<String>,
}

pub fn target_for(category: Category) -> Target {
    let (screen, card) = match category {
        Category::Match => ("spielen", Some("match")),
        Category::Checkin => ("spielen", Some("checkin")),
        Category::Teams => ("spielen", Some("tournaments")),
        Category::Catering => ("essen", Some("orders")),
        Category::Direct => ("sprache", Some("chat")),
        Category::Vote => ("jetzt", Some("vote")),
        Category::Registration | Category::OrgaPing => ("jetzt", None),
        Category::Schedule => ("jetzt", Some("schedule")),
        Category::Friends
        | Category::Discord
        | Category::Account
        | Category::System
        | Category::Other => ("mehr", Some("notifications")),
    };
    Target {
        screen: screen.into(),
        card: card.map(str::to_owned),
    }
}

/// A notification offered to the policy (from the server's bell list, a chat mention, ...).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Incoming {
    pub id: String,
    pub category: Category,
    pub title: String,
    pub body: Option<String>,
}

/// What the OS notification shows and where a click leads.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct SystemNotification {
    pub category: Category,
    pub title: String,
    pub body: Option<String>,
    pub target: Target,
    /// How many notifications this one stands for (1, or the count of a grouped summary).
    pub count: u32,
}

/// Do-not-disturb: push-to-talk held or the user talking, or the manual "Ruhe" toggle.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Dnd {
    pub talking: bool,
    pub manual: bool,
}

impl Dnd {
    pub fn active(self) -> bool {
        self.talking || self.manual
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Reason {
    /// The category is switched off in the settings.
    Disabled,
    /// Do-not-disturb; the notification stays in the bell list.
    DoNotDisturb,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Decision {
    Show(SystemNotification),
    /// Counted for the next grouped summary.
    Held,
    Suppressed(Reason),
}

#[derive(Debug, Default)]
struct Slot {
    last_shown: Option<Duration>,
    held: u32,
}

#[derive(Debug, Default)]
pub struct Policy {
    overrides: BTreeMap<Category, bool>,
    slots: HashMap<Category, Slot>,
}

impl Policy {
    pub fn new(overrides: BTreeMap<Category, bool>) -> Self {
        Self {
            overrides,
            slots: HashMap::new(),
        }
    }

    pub fn set_overrides(&mut self, overrides: BTreeMap<Category, bool>) {
        self.overrides = overrides;
    }

    pub fn enabled(&self, category: Category) -> bool {
        self.overrides
            .get(&category)
            .copied()
            .unwrap_or_else(|| category.default_on())
    }

    pub fn decide(&mut self, now: Duration, n: &Incoming, dnd: Dnd) -> Decision {
        if !self.enabled(n.category) {
            return Decision::Suppressed(Reason::Disabled);
        }
        if dnd.active() {
            return Decision::Suppressed(Reason::DoNotDisturb);
        }
        let slot = self.slots.entry(n.category).or_default();
        let open = slot
            .last_shown
            .is_none_or(|t| now.saturating_sub(t) >= GROUP_WINDOW);
        if open && slot.held == 0 {
            slot.last_shown = Some(now);
            return Decision::Show(SystemNotification {
                category: n.category,
                title: n.title.clone(),
                body: n.body.clone(),
                target: target_for(n.category),
                count: 1,
            });
        }
        slot.held += 1;
        Decision::Held
    }

    /// Grouped summaries that are due: a category with held notifications whose window has
    /// passed yields one "N weitere" notification (and opens a new window). Call it regularly.
    pub fn flush(&mut self, now: Duration, dnd: Dnd) -> Vec<SystemNotification> {
        if dnd.active() {
            // Held ones stay counted; they surface after do-not-disturb ends (or stay in the bell).
            return Vec::new();
        }
        let mut out = Vec::new();
        let mut cats: Vec<Category> = self.slots.keys().copied().collect();
        cats.sort();
        for c in cats {
            let enabled = self.enabled(c);
            let Some(slot) = self.slots.get_mut(&c) else {
                continue;
            };
            if slot.held == 0 {
                continue;
            }
            if !enabled {
                slot.held = 0;
                continue;
            }
            let due = slot
                .last_shown
                .is_none_or(|t| now.saturating_sub(t) >= GROUP_WINDOW);
            if due {
                out.push(SystemNotification {
                    category: c,
                    title: format!("{} weitere Benachrichtigungen", slot.held),
                    body: Some(c.label().to_owned()),
                    target: target_for(c),
                    count: slot.held,
                });
                slot.held = 0;
                slot.last_shown = Some(now);
            }
        }
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn inc(id: &str, c: Category) -> Incoming {
        Incoming {
            id: id.into(),
            category: c,
            title: format!("t-{id}"),
            body: None,
        }
    }

    fn secs(s: u64) -> Duration {
        Duration::from_secs(s)
    }

    #[test]
    fn defaults_follow_the_ux_concept() {
        let p = Policy::default();
        for c in [
            Category::Match,
            Category::Catering,
            Category::Direct,
            Category::Vote,
        ] {
            assert!(p.enabled(c), "{c:?}");
        }
        for c in [
            Category::Registration,
            Category::Checkin,
            Category::Teams,
            Category::Friends,
            Category::Schedule,
            Category::OrgaPing,
            Category::Discord,
            Category::Account,
            Category::System,
            Category::Other,
        ] {
            assert!(!p.enabled(c), "{c:?}");
        }
    }

    #[test]
    fn server_category_strings_map_and_unknown_is_other() {
        assert_eq!(Category::from_server("match"), Category::Match);
        assert_eq!(Category::from_server("orga_ping"), Category::OrgaPing);
        assert_eq!(Category::from_server("brand_new"), Category::Other);
        for c in Category::ALL {
            assert_eq!(Category::from_server(c.as_str()), c);
        }
    }

    #[test]
    fn user_overrides_win_in_both_directions() {
        let mut o = BTreeMap::new();
        o.insert(Category::Match, false);
        o.insert(Category::Friends, true);
        let mut p = Policy::new(o);
        assert_eq!(
            p.decide(secs(0), &inc("1", Category::Match), Dnd::default()),
            Decision::Suppressed(Reason::Disabled)
        );
        assert!(matches!(
            p.decide(secs(0), &inc("2", Category::Friends), Dnd::default()),
            Decision::Show(_)
        ));
    }

    #[test]
    fn at_most_one_per_category_per_minute_and_the_rest_is_a_count() {
        let mut p = Policy::default();
        let d = Dnd::default();
        assert!(matches!(
            p.decide(secs(0), &inc("1", Category::Match), d),
            Decision::Show(_)
        ));
        for i in 2..=4 {
            assert_eq!(
                p.decide(secs(i * 5), &inc(&i.to_string(), Category::Match), d),
                Decision::Held
            );
        }
        // Another category is independent.
        assert!(matches!(
            p.decide(secs(10), &inc("x", Category::Catering), d),
            Decision::Show(_)
        ));
        // Before the window ends nothing is due.
        assert!(p.flush(secs(59), d).is_empty());
        // After it: one summary with the count, then a fresh window.
        let out = p.flush(secs(60), d);
        assert_eq!(out.len(), 1);
        assert_eq!(out[0].count, 3);
        assert_eq!(out[0].category, Category::Match);
        assert!(out[0].title.starts_with("3 weitere"));
        assert!(p.flush(secs(61), d).is_empty());
        // The summary counted as the shown one: a new item right after is held again.
        assert_eq!(
            p.decide(secs(65), &inc("5", Category::Match), d),
            Decision::Held
        );
        // And is shown as the next window's summary count.
        let out = p.flush(secs(120), d);
        assert_eq!(out[0].count, 1);
    }

    #[test]
    fn a_new_item_after_a_quiet_minute_is_shown_directly() {
        let mut p = Policy::default();
        let d = Dnd::default();
        assert!(matches!(
            p.decide(secs(0), &inc("1", Category::Match), d),
            Decision::Show(_)
        ));
        assert!(matches!(
            p.decide(secs(61), &inc("2", Category::Match), d),
            Decision::Show(_)
        ));
    }

    #[test]
    fn do_not_disturb_suppresses_without_touching_the_grouping_state() {
        let mut p = Policy::default();
        let talking = Dnd {
            talking: true,
            manual: false,
        };
        let manual = Dnd {
            talking: false,
            manual: true,
        };
        assert_eq!(
            p.decide(secs(0), &inc("1", Category::Match), talking),
            Decision::Suppressed(Reason::DoNotDisturb)
        );
        assert_eq!(
            p.decide(secs(0), &inc("2", Category::Match), manual),
            Decision::Suppressed(Reason::DoNotDisturb)
        );
        // Once quiet is over the first one is shown right away (it was never "shown" before).
        assert!(matches!(
            p.decide(secs(1), &inc("3", Category::Match), Dnd::default()),
            Decision::Show(_)
        ));
    }

    #[test]
    fn flush_waits_during_do_not_disturb() {
        let mut p = Policy::default();
        let d = Dnd::default();
        p.decide(secs(0), &inc("1", Category::Match), d);
        p.decide(secs(1), &inc("2", Category::Match), d);
        let quiet = Dnd {
            talking: true,
            manual: false,
        };
        assert!(p.flush(secs(100), quiet).is_empty());
        assert_eq!(p.flush(secs(101), d).len(), 1);
    }

    #[test]
    fn held_items_of_a_category_that_was_switched_off_are_dropped() {
        let mut p = Policy::default();
        let d = Dnd::default();
        p.decide(secs(0), &inc("1", Category::Match), d);
        p.decide(secs(1), &inc("2", Category::Match), d);
        let mut o = BTreeMap::new();
        o.insert(Category::Match, false);
        p.set_overrides(o);
        assert!(p.flush(secs(100), d).is_empty());
    }

    #[test]
    fn every_category_opens_a_screen_of_the_five_tabs() {
        for c in Category::ALL {
            let t = target_for(c);
            assert!(
                ["jetzt", "sprache", "spielen", "essen", "mehr"].contains(&t.screen.as_str()),
                "{c:?} -> {t:?}"
            );
        }
        assert_eq!(target_for(Category::Match).screen, "spielen");
        assert_eq!(target_for(Category::Catering).screen, "essen");
        assert_eq!(target_for(Category::Direct).screen, "sprache");
        assert_eq!(target_for(Category::Vote).screen, "jetzt");
    }

    #[test]
    fn shown_notifications_carry_their_target() {
        let mut p = Policy::default();
        match p.decide(secs(0), &inc("1", Category::Catering), Dnd::default()) {
            Decision::Show(n) => {
                assert_eq!(n.target.screen, "essen");
                assert_eq!(n.count, 1);
            }
            d => panic!("{d:?}"),
        }
    }
}
