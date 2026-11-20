# Monitoring

MATA Dashboard polls configured MATA Nodes and records the results. Scheduled jobs keep current application data, historical server metrics, events, alarms, and deliveries up to date.

## Configure a node

Add a node to `config/servers.json` and its key to `config/servers.secrets.json`. The `api_url` and `api_key` must match the node's public URL and `DASHBOARD_API_KEY`. See [Add your first server](https://docs.mata.sh/guides/first-server/index.md).

## Collection jobs

- `servers_data_fetch.php` fetches application data and records application, reachability, log-size, and cronjob events.
- `servers_metrics_collect.php` stores raw CPU, RAM, disk, file, and process snapshots.
- `servers_metrics_aggregate.php` stores hourly and daily summaries.
- `libraries_audit.php` records new Composer security advisories.
- The alarm evaluation jobs apply thresholds and the configured OPA or built-in decision driver.
- `alarms_dispatch.php` sends due deliveries by email and webhook.

See the [server metrics pipeline](https://docs.mata.sh/architecture/server-metrics-pipeline/index.md) for schedules, tables, retention, and diagnostics.

## Application monitoring

Application error, log-size, session, and reachability behavior is controlled by the node's per-app `monitoring` settings. The dashboard also has two server-scoped configuration flags. See [Application error monitoring](https://docs.mata.sh/guides/application-error-monitoring/index.md).

### Framework and CMS labels

Nodes may include `framework: {"name": "shopware", "version": "6.7.0"}` in each `/apps` entry. The version is optional or `null`. Application cards show the name and version on hover, using a bundled logo for Laravel, Symfony, WordPress, Drupal, TYPO3, Shopware, Joomla, CakePHP, Sylius, Craft CMS, Statamic, Contao, Sulu, Neos, October CMS, Yii and CodeIgniter. Magento is labelled but uses the generic icon, as Adobe does not license its marks.

Custom names are shown verbatim as escaped text with a generic box icon. Names never select arbitrary files or URLs; all icons ship with the dashboard. A missing bundled logo also uses the generic icon. An omitted or `null` framework, or the name `none`, shows no framework icon, including on older nodes.

## Alarm delivery

Events, logical alarms, and deliveries are separate records. A notification rule can select no channel without hiding a policy-evaluated logical alarm; direct configuration-failure alarms bypass those rules. See the [alarm decision and delivery flow](https://docs.mata.sh/reference/alarms/index.md).

## Status from the command line

`bin/mata status` reports node health and cron jobs for scripts, CI gates, and Nagios-style checks:

```
php bin/mata status
php bin/mata status --json
make mata ARGS=status             # as the right account, also in Docker
```

It reads only what cron already wrote (the health cache from `servers_data_fetch` and the cron heartbeats), so it never contacts a node and can run as often as needed. The exit codes follow Nagios and are listed in the [Operator CLI](https://docs.mata.sh/reference/cli/#exit-codes) reference.

Right after an install, jobs that run daily or every six hours have not run yet. During cron's first day they are `pending` and count as ok. A job that still has not run after that is missing from the crontab and reported as `never`.

Scope

The status covers node health and cron. Metric thresholds, application probes, and alarms are only shown in the dashboard.
