package config.intervals_test

import rego.v1
import data.config.intervals

test_zero_event_interval_passes if {
    denies := intervals.deny with input as {
        "events": {"min_event_interval_minutes": {"_default": 60, "app_unreachable": 0}},
        "alarms": {"min_alarm_interval_minutes": {"_default": 60, "app_unreachable": 60}},
    }
    count(denies) == 0
}

test_negative_event_interval_fails if {
    denies := intervals.deny with input as {
        "events": {"min_event_interval_minutes": {"_default": 60, "app_unreachable": -1}},
        "alarms": {"min_alarm_interval_minutes": {"_default": 60, "app_unreachable": 60}},
    }
    denies == {"intervals.json.example: events.min_event_interval_minutes.app_unreachable must be a non-negative integer"}
}
