package config.thresholds_test

import rego.v1
import data.config.thresholds

test_consecutive_failures_rule_passes if {
    denies := thresholds.deny with input as {
        "server_data": {},
        "alarms": {
            "app_unreachable": {
                "strategy": "consecutive_failures",
                "required_count": 2,
                "window_minutes": 10,
            },
        },
    }
    count(denies) == 0
}

test_consecutive_failures_requires_a_positive_count if {
    denies := thresholds.deny with input as {
        "server_data": {},
        "alarms": {
            "app_unreachable": {
                "strategy": "consecutive_failures",
                "required_count": 0,
                "window_minutes": 10,
            },
        },
    }
    denies == {"thresholds.json.example: alarms.app_unreachable required_count must be a positive integer for consecutive_failures"}
}
