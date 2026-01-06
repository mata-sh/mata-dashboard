

## Security model

### Authentication (Dashboard ⇄ Nodes)
MATA uses a **dashboard → node** pull model. The dashboard polls each node over HTTPS and authenticates using a **shared secret** configured on both sides.

- The dashboard generates a short-lived token using **HMAC(secret, timestamp + request data)**.
- The token is sent with every request (e.g. via `Authorization: Basic …`).
- The node verifies the HMAC and **rejects expired tokens** (short TTL) to limit replay.

Nodes do not call back into the dashboard. All alerting logic (email, escalation, notification routing) lives exclusively in the dashboard.

### Transport security (TLS required)
Plain HTTP is deactivated by default via servers.json configuration. Nodes therefore **only accept HTTPS URLs**.

### Key scoping and rotation
Today, authentication is based on a **single per-node shared secret** stored in `servers.json` dashboard  and config.env on the node.
- **Scope:** secrets should be unique per node (do not reuse across nodes).
- **Rotation:** rotation is supported operationally by replacing the secret on the node and dashboard (brief monitoring interruption while configs are updated).
Planned improvements include **mTLS (client certificates)** and/or asymmetric signing for stronger operational security and easier rotation.

### Threat model (high level)
Assumptions:
- An attacker might gain network access, attempt replay, or obtain a node’s shared secret.
- A node host might be partially compromised.

Impact and containment:
- Node APIs are **read-only** and **stateless**. They cannot send emails, mutate dashboard configuration, or execute remote actions.
- The dashboard treats node responses as **untrusted input** and validates/sanitizes data before display.
- If a node is compromised, the attacker’s capabilities are limited to **exposing/forging monitoring data** for that node (e.g. misleading metrics/log excerpts). They do not gain the ability to trigger email alerts or pivot into the dashboard through “push” traffic.

Recommended response:
- Rotate the affected node secret.
- Re-issue TLS certificates (if applicable).
- Review dashboard logs for failed auth attempts and unusual polling patterns.

### Hardening expectations (shared hosting / low-permission environments)
MATA is designed for constrained hosting, but it still expects basic web isolation:

- Only the `public/` directory should be web-accessible.
  - Point your (sub)domain’s document root to `…/public/` for both dashboard and nodes.
- Deny direct access to configuration and internal paths (`config/`, `storage/`, `vendor/`, logs, etc.).
- Keep `config.env` and secrets **outside the web root** and readable only by the web user.
- Use strong, unique secrets per node and keep file permissions tight.

No system-level agents, daemons, or elevated privileges are required.

### Authentication storage (users.json)

MATA intentionally stores dashboard user accounts in a local `users.json` file to keep the dashboard **operational without a database** (e.g., in constrained/shared-hosting environments).

**What is stored**
- Only **password hashes** (no plaintext passwords). No API keys or server credentials are stored in `users.json`.

**How it’s managed**
- User management is performed **only via a PHP CLI tool** (no web UI for user admin).
- The CLI tool writes an **append-only audit log** of all user-management actions. This audit log is **CLI-only** (not exposed via the web UI).
- The application checks that `users.json` has **restrictive file permissions** and refuses unsafe configurations.

**Operational controls**
- You can restrict who is allowed to run the CLI tool by configuring an allowlist of **Linux users** in `config.env` (useful when multiple shell users exist on the same host).
- Recommended deployment: keep `users.json` **outside the web root** and only expose `public/`.

**Limitations (current)**
- There is currently **no built-in backup workflow** and **no automatic credential rotation** for user records.
- Availability depends on the integrity of the local file; operators should back up the config/state directory at the system level.
- No password recovery or reset functionality is provided.

**Blast radius**
- The dashboard is **read-only** with respect to monitored systems: it does not deploy changes, execute actions on servers, or expose sensitive secrets by design.
- A compromised dashboard account primarily risks **visibility of monitoring data**, not control over the monitored infrastructure.


