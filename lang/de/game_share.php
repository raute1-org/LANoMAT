<?php

return [

    // What PHP says on /games/share: the flash after a share, the refusals the
    // action adds to the form, and the German field names for the default
    // validation messages. The page's own copy lives in the Vue file.
    'flash' => [
        'created' => 'Entwurf für „:title“ gespeichert. Die Orga prüft deine Datei und gibt das Spiel frei.',
    ],

    'errors' => [
        'slug_reserved' => 'Der Titel „Share“ ist für die Teilen-Seite reserviert. Wähle einen anderen Titel.',
        'slug_taken' => 'Ein Spiel mit diesem Titel gibt es schon. Wähle einen anderen Titel oder ändere deinen eigenen Entwurf.',
        'file_missing' => 'Diese Datei gehört dir nicht oder ist nicht freigegeben. Lade sie selbst hoch oder wähle eine freigegebene Datei.',
        'file_rejected' => 'Diese Datei wurde abgelehnt. Lade sie neu hoch oder wähle eine andere Datei.',
        'file_type' => 'Nur .zip- und .tar.bz2-Archive können als Spiel geteilt werden.',
        'path' => 'Der Pfad muss relativ zum Spielordner sein: ohne führenden Schrägstrich, ohne Laufwerksbuchstabe, ohne „..“ und ohne Anführungszeichen, Semikolon, Sternchen oder andere Sonderzeichen.',
        'path_too_long' => 'Der Pfad ist zu lang (höchstens 200 Zeichen).',
    ],

    'attributes' => [
        'title' => 'Titel',
        'shared_file_id' => 'Datei',
        'platforms' => 'Plattformen',
        'platforms.*' => 'Plattform',
        'executable' => 'Programmdatei (Windows)',
        'linux_runner' => 'Linux-Start',
        'linux_executable' => 'Programmdatei (Linux)',
        'summary' => 'Kurzbeschreibung',
        'version' => 'Version',
        'homepage' => 'Homepage',
        'players' => 'Spielerzahl',
        'multiplayer' => 'Mehrspieler',
    ],

];
