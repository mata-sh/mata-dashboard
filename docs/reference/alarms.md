# Alarm decision and delivery flow

MATA currently delivers alarms by email. The data model is channel-oriented,
but no other transport is implemented.

## Records

- An **event** is a recorded fact.
- A **logical alarm** is a stored alarm associated with an event. Policy-evaluated
  alarms are decision-driver approved; direct alarms bypass that decision.
- A **delivery** is one queued transport attempt. Retry state belongs to the
  delivery, not the logical alarm.

A policy-evaluated candidate may produce a visible logical alarm without an
email delivery when its notification rule supplies no channel.

## Policy-evaluated pipeline

```text
thresholds.json -> notification_rules.json -> decision driver -> intervals.json -> queue -> limits.json -> email
```

| Layer | Responsibility |
| --- | --- |
| `thresholds.json` | PHP decides whether an event pattern is an alarm candidate. |
| `notification_rules.json` | Enables email for the candidate or leaves it dashboard-only. |
| Decision driver | OPA or the limited built-in driver decides whether to create the logical alarm. |
| `intervals.json` | Deduplicates equivalent email deliveries before queueing. |
| Queue | Persists the logical alarm and optional delivery record. |
| `limits.json` | Applies transport limits when a due delivery is claimed. |
| Email | Sends the delivery and records its result. |

PHP owns counts, threshold windows, persistence, deduplication, and retries. OPA
is stateless and does not query the database. Lower numeric priorities dispatch
first. The current drivers use `10`, `30`, `60`, and `100`.

`ALARM_DECISION_DRIVER=opa` uses the configured OPA service.
`ALARM_DECISION_DRIVER=builtin` supports hosts without OPA. The built-in driver
allows critical events and `fatal_errors_occurring`, using email only, and
denies other candidates.

Disabled threshold entries still allow event recording but do not create alarms
through this pipeline.

## Direct alarms

Configuration and bootstrap failures use an emergency path. Direct alarms
bypass the alarm threshold gate, notification rules, the decision driver, and
`intervals.json` delivery deduplication. When persistence is available, they use
the same queue and transport limits as policy-evaluated alarms.

When delivery storage is unavailable, no email can be queued. A filesystem lock
only throttles repeated queue-failure handling.

## Deduplication and transport limits

`intervals.json` deduplicates a delivery before queueing by:

```text
event type + server + application + context + channel
```

A matching delivery inside `alarms.min_alarm_interval_minutes` is omitted, not
delayed. Another logical alarm may still be stored.

`limits.json` applies after queueing. A rate-limited delivery remains due for a
future dispatcher run through `next_retry_at`; no second alarm is created. If
`limits.json` is absent, built-in per-server/channel limits apply: 20 per minute,
120 per hour, and 500 per day.

## Example

At 10:00, an `app_unreachable` event creates a logical alarm and email delivery.
With a 60-minute deduplication interval, an equivalent event at 10:20 may create
another logical alarm, but its email delivery is omitted. A transport limit can
instead keep an already queued delivery pending until `next_retry_at`.

## Operations

Install the alarm evaluator and dispatcher entries from the
[shared-hosting crontab template](https://github.com/mata-sh/mata-dashboard/blob/main/cron/crontab.shared-hosting.template).
Docker installs the same jobs automatically. The evaluators create logical
alarms and deliveries; `cron/alarms_dispatch.php` sends due deliveries.

Inspect waiting records with:

```sql
SELECT id, alarm_id, channel, status, attempts, next_retry_at
FROM alarm_deliveries
WHERE status IN ('pending', 'retry', 'processing')
ORDER BY next_retry_at, created_at;
```

`pending` and `retry` records are claimable when due. A claim uses `processing`;
claims older than 15 minutes are treated as stale and can be recovered by a
later dispatcher. Successful deliveries become `sent`. Failed sends become
`retry` until they exhaust their attempts, then become `failed`. The schema
accepts the retained `throttled` and `discarded` states, but the current runtime
never writes either one.
