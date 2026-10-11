# Coding Standards

Document project-specific decisions here, not syntax already visible in nearby code or rules enforced by tooling; Keep it concise and non-obvious. Update it when feedback reveals a clear recurring taste or style direction. If in doubt dont touch this.

## Boundaries

- Controllers only coordinate HTTP: business rules go in services, persistence in repositories, view data in builders, Twig in renderers. Route errors through `BaseController::executeWithErrorHandling()`.
- `Models/<Domain>/` holds persistence for `Services/<Domain>/`: `<Noun>Repository` plus the records it returns; no loose files in `Models/`.
- Validate route parameters in `RouterSetup`, before controller dispatch.
- Naming: `Config` = dashboard-owned startup input (`.env` or JSON); `Settings` = per-entity monitored payload values; `Policy` = derived behavior; `Context`/`Runtime` = composed collaborators. Services are `<Domain><Role>` with an agent noun (`UserAuthenticator`).
- Missing required JSON config is reported, missing optional config is silent; invalid config is reported via `AbstractJsonConfigLoader::reportProblem()` and falls back to defaults. Only `servers.json` raises an alarm (no usable default).
- Accept native JSON integers only; reject out-of-bounds values instead of coercing or clamping.
- Record events via `EventManager::handle()`, never `EventRecorderService` directly (it skips `EVENTS_ENABLED` and cooldowns).
