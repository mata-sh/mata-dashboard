# Coding Standards

Document project-specific decisions here, not syntax already visible in nearby code or rules enforced by tooling.

## Boundaries

- Controllers coordinate HTTP work and return typed `Response` objects. Put business rules in services, persistence in repositories, view data in builders, and Twig rendering in renderers.
- Use the shared controller error-handling path for user-safe responses and contextual logs.
- `RouterSetup` owns route registration and HTTP middleware. Register named endpoints and validate route parameters before controller dispatch.
- Use GET for reads and POST/PUT/PATCH/DELETE for mutations.
- Replace unwieldy dependency or configuration lists with typed context DTOs. Prefer immutable DTOs at boundaries over mixed arrays.
- Use named arguments when they clarify boolean flags or long calls.
- Extract cohesive collaborators instead of expanding an already broad controller.

## Frontend

- JavaScript initialization must survive htmx swaps without duplicate handlers or stale component instances.
- Build dynamic user-controlled content with DOM APIs and `textContent`. Use `innerHTML` or Twig `|raw` only for trusted, controlled markup.
- Preserve accessible labels, semantic controls, and CSRF handling for mutations.

## Tests

- Put isolated behavior in `src/Tests/Unit`; use `src/Tests/Integration` for database or multi-component behavior.
- Cover failure and security boundaries alongside happy paths. Mock external boundaries, not the behavior under test.
