# S10: failure tests (local, prod-like drivers)

Date: 2026-10-31. Local app instance against a throwaway database, with the same drivers as production
(`SESSION_DRIVER=database`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`), all Discord/Mumble/Pelican credentials blank.
Services were stopped and started one at a time.

| Scenario | Result |
|---|---|
| Redis stopped: `/`, `/login`, `/events` (guest) | 200 / 200 / 302, no errors |
| Redis stopped: Discord login redirect, `POST /csp-report` | 302, 204 |
| Redis stopped: `RegisterForEvent` | succeeds and is stored; registration does not enqueue anything, so nothing is lost |
| Redis stopped: `lanomat:preflight` | Redis, Scheduler and Queue-Worker report DOWN (connection refused), Datenbank OK |
| Redis restarted | pages and registration work again without restarting the app |
| Postgres stopped | `/` and `/login` answer 500 (sessions live in the database, unavoidable); `/up` stays 200; preflight reports Datenbank DOWN |
| Postgres restarted | pages answer 200 again without restarting the app |
| No queue worker / scheduler running, Redis up | preflight reports both DOWN ("Kein aktuelles Lebenszeichen"), failed jobs OK |

## Notes

- Nothing in the registration path depends on Redis. Queued notifications (check-in opened, orga ping, match ready, food order) still do: with Redis down their dispatch fails, which is why the preflight and the scheduler heartbeat matter on the day.
- `/up` (the container health check) does not touch the database, so a database outage does not restart the app container. That is intended (a restart would not help); the preflight tile is the signal.
- Not covered: a stopped Reverb (the realtime UI degrades to polling where implemented, not exercised here), disk full, and a killed Traefik. Load testing is still open (checklist row 11).
