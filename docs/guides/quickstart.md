# Quick Start

Get MATA Dashboard up and running in minutes with this quick start guide.

!!! info "Requirements:" 
    You only need **PHP 8.3+**, **composer** and **git**. A SQL-Database is recommended for the Dashboard application though.

<!-- - [Docker Deployment Guide](quickstart-docker.md) for using docker. -->

## 1. Client Node

The client is the application living on the server where the applications are you wantto monitor. 

```bash
# ssh into your server and clone the repo for the mata client
git clone https://github.com/mata-sh/mata-node.git && cd mata-node

# Runs composer install and creates config files
make setup

# generate api key
openssl rand -hex 32

# set DASHBOARD_API_KEY
vi config/config.env 

# Edit node configuration (apps to monitor, log paths, session paths, etc.)
vi config/apps.json
```


## 2. Server Dashboard 

This is the Innterface that fetches data from the client nodes on the same or other servers.

```bash
# ssh into your server and clone the repo for the dashboard
git clone https://github.com/mata-sh/mata-dashboard.git && cd mata-dashboard

# Select native/local mode and the dev, demo, or prod environment when prompted.
# Setup saves the selection in ignored .mata.mk, installs dependencies,
# offers native OPA setup, and can create the first user. Native OPA uses
# make setup-opa plus the systemd service installed by make opa-install-service.
make setup

# Create another dashboard user later when needed.
# Use MODE=... ENV=... to override the saved selection for one command.
make users-create

# smtp server (optional)
vi config/mail.config.env 

 # database connection (optional)
vi config/db.config.env
```

**Configure the applications to be monitored**

```bash
 # client node URLs & shared secrets
 vi config/servers.json
```

```json
// Set Api key you generated for your client node (DASHBOARD_API_KEY)
{
  "server-id": {
    "api_key": "32_character_hexadecimal_api_key_here"
  }
}
```

## 3. Subdomains

**Create 2 subdomains for the dashboard and the client nodes.**

```
mata.example.com -> public_html/mata-dashboard/public
mata.api.example.com -> public_html/mata-node/public
```

-> Access the dashboard at `https://mata.example.com`


## Next Steps

- [Adding Your First Server Tutorial](../tutorials/first-server.md) - Detailed walkthrough
- [Deployment Guide](deployment.md) for production setup.
- [Configuration Guide](configuration.md) - Customize your monitoring setup
<!-- - [Monitoring Guide](monitoring.md) - Learn about monitoring features -->

For troubleshooting help, see the [Troubleshooting Guide](../tutorials/troubleshooting.md).

