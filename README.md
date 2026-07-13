<a id="readme-top"></a>

<!-- PROJECT LOGO -->
<div align="center">
  <a href="https://github.com/mata-sh/mata-dashboard">
    <img src="docs/images/logo.png" alt="Logo" width="200">
  </a>

<h3 align="center">MATA Dashboard</h3>

  <p align="center">
Modern, lightweight node and application monitoring with the needs of PHP developers kept in mind.

<br>
<a href="https://github.com/mata-sh/mata-dashboard/issues/new?labels=bug&template=bug-report---.md">Report Bug</a>
&middot;
<a href="https://github.com/mata-sh/mata-dashboard/issues/new?labels=enhancement&template=feature-request---.md">Request Feature</a>
  </p>

[![CI][CI-shield]][ci-url]
[![License][license-shield]][license-url]
[![PHP][PHP-shield]][PHP.net]
[![HTMX][HTMX-shield]][empty-url]


</div>


<!-- ABOUT THE PROJECT -->
<p>
<strong>MATA</strong> is a PHP-native monitoring solution for environments where full observability stacks are impractical.
Deploy read-only nodes on each monitored environment, manage alarms and logs from one lightweight dashboard. Works on shared hosting, constrained VPS, anywhere PHP 8.3+ runs.</p>

## Mental Model

- **Dashboard pulls data from nodes.**
- **Nodes never initiate connections.**
- **Nodes are stateless and read-only.**

MATA consists of one dashboard and one or more nodes. The dashboard polls each node for snapshots and evaluates alarms centrally. Nodes expose read-only endpoints and do not send data anywhere on their own.

<div align="center">

[![Product Name Screen Shot][product-screenshot]][demo-url]

[Check out the demo][demo-url]

User: **demo** | Password: **demo**
</div>

## Core Features

- **Application Health:** HTTP ping monitoring, error log parsing, and session tracking
- **Alarm handling:** Threshold-based alarm system with email notifications
- **Node Monitoring:** Real-time tracking of CPU/RAM/disk and system processes
- **PHP Dev Toolset:** Supports reading PHP logs and installed Composer packages.

## Why MATA?

- **Multi-Node Visibility:** One dashboard for many nodes and apps
- **Works where you can only run PHP:** Shared hosting / constrained VPS (no root, no daemons, no system packages)
- **Alarm handling:** Threshold-based alarms with email notifications
- **Least privilege by design:** Lightweight, read-only nodes with proper deployment and network isolation
- **PHP-first insights:** Error logs, Composer inventory, session monitoring
- **Resilient:** Dashboard can run in degraded mode without a DB
- **Developer Friendly:** Simple deploy, built with modern PHP and optional Docker support

## Security model

- **Read-only nodes:** stateless endpoints; no alarm handling, no emails, no remote actions.
- **Pull-only traffic:** communication is **dashboard → node** only.
- **Auth (HMAC + short TTL):** per-node shared secret; each pull request includes a timestamped HMAC token with a short TTL.
- **TLS required:** nodes accept **HTTPS only**.
- **Network controls:** optional IP allowlists for dashboard and node endpoints.
- **Abuse resistance:** rate limiting on dashboard and node APIs.
- **Account security:** CLI-driven user management with CLI-only audit logs.
- **Retention & thresholds:** configurable retention for events/logs and configurable alarm thresholds.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Architecture Overview

### **Dashboard**
The monitoring hub with web interface and alarm handling system. It pulls snapshots from nodes and can run without a database by utilizing filesystem fallback mode.

### **Nodes**
Lightweight, stateless applications offering **read-only** API endpoints. They never initiate connections and are easily deployed with minimal dependencies.


## What is collected?

**Collected**
- CPU / RAM / disk metrics
- Process snapshot (top processes)
- HTTP status (and optional latency, if enabled)
- Recent log lines
- Session counts
- Composer package inventory (names/versions)
- Explicitly **whitelisted** application metadata from config

