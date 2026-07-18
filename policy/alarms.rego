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
else := disk_usage_critical if disk_usage_critical
else := library_outdated_security if library_outdated_security
else := cpu_usage_over_80 if cpu_usage_over_80
else := disk_usage_warning if disk_usage_warning
else := monitored_warning if monitored_warning

monitored_types := {
    "app_unreachable",
    "orders_export_stale",
    "cronjob_heartbeat_missing",
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

# Aspirational rules: no producer emits these event types yet.
disk_usage_critical := result if {
    input.event.type == "disk_usage"
    input.event.threshold >= 0.8
    input.event.level == "critical"
    result := {
        "allow": true,
        "channels": ["email", "messenger"],
        "priority": 10,
        "enrich": false,
        "reason": "disk_over_80_critical",
    }
}

disk_usage_warning := result if {
    input.event.type == "disk_usage"
    input.event.threshold >= 0.8
    input.event.level == "warning"
    result := {
        "allow": true,
        "channels": ["email"],
        "priority": 60,
        "enrich": false,
        "reason": "disk_over_80_warning",
    }
}

cpu_usage_over_80 := result if {
    input.event.type == "cpu_usage"
    input.event.threshold >= 0.8
    result := {
        "allow": true,
        "channels": ["email"],
        "priority": 60,
        "enrich": false,
        "reason": "cpu_over_80",
    }
}

library_outdated_security := result if {
    input.event.type == "library_outdated_security"
    result := {
        "allow": true,
        "channels": ["email"],
        "priority": 30,
        "enrich": false,
        "reason": "library_outdated_security",
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
