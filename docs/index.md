# MATA Dashboard

<div align="center">
    <img src="images/screenshot.jpg" alt="MATA Screenshot" width="600">
</div>

## What is MATA?

MATA Dashboard is a PHP-based server monitoring solution that provides centralized oversight of multiple servers and their hosted applications. It offers real-time monitoring, intelligent alerting, and event logging for infrastructure teams.

### Architecture

```
┌──────────────────────────────────────┐
│       MATA Dashboard (Server)       │
│   • Web Interface & Alerting         │
│   • Event Logging & Analysis         │
│   • Threshold Management             │
└──────────────────────────────────────┘
              ↕ ↕ ↕
    Encrypted API Communication
              ↕ ↕ ↕
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node 1  │  │ Node 2  │  │ Node N  │
│(Server1)│  │(Server2)│  │(ServerN)│
└─────────┘  └─────────┘  └─────────┘
```

## Key Features

- Server monitoring: CPU, RAM, disk, and processes
- Application health: HTTP checks, error logs, session tracking
- Intelligent alerting: threshold rules with notifications
- Security-first: encrypted API, rate limits, read-only nodes
- Event logging: configurable retention and severity

## Start Here

- [Installation](guides/installation.md)
- [Quick Start](guides/quickstart.md)
- [First Server](tutorials/first-server.md)
- [Configuration](guides/configuration.md)

## Popular Docs

- [Monitoring Guide](guides/monitoring.md)
- [Security Guide](guides/security.md)
- [Deployment Guide](guides/deployment.md)
- [Configuration Reference](reference/configuration-reference.md)
- [Server Metrics](reference/server-metrics.md)
- [Client Configuration](reference/client-config.md)
- [Node API](api/node-api.md)

## Development

- [Contributing](development/contributing.md)
- [Project Structure](development/project-structure.md)
- [Testing](development/testing.md)

## Support

- [Troubleshooting](tutorials/troubleshooting.md)
- [GitHub Issues](https://github.com/xarcdotdev/mata-dashboard/issues)
- [GitHub Discussions](https://github.com/xarcdotdev/mata-dashboard/discussions)

## License

This project is licensed under the GNU Affero General Public License v3.0. See [LICENSE.txt](https://github.com/xarcdotdev/mata-dashboard/blob/main/LICENSE.txt) for details.

## Contact

**xarc** - [@xarcdev](https://x.com/xarcdev) - mata@xarc.dev

Project Link: [https://github.com/xarcdotdev/mata-dashboard](https://github.com/xarcdotdev/mata-dashboard)
