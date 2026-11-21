# Bezahlung einrichten: Anleitung für die Orga

Kurze Anleitung für alle, die ein Event betreuen: wie du Tickets mit Preisen anlegst, wie Teilnehmer bezahlen,
wie du Zahlungen abhakst und wie Essensrunden abgerechnet werden. Alles findest du im Admin-Bereich (`/admin`)
beim jeweiligen Event. Das Design dahinter steht in
[`superpowers/specs/2026-11-21-payment-comfort-design.md`](superpowers/specs/2026-11-21-payment-comfort-design.md).

## Tickets anlegen (Ticket-Typen)

Jedes Event hat eine Liste **Tickets** (Tab im Event). Ein Ticket hat Name, Beschreibung, **Preis (EUR)**,
**Reihenfolge** und den Schalter **Aktiv**.

- **Anlegen:** Name und Preis eintragen (zum Beispiel `35` oder `35,00`), speichern. Der interne Schlüssel wird
  automatisch aus dem Namen erzeugt und bleibt danach fest.
- **Preis 0:** Das Ticket gilt als kostenlos, Teilnehmer sehen „kostenlos" und bekommen keine Zahlungsaufforderung.
- **Preis ändern:** gilt nur für **neue** Anmeldungen. Bestehende Anmeldungen behalten den Preis, den sie bei der
  Anmeldung hatten (siehe „Preis aus Ticket übernehmen" unten).
- **Deaktivieren:** Ein inaktives Ticket kann nicht mehr neu gewählt werden, bestehende Anmeldungen bleiben gültig.
  Gelöscht werden kann ein Ticket nur, wenn es nicht benutzt wird.
- **Das letzte aktive Ticket bleibt immer:** Es lässt sich weder deaktivieren noch löschen („Das letzte aktive
  Ticket kann nicht deaktiviert werden."). Sonst könnte sich niemand mehr anmelden. Lege zuerst das neue Ticket an,
  dann kannst du das alte deaktivieren.

Neue Events bekommen automatisch ein Ticket „Standard".

## Zahlungseinstellungen

Auf der Event-Seite im Admin gibt es oben die Aktion **Zahlungseinstellungen**:

| Feld | Bedeutung |
| --- | --- |
| Zahlungslink | `https://…`-Link, zum Beispiel `https://paypal.me/example-orga` oder ein PayPal-Pool-Link. Nur https ist erlaubt. |
| Hinweis | Kurzer Text für Teilnehmer (höchstens 160 Zeichen), zum Beispiel „Bitte als Verwendungszweck deinen Namen angeben". |
| Erinnerung nach (Tagen) | Wie viele Tage nach der Anmeldung eine Zahl-Erinnerung kommt (0 bis 60, Standard 3). |
| Erinnerung vor dem Event (Tagen) | Wie viele Tage vor dem Event erinnert wird (0 bis 60, Standard 3). |

**paypal.me oder Pool-Link:** Bei einem `paypal.me`-Link hängt LANoMAT den Betrag automatisch an
(`https://paypal.me/example-orga/35EUR`), der Teilnehmer landet also direkt beim richtigen Betrag. Ein
Pool-Link (oder jeder andere Link) wird unverändert angezeigt; dann steht der Betrag nur als Text daneben.

## Was Teilnehmer sehen

- Auf **Meine Anmeldung** steht, was noch offen ist: Betrag, Ticket, der Zahlungslink als Button, ein
  Verwendungszweck (Referenz) zum Kopieren und dein Hinweis.
- Auf der Startseite **Jetzt** erscheint eine Karte „Zahlung offen" mit dem Betrag, solange die Anmeldung
  unbezahlt, nicht storniert und der Preis größer als 0 ist. Der Link selbst steht nur auf der Anmeldeseite.
- Sobald du die Anmeldung als bezahlt markierst, verschwinden Karte und Aufforderung.

## Bezahlt markieren und „bezahlt von"

In der Teilnehmerliste (Anmeldungen im Event) siehst du pro Anmeldung Ticket, Preis, **Bezahlt** und
**Bezahlt von**.

- **Bezahlt umschalten:** öffnet einen kleinen Dialog. Im optionalen Feld **Bezahlt von** trägst du ein, wer
  bezahlt hat oder für wen, zum Beispiel bei einer Sammelüberweisung („Max, für die ganze Gruppe"). Beim
  Zurücksetzen auf unbezahlt wird die Notiz gelöscht.
- **Filter „Zahlung offen":** zeigt nur Anmeldungen, die noch nicht bezahlt sind (stornierte ausgenommen).
- **Ticket ändern:** wechselt das Ticket einer Anmeldung und übernimmt dabei den aktuellen Preis des neuen Tickets.
- **CSV-Export:** enthält zusätzlich die Spalten `ticket_price_eur` (Punkt als Dezimaltrenner) und `paid_by`.

### Preis aus Ticket übernehmen (Sammelaktion)

Hast du den Preis eines Tickets nachträglich geändert, markierst du die betroffenen Anmeldungen und wählst die
Sammelaktion **Preis aus Ticket übernehmen**. Unbezahlte Anmeldungen bekommen den aktuellen Ticketpreis;
bezahlte werden bewusst übersprungen. Die Meldung lautet zum Beispiel „2 aktualisiert, 1 übersprungen (bezahlt)".

## Essensrunden abrechnen

Jede Essensrunde (Catering) hat zwei neue Einstellungen:

- **Bezahlart:** **Bar** oder **PayPal**. Bei PayPal kannst du einen eigenen **Zahlungslink** (https) für diese
  Runde setzen; ohne eigenen Link gilt der Link aus den Zahlungseinstellungen des Events.
- **Im Ticket enthalten für:** Mehrfachauswahl der Ticket-Typen, für die diese Runde schon bezahlt ist (zum
  Beispiel eine Flatrate mit Essen). Wer so ein Ticket hat, zahlt für die Runde nichts; seine Positionen zeigen
  „inklusive". Die Hinweise **Alle** und **Keine** wählen schnell alle oder keinen Ticket-Typ aus.

Teilnehmer sehen in der Runde, was sie schuldig sind und wie sie zahlen (bar, PayPal-Button mit Betrag oder „Im
Ticket enthalten, du zahlst hier nichts."). Bei geschlossener Runde wird der Zahl-Button hervorgehoben.

In der Positionsliste der Runde hakst du ab: Pro Position **Bezahlt umschalten**, oder mit einem Klick
**Alles von &lt;Name&gt; bezahlt**, das alle Positionen dieser Person in der Runde als bezahlt markiert (enthaltene
Positionen zählen nicht als offen). Die Summen und der CSV-Export weisen enthaltene Beträge separat aus
(Spalte „Im Ticket enthalten").

Ändert sich die Auswahl „Im Ticket enthalten", werden bestehende Positionen beim Speichern neu berechnet.

## Erinnerungen

Der Befehl `registration:payment-reminders` läuft stündlich über den Scheduler und schickt eine Benachrichtigung
(Glocke und, wenn das Discord-Modul aktiv ist, als Discord-Direktnachricht) an Teilnehmer mit offener Zahlung:

- **nach der Anmeldung:** nach den eingestellten Tagen („Erinnerung nach");
- **vor dem Event:** so viele Tage vor Beginn wie eingestellt, solange das Event noch nicht begonnen hat.
- **Ruhezeiten:** Erinnerungen werden nur zwischen **09:00 und 21:00 Uhr** (Europe/Berlin) verschickt.
- Jede Erinnerung geht pro Person und Art **höchstens einmal** raus. Sind beide fällig, wird nur die Erinnerung
  „vor dem Event" verschickt. Bezahlte, stornierte und kostenlose Anmeldungen bekommen keine.
- Die Kategorie **Zahlung** lässt sich in den Benachrichtigungs-Einstellungen der Teilnehmer für Discord abschalten.

## Hinweis: „Freunde und Familie"

Bei PayPal kann man als Zahlungsart „Freunde und Familie" wählen. Dabei fallen in der Regel keine Gebühren an,
es gibt aber **keinen Käuferschutz**. Ob du diese Zahlungsart in deinem Hinweistext empfiehlst, entscheidest du
als Orga selbst; LANoMAT schreibt nichts vor und legt nichts fest.
