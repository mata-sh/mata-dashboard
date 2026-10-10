# Alarm decision and delivery flow

MATA delivers alarms by email and through outgoing [webhook channels](#channels),
such as Telegram. Each channel is delivered, retried, and rate-limited independently.

## Records

- An **event** is a recorded fact.
- A **logical alarm** is a stored alarm associated with an event. Policy-evaluated
  alarms are decision-driver approved; direct alarms bypass that decision.
- A **delivery** is one queued transport attempt. Retry state belongs to the
  delivery, not the logical alarm.

A policy-evaluated candidate may produce a visible logical alarm without any
delivery when its notification rule supplies no channel.

## Policy-evaluated pipeline

```text
thresholds.json -> notification_rules.json -> decision driver -> intervals.json -> queue -> limits.json -> email / webhooks
```

| Layer | Responsibility |
| --- | --- |
| `thresholds.json` | PHP decides whether an event pattern is an alarm candidate. |
| `notification_rules.json` | Selects the candidate's channels or leaves it dashboard-only. |
| Decision driver | OPA or the limited built-in driver decides whether to create the logical alarm. |
| `intervals.json` | Deduplicates equivalent deliveries per channel before queueing. |
| Queue | Persists the logical alarm and optional delivery record. |
| `limits.json` | Applies transport limits when a due delivery is claimed. |
| Email / webhooks | Sends the delivery and records its result. |

PHP owns counts, threshold windows, persistence, deduplication, and retries. OPA
is stateless and does not query the database. Lower numeric priorities dispatch
first. The current drivers use `10`, `30`, `60`, and `100`.

`ALARM_DECISION_DRIVER=opa` uses the configured OPA service.
`ALARM_DECISION_DRIVER=builtin` supports hosts without OPA. The built-in driver
allows critical events and `fatal_errors_occurring` on the channels their
notification rule selects, and denies other candidates.

Disabled threshold entries still allow event recording but do not create alarms
through this pipeline.

## Direct alarms

Configuration and bootstrap failures use an emergency path. Direct alarms
bypass the alarm threshold gate, notification rules, the decision driver, and
`intervals.json` delivery deduplication. They are always delivered by email, never
through webhook channels. When persistence is available, they use the same queue
and transport limits as policy-evaluated alarms.

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

## Channels

`email` is built in and configured in `config/mail.config.env`, with recipients
in `MAIL_ALARM_RECIPIENTS`. Every other channel is an entry in the optional
`config/channels.json`, named by its key. Route alarms to a channel by listing its
name in `notification_rules.json`. Deduplication, rate limits, and retries are kept
per channel. A channel that keeps failing does not affect the others.

```json
{
  "channels": {
    "telegram": {
      "type": "webhook",
      "url_env": "WEBHOOK_TELEGRAM_URL",
      "template": "telegram",
      "params": {"chat_id": "-1001234567890"}
    }
  }
}
```

| Key | Meaning |
| --- | --- |
| `type` | Required. `webhook` is currently the only type. |
| `enabled` | Optional, default `true`. A disabled channel is ignored. |
| `url_env` | Environment variable in `config/config.env` holding the URL. |
| `template` | Payload template in `templates/webhooks/`: `telegram` or `generic`. |
| `params` | Optional strings, numbers, or booleans passed to the template, such as `chat_id`. |
| `timeout_seconds` | Optional integer from 1 to 30, default 5. |

!!! warning "Webhook URLs are secrets"
    A Telegram bot token or a Slack or Discord webhook path grants anyone
    permission to post as you. Keep URLs in `config/config.env`, never in
    `channels.json`. Delivery errors stored in `alarm_deliveries` record the
    host and response, with the URL redacted.

URLs must use `https`. `WEBHOOK_ALLOW_INSECURE=true` permits `http` for a
receiver on a private network. Redirects are not followed: a `3xx`, `4xx`, or
`5xx` response, or a timeout, fails the attempt, which is retried like an email.

An invalid channel entry is skipped and recorded as a
`channels_config_invalid_entry` event. When `notification_rules.json` names a
channel that is not configured, for example after a typo or after disabling it,
the evaluators skip that channel's deliveries, deliver the other channels as
usual, and record a `notification_rules_unknown_channel` warning.

### Telegram

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy its token.
2. Send the bot a message, or add it to a group, then read the `chat.id` from
   `https://api.telegram.org/bot<token>/getUpdates`. Group IDs are negative.
3. Set `WEBHOOK_TELEGRAM_URL=https://api.telegram.org/bot<token>/sendMessage` in
   `config/config.env` and add the `telegram` channel to `config/channels.json`.
4. Add `telegram` to the channels in `notification_rules.json`.

Messages are plain text: severity, target, alarm type, message, environment,
alarm ID, time, and the dashboard link from `MAIL_DASHBOARD_URL`.

### Testing a channel

Send a sample alarm through any configured channel, including `email`, without
using the queue:

```bash
sudo -u <php-fpm-user> php scripts/alarm_channel_test.php telegram
# Docker
docker exec -u www-data mata-dashboard-web php scripts/alarm_channel_test.php telegram
```

Run it as the user your crons run as, so its log files stay writable for them.

It exits `0` when sent, `1` when delivery fails, printing the redacted error,
and `2` for an unknown channel, listing the configured ones.

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
