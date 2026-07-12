package config.data_retention_test

import rego.v1
import data.config.data_retention

example_config := {
    "metrics": {
        "raw_retention_days": 30,
        "hourly_retention_days": 90,
        "daily_retention_days": 365,
    },
    "events": {"retention_days": 180},
    "alarms": {"retention_days": 365},
    "aggregation": {
        "hourly": {"lag_minutes": 5, "catch_up_hours": 24},
        "daily": {"lag_minutes": 30},
    },
    "pruning": {"batch_limit": 5000},
}

negative_retention_messages := {
    "data_retention.json.example: metrics.raw_retention_days must be a non-negative number",
    "data_retention.json.example: metrics.hourly_retention_days must be a non-negative number",
    "data_retention.json.example: metrics.daily_retention_days must be a non-negative number",
    "data_retention.json.example: events.retention_days must be a non-negative number",
    "data_retention.json.example: alarms.retention_days must be a non-negative number",
}

non_numeric_retention_messages := {
    "data_retention.json.example: metrics.raw_retention_days must be a non-negative number",
    "data_retention.json.example: metrics.hourly_retention_days must be a non-negative number",
    "data_retention.json.example: metrics.daily_retention_days must be a non-negative number",
    "data_retention.json.example: events.retention_days must be a non-negative number",
    "data_retention.json.example: alarms.retention_days must be a non-negative number",
}

test_checked_in_example_passes if {
    denies := data_retention.deny with input as example_config
    count(denies) == 0
}

test_zero_retention_disables_cleanup if {
    config := object.union(example_config, {
        "metrics": {
            "raw_retention_days": 0,
            "hourly_retention_days": 0,
            "daily_retention_days": 0,
        },
        "events": {"retention_days": 0},
        "alarms": {"retention_days": 0},
    })
    denies := data_retention.deny with input as config
    count(denies) == 0
}

test_negative_retention_values_fail_for_every_category if {
    config := object.union(example_config, {
        "metrics": {
            "raw_retention_days": -1,
            "hourly_retention_days": -1,
            "daily_retention_days": -1,
        },
        "events": {"retention_days": -1},
        "alarms": {"retention_days": -1},
    })
    denies := data_retention.deny with input as config
    denies == negative_retention_messages
}

test_non_numeric_retention_values_fail_for_every_category if {
    config := object.union(example_config, {
        "metrics": {
            "raw_retention_days": "invalid",
            "hourly_retention_days": "invalid",
            "daily_retention_days": "invalid",
        },
        "events": {"retention_days": "invalid"},
        "alarms": {"retention_days": "invalid"},
    })
    denies := data_retention.deny with input as config
    denies == non_numeric_retention_messages
}

test_invalid_aggregation_values_fail if {
    config := object.union(example_config, {
        "aggregation": {
            "hourly": {"lag_minutes": 0, "catch_up_hours": "invalid"},
            "daily": {"lag_minutes": -1},
        },
    })
    denies := data_retention.deny with input as config
    denies == {
        "data_retention.json.example: aggregation.hourly.lag_minutes must be a positive number",
        "data_retention.json.example: aggregation.hourly.catch_up_hours must be a positive number",
        "data_retention.json.example: aggregation.daily.lag_minutes must be a positive number",
    }
}

test_invalid_batch_limit_fails if {
    config := object.union(example_config, {"pruning": {"batch_limit": 0}})
    denies := data_retention.deny with input as config
    denies == {"data_retention.json.example: pruning.batch_limit must be a positive number"}
}

test_non_numeric_batch_limit_fails if {
    config := object.union(example_config, {"pruning": {"batch_limit": "invalid"}})
    denies := data_retention.deny with input as config
    denies == {"data_retention.json.example: pruning.batch_limit must be a positive number"}
}

test_unrelated_config_does_not_trigger_retention_rules if {
    denies := data_retention.deny with input as {
        "events": {"min_event_interval_minutes": {"_default": 60}},
        "alarms": {"min_alarm_interval_minutes": {"_default": 1440}},
    }
    count(denies) == 0
}
