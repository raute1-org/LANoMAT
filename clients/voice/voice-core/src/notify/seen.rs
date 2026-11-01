//! De-duplication across restarts. Notification ids are UUIDs (not ordered), so "the last seen id"
//! is a bounded set of recent ids plus a `created_at` high-water mark: an id in the set is never
//! announced again, and anything older than the mark (whose id may have left the set) is not
//! either.

use std::collections::VecDeque;

use serde::{Deserialize, Serialize};

const MAX_IDS: usize = 200;

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default)]
pub struct SeenState {
    /// False until the first successful look at the bell list: that look only records what is
    /// there, so a fresh install never announces old unread notifications.
    pub baselined: bool,
    /// Newest `created_at` seen (ISO 8601 in UTC as the server writes it: compares as text).
    pub high_water: Option<String>,
    /// Most recent ids, oldest first.
    pub ids: VecDeque<String>,
}

impl SeenState {
    pub fn is_new(&self, id: &str, created_at: &str) -> bool {
        if self.ids.iter().any(|i| i == id) {
            return false;
        }
        match &self.high_water {
            Some(hw) if !created_at.is_empty() => created_at >= hw.as_str(),
            _ => true,
        }
    }

    pub fn mark(&mut self, id: &str, created_at: &str) {
        if !self.ids.iter().any(|i| i == id) {
            self.ids.push_back(id.to_owned());
            while self.ids.len() > MAX_IDS {
                self.ids.pop_front();
            }
        }
        if !created_at.is_empty() && self.high_water.as_deref().is_none_or(|hw| created_at > hw) {
            self.high_water = Some(created_at.to_owned());
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn marked_ids_are_not_new_again() {
        let mut s = SeenState::default();
        assert!(s.is_new("a", "2026-11-02T12:00:00+00:00"));
        s.mark("a", "2026-11-02T12:00:00+00:00");
        assert!(!s.is_new("a", "2026-11-02T12:00:00+00:00"));
        assert!(
            s.is_new("b", "2026-11-02T12:00:00+00:00"),
            "same second, other id"
        );
        assert!(s.is_new("c", "2026-11-02T12:05:00+00:00"));
    }

    #[test]
    fn older_than_the_high_water_mark_is_not_new_even_when_the_id_was_evicted() {
        let mut s = SeenState::default();
        s.mark("old", "2026-11-02T10:00:00+00:00");
        s.mark("new", "2026-11-02T12:00:00+00:00");
        s.ids.clear(); // as if evicted
        assert!(!s.is_new("old", "2026-11-02T10:00:00+00:00"));
        assert!(s.is_new("later", "2026-11-02T12:00:01+00:00"));
    }

    #[test]
    fn the_id_set_is_bounded_and_the_mark_never_moves_back() {
        let mut s = SeenState::default();
        for i in 0..(MAX_IDS + 50) {
            s.mark(&format!("id{i}"), "2026-11-02T12:00:00+00:00");
        }
        assert_eq!(s.ids.len(), MAX_IDS);
        assert_eq!(s.ids.front().unwrap(), "id50");
        s.mark("x", "2026-11-01T00:00:00+00:00");
        assert_eq!(s.high_water.as_deref(), Some("2026-11-02T12:00:00+00:00"));
    }

    #[test]
    fn survives_a_json_round_trip_and_tolerates_missing_fields() {
        let mut s = SeenState::default();
        s.baselined = true;
        s.mark("a", "2026-11-02T12:00:00+00:00");
        let back: SeenState = serde_json::from_str(&serde_json::to_string(&s).unwrap()).unwrap();
        assert_eq!(back, s);
        let empty: SeenState = serde_json::from_str("{}").unwrap();
        assert!(!empty.baselined && empty.ids.is_empty());
    }
}
