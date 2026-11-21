// Release builds on Windows are GUI applications without a console window.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    // Arcade cabinets are started by the install script with `--arcade`
    // (`--kiosk` locks the fullscreen) or through `LANOMAT_ARCADE=1`. Parsing
    // happens once, before Tauri starts, and repeated flags stay idempotent.
    lanomat_launcher_app::run(lanomat_launcher_app::ArcadeMode::detect());
}
