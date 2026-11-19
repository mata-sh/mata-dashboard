<div align="center">
  <img src="docs/images/logo.png" alt="MATA" width="140">

# MATA Dashboard

[![CI][CI-shield]][ci-url]
[![License][license-shield]][license-url]
[![HTMX][HTMX-shield]][empty-url]
![PHP 8.3+][PHP-shield]

Lightweight security and health monitoring for PHP applications, built for
shared hosting, small VPSs, and legacy servers where a full observability
stack is impractical. Learn more at [mata.sh][site-url].

[![Screenshot][product-screenshot]][demo-url]

[Live demo][demo-url] · [Documentation][docs-url]

User: **demo** | Password: **demo**

</div>

## What it does

- **Server & app health:** Monitors CPU, RAM, disk, top processes, HTTP status
  and latency, session counts, and recent application logs.
- **Dependency audits:** Runs `composer audit` across all monitored
  applications and tracks vulnerable packages in one view.
- **Security advisories:** Watches RSS/Atom feeds, GitHub advisories, IMAP
  mailboxes, OSV.dev, and CSAF providers with keyword/severity filters and
  scheduled digests (PDF, Markdown, HTML, JSON).
- **Policy-driven alarms:** Evaluates events with OPA/Rego policies (or
  built-in rules) and delivers alerts by email or webhook (e.g., Telegram).
- **Config as code & CLI:** No settings UI. Plain JSON, `.env`, and Rego files
  you can version in Git, validate in CI, and script via `bin/mata --json`.
- **Minimal footprint:** PHP 8.3+, MySQL/MariaDB, HTMX. No Node runtime, no
  JavaScript framework.

## How it works

The dashboard pulls read-only snapshots over HTTPS from stateless
[MATA Node](https://github.com/mata-sh/mata-node) agents on each host. Cron
jobs handle scheduled polling, audits, alarm evaluation/delivery, and cleanup.

- **Pull-only & authenticated:** Traffic goes only dashboard → node, signed
  with per-node HMAC tokens, with optional IP allowlists and rate limiting.
- **Read-only scope:** Collects metrics, HTTP checks, logs, and Composer
  package inventories. Never secrets, env vars, database contents, or source
  code.
- **Resilient access:** File-based, CLI-managed users with an audit log; live
  app status works even if the database is down.

See [security architecture](docs/architecture/security.md) for details and
[`SECURITY.md`](SECURITY.md) for vulnerability reporting.

## Who it's for

**Good fit**

- Agencies and small teams (1–20 people, up to ~100 nodes)
- PHP applications across modern and legacy stacks
- Shared hosting, small VPSs, and restricted environments
- Spotting vulnerable dependencies and errors across many sites without SSH hopping
- Agent-heavy workflows (file-based config, `--json` CLI output, agent runbooks, and [`llms-full.txt`](https://docs.mata.sh/llms-full.txt))

**Not a fit**

- Public or multi-tenant dashboards
- Large user populations
- Kubernetes or large-scale observability

## Quick start

**Requirements:** PHP 8.3+, MySQL or MariaDB, a web server and cron (or Docker).

Install the latest release:

```bash
# With Docker
curl -fsSL https://mata.sh/install.sh | sh

# Without Docker (native PHP)
curl -fsSL https://mata.sh/install.sh | sh -s -- --native
```

See [release integrity](docs/architecture/security.md#release-integrity) to
verify Sigstore/SLSA signatures first.

Next: [Docker quick start](docs/guides/quickstart-docker.md) ·
[Native quick start](docs/guides/quickstart.md) ·
[Install with a coding agent](docs/guides/agent-install.md) ·
[Add your first server](docs/guides/first-server.md)

## Documentation

Full docs at [docs.mata.sh][docs-url] (source in [`docs/`](docs/index.md)):

- **Setup:** [Native](docs/guides/quickstart.md) · [Docker](docs/guides/quickstart-docker.md) · [Agent install](docs/guides/agent-install.md) · [Development](docs/development/development-setup.md)
- **Guides:** [Configuration](docs/guides/configuration.md) · [Version your config](docs/guides/version-config.md) · [App monitoring](docs/guides/application-error-monitoring.md) · [Security advisories](docs/guides/security-advisories.md) · [Hardening](docs/guides/secure-application.md)
- **Reference:** [Architecture](docs/architecture/overview.md) · [Alarms](docs/reference/alarms.md) · [CLI](docs/reference/cli.md) · [Cron jobs](docs/architecture/overview.md#scheduled-work) · [`llms-full.txt`](https://docs.mata.sh/llms-full.txt)

## Project status

Used in production by its maintainers, still pre-1.0: configuration and the
node API can change between minor releases (see [`CHANGELOG.md`](CHANGELOG.md)).
Maintained by one developer alongside other work, with no support agreement or
SLA. Report bugs or questions in
[issues](https://github.com/mata-sh/mata-dashboard/issues), and read
[`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a pull request.

## License & contact

Apache License 2.0 ([`LICENSE`](LICENSE)). Bundled third-party assets keep
their own licenses ([`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)).

Contact: xarc | mata@xarc.dev

<!-- MARKDOWN LINKS & IMAGES -->
[license-shield]: https://img.shields.io/github/license/mata-sh/mata-dashboard.svg?style=for-the-badge&v2
[PHP-shield]: https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=for-the-badge&logo=php&logoColor=white
[HTMX-shield]: https://img.shields.io/badge/HTMX-36C?style=for-the-badge&logo=htmx&logoColor=fff
[CI-shield]: https://img.shields.io/github/actions/workflow/status/mata-sh/mata-dashboard/ci.yml?branch=main&style=for-the-badge

[product-screenshot]: docs/images/screenshot.jpg
[license-url]: https://github.com/mata-sh/mata-dashboard/blob/main/LICENSE
[ci-url]: https://github.com/mata-sh/mata-dashboard/actions/workflows/ci.yml
[site-url]: https://mata.sh
[demo-url]: https://demo.mata.sh
[docs-url]: https://docs.mata.sh
[empty-url]: #
