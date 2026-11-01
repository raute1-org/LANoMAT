fn main() {
    // tauri-build defaults to "static VC runtime, dynamic UCRT" on Windows, which tells the linker to
    // drop the static UCRT. The prebuilt libwebrtc is built against the fully static CRT (/MT, see
    // `.cargo/config.toml`), so the app has to link the whole CRT statically as well.
    let windows = tauri_build::WindowsAttributes::new().static_vc_runtime(false);
    tauri_build::try_build(tauri_build::Attributes::new().windows_attributes(windows))
        .expect("failed to run tauri-build");
}
