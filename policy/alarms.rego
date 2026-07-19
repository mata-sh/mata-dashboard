package alarms

import rego.v1

# Priorities use the dispatch scale: lower dispatches first (10 critical, 30 error/security, 60 warning, 100 info), matching PHP AlarmDirectProcessor::resolvePriority.
default decision := {
    "allow": false,
    "channels": [],
    "priority": 100,
    "enrich": false,
    "reason": "no_rule_match",
}

decision := fatal_prod_app_error if fatal_prod_app_error
else := monitored_critical if monitored_critical
else := monitored_warning if monitored_warning

monitored_types := {
    "app_unreachable",
    "cronjob_heartbeat_missing",
    "cpu_usage_too_high",
    "ram_usage_too_high",
    "filecount_too_large",
    "logfile_too_large",
    "too_many_sql_connections",
    "servers_config_not_found",
    "remaining_diskspace_too_low",
    "node_unreachable",
    "node_unhealthy",
    "server_metrics.ingestion",
    "server_metrics.ingestion_stall",
}

fatal_prod_app_error := result if {
    input.event.type == "fatal_errors_occurring"
    app_env() == "prod"
    result := {
        "allow": true,
        "channels": configured_channels(),
        "priority": 10,
        "enrich": false,
        "reason": "fatal_errors_prod",
    }
}

monitored_critical := result if {
    input.event.type in monitored_types
    input.event.level == "critical"
    result := {
        "allow": true,
        "channels": ["email"],
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
        "channels": ["email"],
        "priority": 60,
        "enrich": false,
        "reason": "monitored_warning",
    }
}

configured_channels() := channels if {
    channels := input.config.channels
} else := channels if {
    channels := input.channels
} else := ["email"]

app_env() := env if {
    env := input.application.env
} else := env if {
    env := input.environment
} else := "unknown"
