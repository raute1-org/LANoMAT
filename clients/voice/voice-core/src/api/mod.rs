//! Client side of `docs/infra/native-voice-client-api.md`: device-code pairing, bearer API v1
//! (voice, events, play, polls, catering, files, chat, notifications, profile, connections, friends, people) and credential storage.
//!
//! Every HTTP failure goes through one mapping (`client::error_from_response`): 401 to
//! [`Error::Unauthorized`] (log in again), 403 `Forbidden`, 404 `NotFound`, 409 `Conflict` (stale
//! `lock_version`), 410 `Gone`, 422 `Validation` (with the server's German `message`, its stable
//! `code` and the field errors), 429 `RateLimited` (with `Retry-After`), 503 `Unavailable`, other
//! 5xx `Server`, and transport trouble `Network`. [`Error::retryable`] tells which are worth a retry.

mod account;
mod catering;
mod chat;
mod client;
mod events;
mod files;
mod notifications;
mod play;
mod poll;
mod polls;
mod presence;
mod store;
mod types;

pub use account::{
    validate_connection_link_url, Connection, FriendRequest, FriendRequests, FriendSuggestion,
    FriendUser, LinkUrl, Profile, ProfileUpdate,
};
pub use catering::{
    Catering, CateringLimits, FoodOrder, MenuEntry, MineItem, MineResponse, MyItem,
};
pub use chat::{ChatAuthor, ChatMessage, MessagesPage};
pub use client::{default_device_name, normalize_base_url, ApiClient};
pub use events::{EventRegistration, EventSummary, EventsResponse};
pub use files::{
    DownloadLink, FilesOverview, Quota, QuotaInfo, SharedFile, UploadInfo, UploadTicket,
};
pub use notifications::{Notification, NotificationsPage};
pub use play::{
    GameServer, InstallHint, Match, MatchCan, MatchTournamentRef, MyEntry, NowCard, NowFeed,
    PendingReport, Side, Tournament, TournamentCan, TournamentRegistration,
};
pub use poll::{PollEvent, PollState, PollStep};
pub use polls::{PollDetail, PollOption, PollSummary, PollsList};
pub use presence::{
    FollowJoin, FollowTargets, FollowUser, FollowVoice, People, PeopleQuery, Person, PersonGame,
    PersonVoice,
};
pub use store::{FileTokenStore, StoredCredentials, TokenStore};
pub use types::{
    AudioSettings, ClientConfig, DeviceAuthorization, JoinGrant, Me, RealtimeConfig, Room,
    TokenResponse,
};

use crate::error::{Error, Result};

/// One URL path segment (an event slug, a UUID): only unreserved characters pass, so a value
/// from the UI can never add path components, a query or a fragment.
pub(crate) fn seg(value: &str) -> Result<String> {
    let ok = !value.is_empty()
        && value.len() <= 128
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.'))
        && value != "."
        && value != "..";
    if ok {
        Ok(value.to_owned())
    } else {
        Err(Error::Usage(format!("not a valid identifier: {value:?}")))
    }
}

#[cfg(test)]
mod tests {
    use super::seg;

    #[test]
    fn path_segments_reject_anything_that_could_escape() {
        assert_eq!(seg("lan-party-2026").unwrap(), "lan-party-2026");
        assert!(seg("b2c7c9a6-1f5e-4a7e-9d5b-0a1e2c3d4e5f").is_ok());
        for bad in ["", "..", ".", "a/b", "a?b", "a#b", "a b", "../x", "a%2fb"] {
            assert!(seg(bad).is_err(), "{bad}");
        }
    }
}
