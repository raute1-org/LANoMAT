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
        'slug_taken' => 'Ein Spiel mit diesem Titel gibt es schon. Wähle einen anderen Titel.',
        'file_missing' => 'Diese Datei gehört dir nicht oder ist nicht freigegeben. Lade sie selbst hoch oder wähle eine freigegebene Datei.',
        'file_rejected' => 'Diese Datei wurde abgelehnt. Lade sie neu hoch oder wähle eine andere Datei.',
        'file_type' => 'Nur .zip- und .tar.bz2-Archive können als Spiel geteilt werden.',
        'file_name' => 'Bitte benenne die Datei um: Der Dateiname darf keinen Backtick, kein Steuerzeichen und keines der Zeichen * ? : " < > | enthalten, nicht mit einem Punkt beginnen und keine zwei Punkte hintereinander haben.',
        'file_name_pattern' => 'Bitte benenne die Datei um: Der Name enthält zu wenige Buchstaben oder Ziffern, das Dateimuster würde auch fremde Archive treffen.',
        'one_line' => 'Der Text darf keine Zeilenumbrüche oder Steuerzeichen enthalten.',
        'path' => 'Der Pfad muss relativ zum Spielordner sein: ohne führenden Schrägstrich, ohne Laufwerksbuchstabe, ohne „..“ und ohne Anführungszeichen, Semikolon, Sternchen oder andere Sonderzeichen.',
        'path_too_long' => 'Der Pfad ist zu lang (höchstens 200 Zeichen).',
    ],

    'attributes' => [
        'title' => 'Titel',
        'shared_file_id' => 'Datei',
        'platforms' => 'Plattformen',
        'platforms.*' => 'Plattform',
        'executable' => 'Startdatei',
        'linux_runner' => 'Linux-Start',
        'linux_executable' => 'Linux-Startdatei',
        'summary' => 'Kurzbeschreibung',
        'version' => 'Version',
        'homepage' => 'Homepage',
        'players' => 'Spieleranzahl',
        'multiplayer' => 'Mehrspieler',
    ],

];
