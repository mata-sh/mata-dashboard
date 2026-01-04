<a id="readme-top"></a>

<!-- PROJECT LOGO -->
<div align="center">
  <a href="https://github.com/mata-sh/mata-dashboard">
    <img src="docs/images/logo.svg" alt="Logo" width="200" height="200">
  </a>

<h3 align="center">MATA Dashboard</h3>

  <p align="center">

`Monitoring Anomalies & Tracking Alerts`
    <br>

**MATA** is a PHP-based server monitoring solution that provides centralized oversight of multiple servers and their hosted applications. Built with security automation principles, it offers real-time monitoring, intelligent alerting, and comprehensive event logging for enterprise-grade infrastructure management.

<br>
<a href="https://github.com/mata-sh/mata-dashboard/issues/new?labels=bug&template=bug-report---.md">Report Bug</a>
&middot;
<a href="https://github.com/mata-sh/mata-dashboard/issues/new?labels=enhancement&template=feature-request---.md">Request Feature</a>
  </p>

[![Contributors][contributors-shield]][contributors-url]
[![Stargazers][stars-shield]][stars-url]
[![Issues][issues-shield]][issues-url]
[![License][license-shield]][license-url]
[![PHP][PHP-shield]][PHP.net]

</div>


<!-- ABOUT THE PROJECT -->
## What is MATA?

MATA is an enterprise-grade server monitoring solution that combines a centralized Dashboard with lightweight Node applications to provide comprehensive infrastructure oversight. The architecture enables secure, API-based monitoring of multiple servers and their hosted PHP applications through encrypted communications and intelligent alerting.

<div align="center">

![Product Name Screen Shot][product-screenshot]

</div>

### Core Features

🖥️ **Server Monitoring:** Real-time tracking of CPU usage, RAM consumption, disk space, and system processes<br>
🌐 **Application Health:** HTTP ping monitoring, error log analysis, and session tracking for web applications<br>
🚨 **Intelligent Alerting:** Threshold-based alarm system with email notifications and event aggregation<br>
🔒 **Security-First:** Encrypted API communications, rate limiting, and read-only node operations<br>
📊 **Event Logging:** Comprehensive audit trail with configurable retention and severity levels<br>
⚙️ **Configuration Management:** Centralized threshold management and server-specific customization

### Architecture Overview

- **Dashboard (Server):** Central monitoring hub with web interface and alerting system
- **Nodes (Clients):** Lightweight API endpoints deployed on monitored servers
- **Secure Communication:** Encrypted API keys with timestamp validation
- **Database Logging:** Dual-table event system for monitoring and alarm management

## Why MATA?

- **Multi-Server Visibility:** Monitor entire infrastructure from a single dashboard
- **Proactive Alerting:** Detect issues before they impact users or operations  
- **Security Automation:** Built-in protection against common attack vectors
- **Compliance Ready:** Comprehensive audit trails and event logging
- **Scalable Design:** Lightweight nodes with minimal performance impact
- **Developer Friendly:** Modern PHP architecture with clean separation of concerns

**🎯 MATA** bridges the gap between basic monitoring tools and enterprise solutions, providing the security automation capabilities essential for modern infrastructure management.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Getting Started

### Requirements

- **PHP 8.3+** with PDO, MySQL extensions
- **MySQL/MariaDB** for event logging and configuration
- **Composer** for dependency management
- **Web server** (Apache/Nginx/Caddy) with URL rewriting
- **Node.js & npm** (for building CSS assets)

### Quick Start

```bash
# Clone and install
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard

# Composer & Configs
make setup
# Edit configuration files with your database and server settings
```

**Full Documentation:**
- [Installation Guide](docs/guides/installation.md) - Detailed setup instructions
- [Configuration Guide](docs/guides/configuration.md) - Configuration reference
- [Deployment Guide](docs/guides/deployment.md) - Production deployment (Apache, Nginx, Caddy, Docker)
- [Quick Start Tutorial](docs/guides/quickstart.md) - Get monitoring in 5 minutes

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

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Design Philosophy

**Priority:** Simplicity, clarity, resilience  
**Target:** Real-world monitoring without unnecessary complexity

## Technology Choices

### Authentication: JSON File Storage
- **Why:** Dashboard stays up even if database fails
- **Security:** Bcrypt hashing, account lockout, timing attack prevention
- **Scale:** 1-20 users optimal, database migration path available

### Database: Lightweight PDO Wrapper
- **Why:** No ORM overhead or magic methods
- **Features:** Prepared statements, consistent interface
- **Best for:** Read-heavy monitoring queries

### Frontend: HTMX + Bulma CSS
- **HTMX:** Dynamic updates via HTML attributes, no JavaScript framework needed
- **Bulma:** Modern CSS framework (pre-compiled, committed to repo)
- **Benefits:** No runtime dependencies, no state management complexity

### Templates: Native PHP
- **Syntax:** Twig-like, familiar to developers
- **Security:** Automatic XSS protection
- **Dependencies:** Zero

### Caching: File-based
- **Purpose:** Reduce load on monitored servers
- **Capacity:** Handles < 50 concurrent users
- **Infrastructure:** None required

## Use Case Fit

✅ **Ideal for:**
- 1-20 monitored servers
- 1-20 team members
- Quick deployment
- Unreliable database environments

❌ **Not for:**
- Public dashboards
- 100+ concurrent users
- Complex permissions
- Sub-minute updates

## Scaling Path

1. **Start:** JSON auth + file cache (zero infrastructure)
2. **Growth:** Add database authentication  
3. **Scale:** Redis/Memcached
4. **Enterprise:** Load balancer + replicas

## Contributing

Contributions are welcome! See [Contributing Guide](docs/development/contributing.md) for development setup, code standards, and pull request guidelines.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## License

This project is licensed under the GNU Affero General Public License v3.0.
See `LICENSE.txt` for details.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

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
<!-- [product-screenshot]: docs/images/demo.gif -->
[product-screenshot]: docs/images/screenshot.png

[PHP.net]: https://php.net/
[PHP-shield]: https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white