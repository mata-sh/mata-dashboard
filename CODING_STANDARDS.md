# Coding Standards

Document project-specific decisions here, not syntax already visible in nearby code or rules enforced by tooling.

## Boundaries

- Controllers only coordinate HTTP: business rules go in services, persistence in repositories, view data in builders, Twig in renderers. Route errors through `BaseController::executeWithErrorHandling()`.
- Validate route parameters in `RouterSetup`, before controller dispatch.
- Naming: `Config` = dashboard-owned startup input (`.env` or JSON); `Settings` = per-entity monitored payload values; `Policy` = derived behavior; `Context`/`Runtime` = composed collaborators. Services are `<Domain><Role>` with an agent noun (`UserAuthenticator`).
- Config factories own defaults and validation; loaders own I/O and fallback. Distinguish `default()` from `empty()`, and expose no constructor that bypasses config invariants.
- Missing required JSON config is reported, missing optional config is silent; invalid config is reported via `AbstractJsonConfigLoader::reportProblem()` and falls back to defaults. Only `servers.json` raises an alarm (no usable default).
- Accept native JSON integers only; reject out-of-bounds values instead of coercing or clamping.
- Record events via `EventManager::handle()`, never `EventRecorderService` directly (it skips `EVENTS_ENABLED` and cooldowns).

## Frontend

- JavaScript initialization must survive htmx swaps without duplicate handlers or stale component instances.
