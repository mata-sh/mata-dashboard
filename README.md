<a id="readme-top"></a>

<!-- PROJECT LOGO -->
<div align="center">
  <a href="https://github.com/mata-sh/mata-dashboard">
    <img src="docs/images/logo.png" alt="Logo" width="200">
  </a>

<h3 align="center">MATA Dashboard</h3>

  <p align="center">
Modern, lightweight server and application monitoring with the needs of PHP developers kept in mind.

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
Deploy read-only nodes on each server, manage alerts and logs from one lightweight dashboard. Works on shared hosting, constrained VPS, anywhere PHP 8.3+ runs.</p>

<div align="center">

[![Product Name Screen Shot][product-screenshot]][demo-url]

[ Check out the demo][demo-url]

user: **demo** | password: **demo**
</div>

## Core Features

- **Application Health:** HTTP ping monitoring, error log parsing, and session tracking
- **Alerting:** Threshold-based alarm system with email notifications
- **Server Monitoring:** Real-time tracking of CPU/RAM/disk and system processes
- **PHP Dev Toolset:** Supports reading of PHP logs and installed Composer packages. Redis planned for future releases.

## Why MATA?

- **Multi-Server Visibility:** One dashboard for many servers and apps
- **Works where you can only run PHP:** Shared hosting / constrained VPS (no root, no daemons, no system packages)
- **Alerting:** Threshold-based alerts with email notifications
- **Least privilege by design:** Lightweight, read-only nodes
- **PHP-first insights:** Error logs, Composer inventory, session monitoring
- **Resilient:** Dashboard can run in degraded mode without a DB
- **Developer Friendly:** Simple deploy, built with modern PHP and optional Docker support

## Security model

- **Read-only nodes:** stateless endpoints; no alerting, no emails, no remote actions.
- **Pull-only traffic:** communication is **dashboard → node** only.
- **Auth (HMAC + short TTL):** per-node shared secret; each request includes a timestamped HMAC token with a short TTL.
- **TLS required:** nodes accept **HTTPS only**.
- **Network controls:** optional IP allowlists for dashboard and node endpoints.
- **Abuse resistance:** rate limiting on dashboard and node APIs.
- **Account security:** CLI-driven user management with CLI-only audit logs.
- **Retention & thresholds:** configurable retention for events/logs and configurable alert thresholds.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Architecture Overview

### **Dashboard (Server)**
A central monitoring hub with web interface and alerting system. Capable of running without database by utilizing filesystem fallback mode.

### **Nodes (Clients)**
Lightweight, stateless client application offering **read-only** API endpoints. Easily deployed with minimal dependencies.


## What is collected?

**Collected**
- CPU / RAM / disk metrics
- process list snapshot (top processes)
- HTTP status (and optional latency, if enabled)
- Recent PHP error log lines
- session counts
- Composer package inventory (names/versions)
- explicitly **whitelisted** application metadata from config

**Not collected**
- secrets (API keys, passwords), environment variables
- database contents
- application source code


## Getting Started

### Requirements

- **PHP 8.3+**
- **Web server** (Apache/Nginx/Caddy) with URL rewriting
- **MySQL/MariaDB** for event logging and configuration (optional but recommended for Dashboard server)

### Default Ports

The MATA stack uses **decade-spaced ports** for clean separation and easy scaling:

| Port | Service | Description |
|------|---------|-------------|
| **3300** | Dashboard | Main web interface |
| **3310** | Node API | Remote monitoring endpoint (for local testing) |
| **3320** | OPA Service | Policy evaluation engine |
| **3330** | MkDocs | Documentation server (development) |
| **3306** | MySQL | Database (standard port) |

> **Decade spacing** leaves room for expansion (e.g., 3301-3309 reserved for dashboard features). Multiple stacks can use 3400, 3410, 3420... to avoid conflicts.

Customize ports in your `.env` file.

### Quick Start

Make sure you have a working PHP 8.3+ environment with composer and git installed.

#### 1. Client Nodes

```bash
# ssh into your server and clone the repo for the client node
git clone https://github.com/mata-sh/mata-node.git

# Runs composer install and creates config files
cd mata-node && make setup

# Edit node configuration (apps to monitor, log paths, session paths, etc.)
vi config/apps.json
vi config/config.env # shared secret
```

#### 2. Dashboard 

```bash
# ssh into your server and clone the repo for the dashboard
git clone https://github.com/mata-sh/mata-dashboard.git

# Runs composer install and creates config files
cd mata-dashboard && make setup

# Edit configuration files with your database and the servers to monitor
vi config/servers.json # node URLs & shared secrets
vi config/mail.config.env # smtp server
vi config/db.config.env # database connection

# Create a user and password with admin role for the dashboard
make users-create
```

#### 3. Subdomain

**Create 2 subdomains for the dashboard and the client nodes.**
```
mata.example.com -> public_html/mata-dashboard/public
mata.api.example.com -> public_html/mata-node/public
```
-> Access the dashboard at `https://mata.example.com`


- [Quick Start Tutorial](docs/guides/quickstart.md) - Get monitoring in 5 minutes
- [Quick Start - Docker](docs/guides/quickstart-docker.md) - Get monitoring in 5 minutes with Docker


<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Documentation

- **[Installation Guide](docs/guides/installation.md)** - Complete setup instructions
- **[Configuration Reference](docs/guides/configuration.md)** - Configuration file documentation
- **[Deployment Guide](docs/guides/deployment.md)** - Production deployment for Apache, Nginx, Caddy, Docker
- **[Monitoring Guide](docs/guides/monitoring.md)** - Server metrics, application monitoring, event system
- **[Security Guide](docs/guides/security.md)** - Security best practices and hardening
- **[API Documentation](docs/api/)** - Dashboard and Node API reference
- **[Architecture Overview](docs/architecture/overview.md)** - System design and components
- **[Troubleshooting](docs/tutorials/troubleshooting.md)** - Common issues and solutions

## Technology Choices

### Authentication & user management: file-based + CLI-only
- **Why:** dashboard stays up even if the database fails (degraded mode)
- **Storage:** `users.json` stores **password hashes only**
- **Controls:** restrictive permissions enforced; user changes via CLI tool
- **Audit:** CLI-only audit log for administrative actions
- **Scale:** optimized for small teams (≈1–20 users)

### Frontend: HTMX + Bulma CSS
- **HTMX:** Dynamic updates via HTML attributes, no JavaScript framework needed
- **Bulma:** Modern CSS framework (pre-compiled, committed to repo)
- **Benefits:** No runtime dependencies, no state management complexity

### Templates: Twig
- **Why:** Familiar to developers & fast and easy to learn
- **Security:** Automatic XSS protection

### API Caching: File-based
- **Purpose:** Reduce load on monitored servers
- **Capacity:** Handles < 50 concurrent users efficiently


## Use Case Fit

✅ **Ideal for:**
- agencies managing dozens of small customer servers
- 1–20 monitored servers / 1–20 team members
- PHP apps with file-based sessions and messy logs
- unreliable or legacy environments
- hosts where you cannot install agents/exporters
- see failures across many small servers without SSH hopping


❌ **Not for:**
- public dashboards
- 100+ concurrent users
- complex Kubernetes monitoring in full-control environments (use dedicated observability stacks)

## Contributing

Contributions are welcome! See [Contributing Guide](docs/development/contributing.md).


## License

This project is licensed under the MIT License.
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