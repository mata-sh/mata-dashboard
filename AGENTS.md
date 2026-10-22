# Agent Notes

## Coding Standards

Follow [CODING_STANDARDS.md](CODING_STANDARDS.md).

## Documentation

Use MkDocs admonitions in `docs/` to highlight prerequisites, conventions, and caveats.
The theme only colors `!!! info`, `!!! warning`, and `!!! danger`.
`docs/node/` is generated from mata-node by `make docs-node` and gitignored; edit those pages in mata-node.

## Changelog

Keep `CHANGELOG.md` current without being asked, following
[docs/development/changelog.md](docs/development/changelog.md).

## Tests

Add a test only for a real-world failure it would catch. Skip tautological tests that stub a dependency's return value and then assert the code passed it through.

## Gotchas

Non-obvious facts that have already misled an agent. Verify before asserting otherwise.

- **Compose env:** `Makefile:22` passes `--env-file config/db.config.env --env-file .env`, so `db.config.env` *does* feed `${VAR}` interpolation; last file wins. Bare `docker compose` resolves differently than `make`.
- **Docker auth:** `users.json` lives in the `mata_users` volume, not the repo. Empty volume = CRITICAL + 500 on every route.
- **Container logs:** `/var/log/apache2/error.log` is a symlink to `/dev/stderr` — tailing it hangs. Truth is `logs/app.log`, `http`-owned, read via the container.
- **Config ownership:** `config/` is instance-owned and may be its own git repo (`/config/` is ignored; templates are in `config.example/`). The dev entrypoint no longer re-owns it, but checkouts started with an older dev container keep an `http`-owned `config/`; edit those through `docker exec mata-dashboard-web` until it is chowned back once.
- **Secret scanning:** gitleaks is not in CI; `gitleaks-action` needs a paid licence for org repos. Run `gitleaks detect` locally. After a history rewrite, regenerate `.gitleaksignore`, since its fingerprints contain commit SHAs.

## Non-Issues

Record very concise insights about findings the user explicitly declared non-issues. Do not treat these as bugs/action items.

2026-07-18 — **Alarm delivery claiming:** selecting and marking deliveries is not atomic across workers. **Decision:** only one non-overlapping alarm dispatch cron runs.
2026-07-21 — **Demo event selection:** `MATA_EVENTS_MOCK` and configurable mock-event paths were removed. **Decision:** demo mode always uses the fixed mock event fixture; no override is needed.
2026-08-16 — **Session save path permissions:** existing directories may be intentionally shared. **Decision:** secure directories created by the application, but do not chmod existing writable directories.
2026-10-20 — **Config problem `type` formats:** env checks use dotted notification identifiers (`config.domain.missing`), JSON loaders use snake_case event types (`servers_config_invalid_entry`). **Decision:** keep both; keep each group consistent within itself.
2026-10-22 — **Cron `Pending` level:** `doctor` warns, `FleetStatus::cronLevel()` (status, dashboard) says ok. **Decision:** intentional; doctor gives install feedback, status must not alarm before cron had a chance to run.