**Not collected**
- Secrets (API keys, passwords), environment variables
- Database contents
- Application source code
- Data exposure depends on node configuration; misconfiguration can expose unintended data.


## Getting Started

### Requirements

- **PHP 8.3+**
- **MySQL/MariaDB** for event logging and configuration (optional but recommended for the dashboard)

### Quick Start

`make setup` interactively selects native (`local`) or Docker mode and the `dev`, `demo`, or `prod` environment. It stores the selection in ignored `.mata.mk`. Use `MODE=... ENV=...` for one-command overrides.

- [Quick Start Tutorial](docs/guides/quickstart.md)
- [Quick Start - Docker](docs/guides/quickstart-docker.md)


<p align="right">(<a href="#readme-top">back to top</a>)</p>


## Technology Choices

### Frontend: HTMX + Bulma CSS
- **HTMX:** Dynamic updates via HTML attributes, no JavaScript framework needed
- **Bulma:** Modern CSS framework (pre-compiled, committed to repo)
- **Benefits:** No runtime dependencies, no state management complexity

### Templates: Twig
- **Why:** Familiar to developers & fast and easy to learn
- **Security:** Automatic XSS protection

### Authentication & user management: file-based + CLI-only
- **Why:** dashboard stays up even if the database fails (degraded mode)
- **Audit:** CLI-only audit log for administrative actions


## Use Case Fit

✅ **Ideal for:**
- Agencies managing dozens of small customer nodes
- 1–20 nodes / 1–20 team members
- PHP apps with file-based sessions and messy logs
- Unreliable or legacy environments
- Hosts where you cannot install agents/exporters
- See failures across many small nodes without SSH hopping

❌ **Not for:**
- Public dashboards
- 100+ concurrent users
- Complex Kubernetes monitoring in full-control environments (use dedicated observability stacks)


## License

Licensed under the Apache License, Version 2.0.
See `LICENSE.txt` for details.

## Contact

xarc - [@xarcdev](https://x.com/xarcdev) - mata@xarc.dev

Project Link: [https://github.com/mata-sh/mata-dashboard](https://github.com/mata-sh/mata-dashboard)

<p align="right">(<a href="#readme-top">back to top</a>)</p>

<!-- MARKDOWN LINKS & IMAGES -->
[contributors-shield]: https://img.shields.io/github/contributors/mata-sh/mata-dashboard.svg?style=for-the-badge
[contributors-url]: https://github.com/mata-sh/mata-dashboard/graphs/contributors
[forks-shield]: https://img.shields.io/github/forks/mata-sh/mata-dashboard.svg?style=for-the-badge
[forks-url]: https://github.com/mata-sh/mata-dashboard/network/members
[stars-shield]: https://img.shields.io/github/stars/mata-sh/mata-dashboard.svg?style=for-the-badge
[stars-url]: https://github.com/mata-sh/mata-dashboard/stargazers
[issues-shield]: https://img.shields.io/github/issues/mata-sh/mata-dashboard.svg?style=for-the-badge
[issues-url]: https://github.com/mata-sh/mata-dashboard/issues
[license-shield]: https://img.shields.io/github/license/mata-sh/mata-dashboard.svg?style=for-the-badge
[license-url]: https://github.com/mata-sh/mata-dashboard/blob/master/LICENSE.txt
[empty-url]: #
[product-screenshot]: docs/images/screenshot.jpg
[PHP.net]: https://php.net/
[PHP-shield]: https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white
[HTMX-shield]: https://img.shields.io/badge/HTMX-36C?style=for-the-badge&logo=htmx&logoColor=fff
[CI-shield]: https://img.shields.io/github/actions/workflow/status/mata-sh/mata-dashboard/ci.yml?branch=main&style=for-the-badge
[ci-url]: https://github.com/mata-sh/mata-dashboard/actions/workflows/ci.yml
[demo-url]: https://demo.mata.sh