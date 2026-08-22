# Technology choices

MATA favors a small deployment and explicit code over a large platform stack.
The current dependencies and supported runtime are defined in
[`composer.json`](https://github.com/mata-sh/mata-dashboard/blob/main/composer.json).

## Runtime

MATA requires PHP 8.3 or newer. Composer manages the application dependencies.
Docker provides Apache, PHP, MySQL, and OPA for the bundled deployment, while a
native installation can use any web server that serves `public/` and runs PHP.

## PHP application

The application uses typed PHP services, Twig templates, and a small PDO-based
database layer rather than an ORM. SQL stays visible in repositories and
services. Prepared statements handle values supplied to queries.

The dashboard is split into controllers, services, repositories, configuration
loaders, view builders, and renderers. `RouterSetup` owns routes and middleware;
controllers coordinate requests rather than holding the monitoring rules.

## Database

MySQL or MariaDB stores data that must survive requests and cron runs:

- events, logical alarms, and alarm deliveries;
- raw, hourly, and daily server metrics;
- application and dashboard state used by monitoring jobs.

The application does not use a schema-generated ORM. Migrations live in
`migrations/` and run with `make db-migrate`.

User authentication stays in a file so the dashboard can still authenticate
when the database is unavailable. This fits the intended small team size. It
also means operators must protect the users file and run the CLI management
commands as the PHP-FPM user.

## Frontend

Twig renders HTML on the server. HTMX swaps pages and fragments without a
client-side application framework, which keeps the monitoring rules on the
server and avoids maintaining a second data model in JavaScript. JavaScript
handles interactions that need local state, such as charts and modal request
cancellation.

Bulma provides the base CSS. The project compiles Sass into
`public/assets/css/bulma.css`; `public/assets/css/main.css` contains the
application styles. The committed assets mean production does not need Node or
npm at runtime.

## Cache

The default cache is filesystem-based under `temp/`. It needs no extra service,
works on shared hosting, and can return stale data when a node is unavailable.
Cache keys are namespaced by data type and server. TTLs live in `config/ttl.json`.

File storage is the rate-limit default. APCu and Redis are optional rate-limit
backends; neither is required for the application cache or a normal
single-dashboard installation.

## Policy and alarms

The default driver sends alarm candidates assembled by PHP to OPA. Alarm
thresholds and routing live in Rego so they can be changed and tested without
redeploying the application, and so the decision logic is inspectable on its
own. PHP owns event counts, delivery deduplication, queue persistence, and
retries, because those depend on the database. Rego policies live in `policy/`
and can be tested with `make opa-test`.

Hosts that cannot run OPA can use a deliberately limited built-in driver. Direct
configuration-failure alarms bypass both drivers. See the
[alarm decision flow](../reference/alarms.md).

## Deployment

Docker is the supported repeatable deployment path. The base Compose file
contains `mata-web`, `mata-db`, and `mata-opa`. The development override mounts
the source and uses a temporary MySQL data directory. The production override
bakes the application into the image, mounts only persistent directories, and
uses a persistent MySQL bind mount.

Native and shared-hosting deployments remain useful where Docker is not
available. They need PHP, Composer, a web server, a database for persistent
monitoring, and a cron runner. Native deployments can run OPA as a service;
shared hosting can use the limited built-in alarm driver.

## Limits

MATA is for private operational dashboards, not public multi-tenant monitoring.
File-backed configuration and cache are a poor fit for many dashboard replicas
or large user populations. At that point, use a shared cache and a deliberate
identity system, or move to a monitoring platform built for that scale.

The current product model is one dashboard, pull-based polling, and scheduled
collection. Collection throughput is bounded by unreachable nodes rather than by
node count: `servers_data_fetch.php` and `servers_metrics_collect.php` run
chained in a single five-minute cron slot and iterate nodes sequentially, so
responsive nodes cost little while a node that times out consumes its full API
timeout plus health-probe retries. A lock file prevents overlapping runs, so an
overrun skips a cycle instead of stacking. The code and configuration examples
should be treated as the authority over broad capacity estimates.
