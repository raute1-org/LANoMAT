// Release builds on Windows are GUI applications without a console window.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    lanomat_launcher_app::run();
}
