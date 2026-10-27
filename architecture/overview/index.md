# Architecture overview

MATA has one dashboard and one or more MATA Nodes. The dashboard pulls snapshots from nodes. Nodes are stateless, read-only, and never initiate connections.

## Main components

### Dashboard

The PHP dashboard provides the authenticated web interface, configuration loading, event storage, alarm evaluation, notification delivery, and historical metrics.

- `public/index.php` bootstraps the web request.
- `src/Http/Routing/RouterSetup.php` registers routes and middleware.
- Controllers coordinate HTTP work.
- Services contain collection, monitoring, alarm, cache, and notification logic.
- `src/Views/` and Twig templates render HTML and HTMX fragments.
- MySQL or MariaDB stores events, alarms, deliveries, and historical metrics.
- Files store configuration, sessions, caches, and logs.

### MATA Node

A node runs on each monitored environment. It exposes read-only JSON endpoints for server and application snapshots. It does not store monitoring history, send email, execute remote commands, or call the dashboard.

See the [MATA Node README](https://github.com/mata-sh/mata-node) for its API and configuration contract.

## Request flow

A normal dashboard refresh follows this path:

```
browser
  -> router and middleware
  -> controller
  -> cache or MATA Node request
  -> response validation and view builder
  -> Twig or JSON response
```

The router applies login protection to `/watch/*` and `/api/*`, CSRF checks to unsafe methods, optional IP allowlisting, and separate rate limits for login, dashboard, and API routes. `/health` is public.

## Scheduled work

Docker installs the cron manifest as the `www-data` crontab. Shared-hosting installations can use the template in `cron/crontab.shared-hosting.template`.

The important jobs are:

- `servers_data_fetch.php` fetches node application data and records events.
- `servers_metrics_collect.php` writes raw historical server snapshots.
- `servers_metrics_aggregate.php` writes hourly and daily metric rows.
- `libraries_audit.php` records new Composer security advisories.
- `alarms_evaluate_critical.php` and `alarms_evaluate_non_critical.php` create logical alarms.
- `alarms_dispatch.php` sends due delivery records.
- `retention_cleanup.php` removes expired database rows and reconciles config-warning notifications.
- `rate_limit_cleanup.php` removes expired file-based rate-limit state.
- `cron_history_cleanup.php` removes heartbeat files of cron jobs that no longer exist.

See [Server metrics pipeline](https://docs.mata.sh/architecture/server-metrics-pipeline/index.md) and the [alarm decision flow](https://docs.mata.sh/reference/alarms/index.md) for the detailed paths.

## Storage boundaries

| Data                                  | Storage                                                           |
| ------------------------------------- | ----------------------------------------------------------------- |
| Users                                 | `MATA_USERS_FILE`, falling back to `config/users.json` when unset |
| Node/API and template cache           | `temp/`                                                           |
| Application and cron logs             | `logs/`                                                           |
| Events, alarms, deliveries            | MySQL/MariaDB                                                     |
| Raw, hourly, and daily server metrics | MySQL/MariaDB                                                     |
| Alarm decisions                       | `policy/*.rego` through OPA, or the limited built-in driver       |

The dashboard can serve basic pages in degraded mode without a database. Event history, alarm processing, scheduled collection, and historical metrics need a working database.

## Alarm model

MATA keeps three concepts separate:

- An **event** is a recorded fact.
- A **logical alarm** is a stored alarm associated with an event.
- A **delivery** is one queued attempt on one channel, by email or webhook.

The normal path applies thresholds, notification rules, and OPA or the built-in decision driver. Direct configuration-failure alarms bypass notification rules and the decision driver. Read the [alarm decision and delivery flow](https://docs.mata.sh/reference/alarms/index.md) for details.
