package config.intervals

import rego.v1

is_intervals_config if {
    input.events.min_event_interval_minutes
}

is_intervals_config if {
    input.alarms.min_alarm_interval_minutes
}

min_event_intervals := object.get(input.events, "min_event_interval_minutes", {})
min_alarm_intervals := object.get(input.alarms, "min_alarm_interval_minutes", {})

deny contains msg if {
    is_intervals_config
    is_object(min_event_intervals)
    not min_event_intervals._default
    msg := "intervals.json.example: events.min_event_interval_minutes must include _default"
}

deny contains msg if {
    is_intervals_config
    is_object(min_alarm_intervals)
    not min_alarm_intervals._default
    msg := "intervals.json.example: alarms.min_alarm_interval_minutes must include _default"
}

deny contains msg if {
    is_intervals_config
    is_object(min_event_intervals)
    key := object.keys(min_event_intervals)[_]
    value := min_event_intervals[key]
    not is_non_negative_int(value)
    msg := sprintf("intervals.json.example: events.min_event_interval_minutes.%s must be a non-negative integer", [key])
}

deny contains msg if {
    is_intervals_config
    is_object(min_alarm_intervals)
    key := object.keys(min_alarm_intervals)[_]
    value := min_alarm_intervals[key]
    not is_non_negative_int(value)
    msg := sprintf("intervals.json.example: alarms.min_alarm_interval_minutes.%s must be a non-negative integer", [key])
}

deny contains msg if {
    is_intervals_config
    is_object(min_event_intervals)
    is_object(min_alarm_intervals)
    key := object.keys(min_event_intervals)[_]
    key != "_default"
    min_event_intervals[key]
    min_alarm_intervals[key]
    is_non_negative_int(min_event_intervals[key])
    is_non_negative_int(min_alarm_intervals[key])
    min_alarm_intervals[key] < min_event_intervals[key]
    msg := sprintf("intervals.json.example: alarms.min_alarm_interval_minutes.%s must be >= events.min_event_interval_minutes.%s", [key, key])
}

is_non_negative_int(value) if {
    is_number(value)
    value >= 0
    value == floor(value)
}
