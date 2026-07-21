---
title: Alarm Decision and Delivery Flow
---

# Alarm Decision and Delivery Flow

## Pipeline

```text
thresholds.json -> notification_rules.json -> OPA -> intervals.json -> queue -> limits.json -> transport
```

The stages run in this order:

| Layer | Responsibility |
|---|---|
| `thresholds.json` | Decides whether an event pattern is an alarm candidate. PHP applies count-based candidate thresholds. |
| `notification_rules.json` | Constrains delivery channels. It never decides whether a logical alarm exists. |
| OPA | Stateless classification only: decides whether to create a logical alarm, then its delivery channels, priority, and reason from event type, level, threshold, and environment. It never evaluates counts. |
| `intervals.json` | Deduplicates equivalent channel deliveries. |
| Queue | Persists the logical alarm and every selected, non-deduplicated channel delivery. |
| `limits.json` | Applies per-server, per-channel transport rate limits to queued deliveries. |
| Transport | Sends a due delivery through its channel. |

OPA does not perform counts, cooldown, or transport rate-limit logic. PHP owns all count and frequency thresholds in `thresholds.json`, delivery deduplication, queue persistence, and transport rate limiting.

Delivery priority uses ascending dispatch order: `10` critical, `30` error/security, `60` warning, `100` info. Lower values dispatch first.

In `thresholds.json`, alarm count gates use `alarm_threshold_window_minutes`. An entry under `alarms` may set `enabled: false` to keep recording its events while preventing logical alarm creation.

## Events, logical alarms, and deliveries

An **event** is a recorded fact.

A **logical alarm** is a policy-approved event. It remains visible in the dashboard even when it has no channel deliveries.

A **delivery** is one queued attempt for a selected channel. A logical alarm can have multiple deliveries, one per channel. Queue state and retry metadata belong to deliveries, not logical alarms.

A **notification rule** selects or suppresses deliveries only. A disabled rule or empty channel list still permits a logical alarm when OPA allows it; it creates no deliveries.

Keep future event-emission controls and alarm-eligibility controls out of `notification_rules.json`. Add separate configuration only when those capabilities exist. Keep the current flat `config/` layout until a domain has multiple related files; then group that domain under a directory rather than creating ambiguous top-level rule files.

`intervals.json` deduplicates a delivery before it enters the queue. Its identity is:

```text
event type + server + application + context + channel
```

A matching delivery inside `alarms.min_alarm_interval_minutes` is permanently omitted. It is not queued, deferred, or sent after the interval ends.

`limits.json` applies after queueing. If transport rate limiting rejects a delivery, the delivery remains `pending`, keeps its current `attempts` value, and receives a future `next_retry_at`. It is claimable only at or after that timestamp. This is a delay, not deduplication.

## `intervals.json` legacy name

`alarms.min_alarm_interval_minutes` is a legacy name. It is a per-channel delivery-deduplication window; it does not delay a logical alarm or a queued delivery.

A future configuration migration should rename it to:

```text
alarms.delivery_deduplication_interval_minutes
```

The current key remains supported to avoid a configuration migration. JSON configuration files remain strict JSON; document the meaning outside the JSON rather than adding comments to them.

## Examples

### OPA allows an alarm, but email is deduplicated

At 10:00, an event produces a logical alarm and an email delivery. `intervals.json` sets `app_unreachable` to 60 minutes.

At 10:20, an equivalent `app_unreachable` event for the same server, application, context, and `email` channel reaches OPA. OPA returns `allow: true` and selects `email`. PHP creates the second logical alarm, but finds the matching email cooldown. The email delivery is omitted permanently.

The dashboard shows both logical alarms. Only the first has an email delivery.

### Notification rule creates a dashboard-only alarm

A critical event passes its threshold and OPA allows it. Its notification rule is disabled, so PHP supplies no candidate delivery channels. PHP persists the logical alarm without deliveries. The dashboard shows the alarm; no transport is attempted.

### Queued email is delayed by `limits.json`

`limits.json` allows one email per server per minute. At 10:00:30, a queued email is claimed, but an email for that server was already sent at 10:00:00. The rate limiter rejects it.

The dispatcher leaves the delivery `pending`, does not increment `attempts`, and sets `next_retry_at` to 10:01:30. A dispatcher run before 10:01:30 does not claim it. At or after 10:01:30, it becomes eligible for another transport attempt.

## Operations

Schedule `cron/alarms_dispatch.php` at least once per minute. To inspect delayed deliveries:

```sql
SELECT id, alarm_id, channel, status, attempts, next_retry_at
FROM alarm_deliveries
WHERE status IN ('pending', 'retry')
ORDER BY next_retry_at, created_at;
```

Terminal delivery states are `sent`, `failed`, and `discarded`. `processing` is an active claim and must not be claimed again.
