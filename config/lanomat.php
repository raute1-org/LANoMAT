<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display / input timezone
    |--------------------------------------------------------------------------
    |
    | Storage stays UTC (`app.timezone`) so existing rows never shift. Events
    | are German, so everything a person reads or types — arrival days, Filament
    | date-time inputs/columns, Vue date formatting, notification times — uses
    | this zone instead. Use `App\Support\DisplayTimezone` in PHP.
    |
    */

    'timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Berlin'),

];
