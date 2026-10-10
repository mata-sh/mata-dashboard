package alarms

import rego.v1

# The full pipeline is (see docs/reference/alarms.md):
#
#   thresholds.json -> notification_rules.json -> OPA (this file) -> intervals.json -> queue -> limits.json -> email
#
# PHP owns everything stateful and evaluates it BEFORE and AFTER this policy:
#   - thresholds.json: "did enough events happen?" (alarm_threshold_event_count
#     within alarm_threshold_window_minutes).
#   - intervals.json: per-channel delivery deduplication (cooldowns) and event
#     recording intervals, applied to the channels this policy selects.
#   - limits.json: transport rate limits at dispatch time.
#
# This policy is deliberately STATELESS. Input is one event that passed the
# threshold gate. notification_rules.json constrains input.channels, but never
# suppresses logical alarm creation: an empty list means dashboard-only.
# Input: {event: {type, level, message, context, datetime}, server: {id, name},
# application: {id, code, name, env}, channels: [...], notification_rule:
# {enabled, channels, suppression_reason}}. It decides whether to
# create a logical alarm,
# which deliveries to select, and dispatch priority — pure classification by
# type/level/env.
# It sees no counts, no cooldown state, and no delivery history.
#
# Result fields:
#   allow    - false => no logical alarm is created for this event (event is
#              still marked evaluated with the reason persisted).
#   channels - selected only from input.channels, which PHP has already
#              constrained to configured and runtime-supported channels. An
#              allowed empty list creates a dashboard-only logical alarm.
#   priority - dispatch order, ASC: lower dispatches first. Scale matches PHP
#              AlarmDirectProcessor::resolvePriority (10 critical, 30 error/
#              security, 60 warning, 100 info).
#   enrich   - reserved flag for a future enrichment step (e.g. LLM summary of
#              the event before sending). PHP currently only logs/counts it;
#              nothing consumes it yet.
#   reason   - free-form slug for observability. It is not matched against any
#              application enum: PHP writes it verbatim into the alarm_decision
#              log line and the events.alarm_decision JSON column. Invent new
#              reasons here as needed.

default decision := {
    "allow": false,
    "channels": [],
    "priority": 100,
    "enrich": false,
    "reason": "no_rule_match",
}

# First matching rule wins
decision := fatal_prod_app_error if fatal_prod_app_error
else := monitored_critical if monitored_critical
else := monitored_warning if monitored_warning

# Environments that explicitly suppress fatal-php-error alarms.
non_prod_envs := {"dev", "development", "staging", "test", "testing", "demo", "local"}

# Fatal application errors alarm unless the env is explicitly non-prod.
# (warning lvl sufficent)
fatal_prod_app_error := result if {
    input.event.type == "fatal_errors_occurring"
    not lower(app_env()) in non_prod_envs
    result := {
        "allow": true,
        "channels": configured_channels(),
        "priority": 10,
        "enrich": false,
        "reason": "fatal_errors_prod",
    }
}

# PHP supplies the channels allowed by notification rules and runtime support.
configured_channels() := object.get(input, "channels", [])

# Application environment with fallback to unknown
# input.application.env is the event source environment
# input.environment is an alternate input shape for external callers.
app_env() := env if {
    env := input.application.env
} else := env if {
    env := input.environment
} else := "unknown"

# Alarm-eligible warning/critical event types produced by dashboard.
# Informational-only producers stay outside this set.
monitored_types := {
    "app_unreachable",
    "cronjobs_failing",
    "cpu_usage_too_high",
    "ram_usage_too_high",
    "filecount_too_large",
    "logfile_too_large",
    "database_connections_too_high",
    "database_unreachable",
    "remaining_diskspace_too_low",
    "node_unreachable",
    "node_unhealthy",
    "server_metrics.ingestion_run_empty",
    "server_metrics.ingestion_stalled",
    "users_file_insecure",
    "user_account_locked",
    "user_account_changed",
}

monitored_critical := result if {
    input.event.type in monitored_types
    input.event.level == "critical"
    result := {
        "allow": true,
        "channels": configured_channels(),
        "priority": 10,
        "enrich": false,
        "reason": "monitored_critical",
    }
}

monitored_warning := result if {
    input.event.type in monitored_types
    input.event.level == "warning"
    result := {
        "allow": true,
        "channels": configured_channels(),
        "priority": 60,
        "enrich": false,
        "reason": "monitored_warning",
    }
}

