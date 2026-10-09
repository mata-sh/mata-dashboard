# Agent Notes

## Changelog

Keep `CHANGELOG.md` current without being asked, following
[CONTRIBUTING.md](CONTRIBUTING.md#release--changelog).

## Coding Standards

Follow [CODING_STANDARDS.md](CODING_STANDARDS.md). Keep it concise and non-obvious; do not restate patterns visible in the code or enforced by tooling. Update it when feedback reveals a clear recurring taste or style direction.

## Documentation

Use MkDocs admonitions in `docs/` to highlight prerequisites, conventions, and caveats.
The theme only colors `!!! info`, `!!! warning`, and `!!! danger`.

## Gotchas

Non-obvious facts that have already misled an agent. Verify before asserting otherwise.

- **Compose env:** `Makefile:22` passes `--env-file config/db.config.env --env-file .env`, so `db.config.env` *does* feed `${VAR}` interpolation; last file wins. Bare `docker compose` resolves differently than `make`.
- **Docker auth:** `users.json` lives in the `mata_users` volume, not the repo. Empty volume = CRITICAL + 500 on every route.
- **Container logs:** `/var/log/apache2/error.log` is a symlink to `/dev/stderr` — tailing it hangs. Truth is `logs/app.log`, `http`-owned, read via the container.
- **PHP ini:** images use `php-dev.ini` / `php-prod.ini`. `docker/php/php.ini` and `docker/mysql/my.cnf` are unreferenced orphans.
- **Protected config files:** `config/` is owned by the web container user; edit files there through `docker exec mata-dashboard-web` (start the container first if needed), not directly from the host.

## Non-Issues

Record very concise insights about findings the user explicitly declared non-issues. Do not treat these as bugs/action items. Include the date, affected area, finding, user decision.

Format: `YYYY-MM-DD` — **Area:** finding. **Decision:** why it is a non-issue.

2026-07-18 — **Alarm delivery claiming:** selecting and marking deliveries is not atomic across workers. **Decision:** only one non-overlapping alarm dispatch cron runs.
2026-07-21 — **Demo event selection:** `MATA_EVENTS_MOCK` and configurable mock-event paths were removed. **Decision:** demo mode always uses the fixed mock event fixture; no override is needed.
2026-08-16 — **Session save path permissions:** existing directories may be intentionally shared. **Decision:** secure directories created by the application, but do not chmod existing writable directories.