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

/// Picks rustls's process-wide crypto provider. The dependency tree enables both `aws-lc-rs`
/// (reqwest) and `ring` (the LiveKit websocket), so rustls cannot choose one itself and panics on
/// the first `wss://` connection. Idempotent: a provider that is already installed is kept.
/// Called by the CLI at start-up and by [`session::Session::connect`], so library users need not.
pub fn install_crypto_provider() {
    let _ = rustls::crypto::aws_lc_rs::default_provider().install_default();
}

#[cfg(test)]
mod tests {
    #[test]
    fn installing_the_crypto_provider_twice_is_harmless() {
        super::install_crypto_provider();
        super::install_crypto_provider();
        assert!(rustls::crypto::CryptoProvider::get_default().is_some());
    }
}
