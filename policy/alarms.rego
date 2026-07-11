package alarms

import rego.v1

default decision := {
    "allow": false,
    "channels": [],
    "priority": 100,
    "enrich": false,
    "reason": "no_rule_match",
}

decision := fatal_prod_app_error if fatal_prod_app_error
else := disk_usage_critical if disk_usage_critical
else := library_outdated_security if library_outdated_security
else := cpu_usage_over_80_4h if cpu_usage_over_80_4h
else := disk_usage_warning if disk_usage_warning

fatal_prod_app_error := result if {
    input.event.type == "app_fatal_error"
    app_env() == "prod"
    event_count("1h") > 0
    result := {
        "allow": true,
        "channels": configured_channels(),
        "priority": 90,
        "enrich": false,
        "reason": "fatal_errors_prod",
    }
}

disk_usage_critical := result if {
    input.event.type == "disk_usage"
    input.event.threshold >= 0.8
    input.event.level == "critical"
    result := {
        "allow": true,
        "channels": ["email", "messenger"],
        "priority": 90,
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
        "priority": 30,
        "enrich": false,
        "reason": "disk_over_80_warning",
    }
}

cpu_usage_over_80_4h := result if {
    input.event.type == "cpu_usage"
    input.event.threshold >= 0.8
    event_count("4h") > 3
    result := {
        "allow": true,
        "channels": ["email"],
        "priority": 40,
        "enrich": false,
        "reason": "cpu_over_80_4h",
    }
}

library_outdated_security := result if {
    input.event.type == "library_outdated_security"
    result := {
        "allow": true,
        "channels": ["email"],
        "priority": 75,
        "enrich": false,
        "reason": "library_outdated_security",
    }
}

event_count(span) := count if {
    counts := input.event.counts
    count := counts[span]
} else := 0

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
