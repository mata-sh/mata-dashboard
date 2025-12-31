---
title: Alert Limiting & Dispatch
---

# Alert Limiting & Dispatch

This document describes the alarm limiting workflow introduced in `ALARM_LIMITING.md`. It covers configuration, runtime behaviour, and operational procedures for the alert queue and dispatcher.

## Overview
- Alerts are recorded via `EventManager`, stored in `dashboard_event_log`, and enqueued through `AlertManager`.
- `dashboard_alarm_log` now represents a delivery queue with statuses `pending`, `processing`, `sent`, `retry`, `failed`, `throttled`, and `discarded`.
- A dedicated cron job (`cron/dispatch_alerts.php`) processes queued alerts, honours per-channel rate limits, and updates retry metadata.
- When the database is unavailable, filesystem locks under `temp/alert_locks/` prevent runaway alert fan-out.

## Configuration
- Limits live in `config/limits.json`. Structure:
  ```json
  {
    "channels": {
      "email": {"per_minute": 30, "per_hour": 200, "per_day": 1000},
      "default": {"per_minute": 20, "per_hour": 120, "per_day": 500}
    },
    "overrides": {
      "server-alpha": {
        "email": {"per_minute": 10, "per_hour": 80, "per_day": 300}
      }
    }
  }
  ```
- Override keys must match server names defined in `config/<env>/servers.json`.
- Adjust limits per environment by copying `config/limits.json` alongside other environment-specific config files.
- Update `data_retention.json` to control how long sent/terminal alerts are kept (default 30 days).

## Runtime Components
- **AlertManager**: Persists queue entries and falls back to filesystem locks when DB writes fail.
- **AlertDispatcher**: Fetches pending alerts, enforces limits via `AlertRateLimiter`, dispatches through `AlertEmailSender`, and updates statuses.
- **ChannelLockManager**: Maintains per `(server, channel)` timestamps on disk (`temp/alert_locks/<server>/<channel>.lock`).
- **AlertRateLimiter**: Applies per-minute/hour/day ceilings using historical send counts.

## Operational Procedures
1. **Queue Processing Cron**  
   Schedule `cron/dispatch_alerts.php` to run every minute (or faster) alongside existing monitoring jobs. It is self-locking and safe to run concurrently with other cron scripts.

2. **Monitoring**  
   - Track cron logs under `logs/cron/dispatch_alerts.log`.  
   - Metrics are emitted via log context (`status`, `channel`, `server`) and can be scraped by existing log aggregation.
   - When the dispatcher throttles alerts, it leaves entries in `pending` with an updated `next_retry_at`.

3. **Backlog Inspection**  
   - Pending alerts:  
     ```sql
     SELECT * FROM dashboard_alarm_log WHERE status IN ('pending','retry') ORDER BY created_at;
     ```
   - Failed alerts include diagnostic payloads in `error_payload`.

4. **Fallback Behaviour**  
   - If MySQL is unavailable, AlertManager writes timestamps to `temp/alert_locks`. Clearing the lock files resets throttling once service is restored.
   - Dispatcher resumes automatically; no manual steps needed beyond ensuring cron job is running.

5. **Retention & Cleanup**  
   - Terminal statuses (`sent`, `failed`, `discarded`) are removed after the retention period defined in `config/data_retention.json`.
   - Consider a nightly job to prune old queue rows if not already configured.

## Validation & Testing
- `composer phpunit --filter ChannelLockManagerTest` covers filesystem lock semantics.
- `composer phpunit --filter AlertLimitHelperTest` validates limit spacing calculations.
- `composer phpunit --filter AlertRateLimiterTest` validates enforcement of per-channel counters.

## Migration Notes
- Run the migration `20251104120000_update_dashboard_alarm_log_queue.php` after deploying code.
- Ensure `config/limits.json` is deployed before enabling the dispatcher cron.
- Review `ALARM_LIMITING.md` for the implementation plan and outstanding open questions.
