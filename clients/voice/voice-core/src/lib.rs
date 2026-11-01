//! Core of the LANoMAT native voice client: API client, LiveKit session, audio abstraction,
//! push-to-talk control socket and latency maths. UI-free, so the same crate can sit under the
//! CLI today and a Tauri shell later.

pub mod api;
pub mod audio;
pub mod control;
#[cfg(feature = "dev-tokens")]
pub mod devtokens;
pub mod error;
pub mod latency;
pub mod latency_test;
pub mod session;

pub use error::{Error, ErrorKind, Result};
