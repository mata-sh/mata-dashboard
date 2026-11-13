# Alarm decision and delivery flow

MATA delivers alarms by email and through outgoing [webhook channels](#channels),
such as Telegram. Extra email channels send selected alarms to other addresses. Each channel is delivered, retried, and rate-limited independently.

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
allows critical events, `fatal_errors_occurring`, `advisories.matched`
and the advisory poller warnings on the channels their notification rule
selects, and denies other candidates. See [Security advisories](../guides/security-advisories.md).

Disabled threshold entries still allow event recording but do not create alarms
through this pipeline.

## Local policy overrides

`policy/alarms.rego` ships with the dashboard and updates with it. To change a
decision, write your own policy in `config/policy/`, which you
[version with the rest of your configuration](../guides/version-config.md).
OPA loads that directory next to `policy/`, and `OPA_ALARM_DECISION_PATH`
selects which decision the dashboard asks for.

!!! warning "Use a package under `local`"
    OPA merges both directories into one namespace. Declare a package under
    `local`, such as `local.alarms`. A copy of `alarms.rego` that keeps
    `package alarms` stops OPA from loading any policy, so `config validate`
    reports it as an error.

Build on the shipped policy instead of copying it. `import data.alarms` gives
access to its rules and helpers, and `else := shipped.decision` keeps every
other event as shipped, including changes that arrive with an upgrade:

```rego
# config/policy/alarms.rego
package local.alarms

import rego.v1
import data.alarms as shipped

# Fatal errors on staging alarm too; everything else as shipped.
decision := {
    "allow": true,
    "channels": shipped.configured_channels(),
    "priority": 20,
    "enrich": false,
    "reason": "fatal_errors_staging",
} if {
    input.event.type == "fatal_errors_occurring"
    input.application.env == "staging"
} else := shipped.decision
```

Then point the dashboard at it in `config/config.env`:

```env
OPA_ALARM_DECISION_PATH=/v1/data/local/alarms/decision
```

The comment at the top of `policy/alarms.rego` documents the input and the
result fields. Remove the variable to go back to the shipped decision.

Keep tests next to the policy, in files ending in `_test.rego`:

```rego
# config/policy/alarms_test.rego
package local.alarms_test

import rego.v1
import data.local.alarms

test_staging_fatal_alarms if {
    alarms.decision.reason == "fatal_errors_staging" with input as {
        "event": {"type": "fatal_errors_occurring", "level": "error"},
        "application": {"env": "staging"},
        "channels": ["email"],
    }
}
```

After each change:

| Command | Checks |
| --- | --- |
| `make opa-test` | Local and shipped policies compile together, and all tests pass |
| `make mata ARGS="config validate"` | Package names; a local policy the decision path does not query, or a decision path without one |
| `make mata ARGS="doctor"` | OPA answers the configured decision path |

!!! warning "OPA keeps the last policy that compiled"
    OPA reloads both directories when a file changes. It ignores a file that
    does not compile, logs that only at info level, and keeps serving the
    previous version, so `doctor` still passes. Run `make opa-test` before you
    rely on a change. When OPA starts with a broken file, it loads no policy at
    all.

Local policies take effect only with `ALARM_DECISION_DRIVER=opa`; the built-in
driver ignores them. Docker mounts `config/policy/` into the OPA container. A
native systemd OPA loads it once `make opa-install-service` has written the
unit.

## Direct alarms

Configuration and bootstrap failures use an emergency path. Direct alarms
bypass the alarm threshold gate, notification rules, the decision driver, and
`intervals.json` delivery deduplication. They are always delivered by the built-in
`email` channel, never through channels from `channels.json`. When persistence is available, they use the same queue
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
120 per hour, and 500 per day. Limits for a channel that is not configured are
never applied; the dispatcher records a `limits_unknown_channel` warning.

## Channels

`email` is built in and configured in `config/mail.config.env`, with recipients
in `MAIL_ALARM_RECIPIENTS`. Every other channel is an entry in the optional
`config/channels.json`, named by its key. Route alarms to a channel by listing its
name in `notification_rules.json`. Deduplication, rate limits, and retries are kept
per channel. A channel that keeps failing does not affect the others.

An invalid channel entry is skipped and recorded as a
`channels_config_invalid_entry` event. When `notification_rules.json` names a
channel that is not configured, for example after a typo or after disabling it,
the evaluators skip that channel's deliveries, deliver the other channels as
usual, and record a `notification_rules_unknown_channel` warning.

### Email channels

An `email` channel sends alarms to its own `recipients` through the SMTP settings
in `config/mail.config.env`. For example, send new security advisories to the
security team and keep every other alarm on `MAIL_ALARM_RECIPIENTS`:

```json
{
  "channels": {
    "security-email": {
      "type": "email",
      "recipients": ["security@example.com"]
    }
  }
}
```

```json
{
  "event_types": {
    "advisories.matched": {"channels": ["security-email"]}
  }
}
```

The second file is `config/notification_rules.json`. To also keep the built-in
copy, list `["email", "security-email"]`.

| Key | Meaning |
| --- | --- |
| `type` | `email`. |
| `enabled` | Optional, default `true`. A disabled channel is ignored. |
| `recipients` | Required list of one or more email addresses. |

The name `email` is reserved for the built-in channel.

### Webhook channels

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
| `type` | `webhook`. |
| `enabled` | Optional, default `true`. A disabled channel is ignored. |
| `url_env` | Environment variable in `config/config.env` holding the URL. |
| `template` | Payload template in `templates/webhooks/`: `telegram`, `slack`, `discord`, or `generic`. |
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

#### Telegram

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy its token.
2. Send the bot a message, or add it to a group, then read the `chat.id` from
   `https://api.telegram.org/bot<token>/getUpdates`. Group IDs are negative.
3. Set `WEBHOOK_TELEGRAM_URL=https://api.telegram.org/bot<token>/sendMessage` in
   `config/config.env` and add the `telegram` channel to `config/channels.json`.
4. Add `telegram` to the channels in `notification_rules.json`.

Messages are plain text: severity, target, alarm type, message, environment,
alarm ID, time, and the dashboard link from `MAIL_DASHBOARD_URL`.

#### Slack and Discord

1. Create an incoming webhook: in Slack, add the *Incoming Webhooks* app to a
   channel; in Discord, open the channel settings, then *Integrations* →
   *Webhooks*.
2. Set `WEBHOOK_SLACK_URL` or `WEBHOOK_DISCORD_URL` in `config/config.env` to
   the webhook URL.
3. Add a channel with `"template": "slack"` or `"template": "discord"` to
   `config/channels.json`, and list its name in `notification_rules.json`.

Both send the same plain text as Telegram. Mentions such as `@channel` or
`@everyone` in alarm messages do not notify anyone. Discord messages are cut to
its 2000-character limit.

### Testing a channel

Send a sample alarm through any configured channel, including `email`, without
using the queue:

```bash
make mata ARGS="alarm test telegram"
```

Without a channel it lists the configured ones. It exits `0` when sent and `2`
when delivery fails, printing the redacted error, or the channel is unknown.

### Explaining decisions

The evaluation cron stores a decision trace on every event it evaluates.
`bin/mata alarm explain` shows the latest ones, so you can check a change to
`thresholds.json` or `notification_rules.json` after the next evaluation run:

```bash
php bin/mata alarm explain prod-web-01 --limit 10
```

Each event shows the outcome, the policy gate and reason, the threshold gate,
the trigger evidence (for example `3 events within 30 minutes`), and the ready
and unavailable channels. `--json` returns the full stored trace.

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
`retry` until they exhaust their attempts, then become `failed`.
