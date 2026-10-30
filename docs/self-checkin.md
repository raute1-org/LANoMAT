# Self check-in via on-site QR

Participants check themselves in with their phone: scan the ONE QR code posted at the venue, log in with Discord if needed, tap "Einchecken". The orga scanner flow (`/orga/events/{slug}/checkin`, personal ticket QR) is unchanged and uses the same `CheckInRegistration` action, so `checked_in_at` and the `PresenceUpdated` event are identical.

## Orga: camera scanner

`/orga/events/{slug}/checkin` has a **Kamera scannen** button (helper and above). It opens the rear camera and scans personal ticket QR codes continuously: each code is POSTed to the same endpoint (`CheckInRegistration`, same validation) and the result panel shows the participant's name and ticket label (or the German failure reason). The same code is ignored for 4 seconds and nothing is scanned while a check-in is in flight, so holding the phone steady never double-submits. The token field stays as the fallback (also shown for camera permission denied, no camera, camera busy and non-HTTPS pages: browsers only allow cameras on `https://` or `localhost`).

Decoding uses the native `BarcodeDetector` where available (Chrome on Android) and otherwise the `barcode-detector` ponyfill (ZXing WASM). The WASM binary is bundled and served from our own origin, so the scanner works on an offline LAN and needs `script-src 'wasm-unsafe-eval'` if a CSP is enforced. Only the scanned token leaves the device (the POST); no frames are uploaded. The stream is stopped when you press **Kamera stoppen**, leave the page or close the tab.

## Orga: print and post the code

1. Admin panel -> Events -> edit the event -> **Check-in-QR** (modal with the QR and its URL).
2. **Drucken** opens a clean sheet (`/orga/events/{slug}/checkin-qr`) with a large QR, the event name and "QR-Code scannen und einchecken". Print it, hang it at the entrance.
3. The code is fixed per event (`/checkin/{token}`) and created on first use.

## Rotation

**Token erneuern** (header action, with confirmation) replaces the secret immediately. Old printed codes then answer a generic 404. Rotate if a code leaked (photo shared outside the venue) and re-print. Storage: table `event_checkin_tokens` (Registration module; one row per event, `token`, `rotated_at`).

## Who may check in, and when

- Must be logged in (guests are sent to the Discord login and return to the check-in page).
- Must hold a non-cancelled registration for that event. Paid or unpaid does not matter.
- Window (single source: `Registration\Support\SelfCheckInWindow`, `HOURS_BEFORE_START = 12`): event `live`, OR `registration` and now >= `starts_at` minus 12 hours. Before that the page says "Einchecken noch nicht möglich" with the opening time; after `finished`/`archived` it says the event is over.
- A second tap is harmless: the page shows "Du bist eingecheckt seit HH:MM".

## Walk-ins

Registration stays open while the event is `live` as long as spots remain (`RegistrationAvailability`, see docs/design.md). On the check-in page a not-registered user gets "Jetzt anmelden" and can check in right after (redirect back). If the event is full the page says "Ausgebucht, bitte wende dich an die Orga."

## Privacy

The token only identifies the event. The page never receives a ticket `qr_token` or other participants' data; the token itself is not part of the Inertia props (it lives in the URL). Endpoints are throttled (30 requests/minute per user).

## Deployment note

The QR encodes an absolute URL built from `APP_URL`. In production `APP_URL` must be a host that phones on the venue Wi-Fi (or mobile data) can resolve and reach; otherwise every printed code points to an unreachable address. Check it before printing.
