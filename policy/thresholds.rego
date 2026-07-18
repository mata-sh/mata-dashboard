package config.thresholds

import rego.v1

threshold_keys := ["good", "warn", "critical", "alarm"]
alarm_keys := ["alarm_threshold_event_count", "alarm_threshold_window_minutes"]

is_thresholds_config if {
    input.server_data
}

is_thresholds_config if {
    input.app_data
}

metric_objects[metric_path] := metric if {
    is_thresholds_config
    section_key := object.keys(input)[_]
    section := input[section_key]
    is_object(section)
    section_key != "alarms"
    metric_key := object.keys(section)[_]
    metric := section[metric_key]
    is_object(metric)
    metric_path := sprintf("%s.%s", [section_key, metric_key])
}

alarm_entries[alarm_path] := entry if {
    is_thresholds_config
    alarms := input.alarms
    is_object(alarms)
    alarm_type := object.keys(alarms)[_]
    entry := alarms[alarm_type]
    alarm_path := sprintf("alarms.%s", [alarm_type])
}

deny contains msg if {
    metric_objects[metric_path] = metric
    key := threshold_keys[_]
    not metric[key]
    msg := sprintf("thresholds.json.example: %s missing %s threshold", [metric_path, key])
}

deny contains msg if {
    metric_objects[metric_path] = metric
    key := threshold_keys[_]
    metric[key]
    not is_number(metric[key])
    msg := sprintf("thresholds.json.example: %s %s must be numeric", [metric_path, key])
}

deny contains msg if {
    metric_objects[metric_path] = metric
    all_numeric(metric)
    not is_monotonic(metric)
    msg := sprintf("thresholds.json.example: %s must be monotonic good < warn < critical < alarm", [metric_path])
}

deny contains msg if {
    alarm_entries[alarm_path] = entry
    not is_object(entry)
    msg := sprintf("thresholds.json.example: %s must be an object", [alarm_path])
}

deny contains msg if {
    alarm_entries[alarm_path] = entry
    is_object(entry)
    key := object.keys(entry)[_]
    not is_alarm_key(key)
    msg := sprintf("thresholds.json.example: %s contains unknown key %s", [alarm_path, key])
}

deny contains msg if {
    alarm_entries[alarm_path] = entry
    is_object(entry)
    has_key(entry, "alarm_threshold_event_count")
    not is_number(entry.alarm_threshold_event_count)
    msg := sprintf("thresholds.json.example: %s alarm_threshold_event_count must be numeric", [alarm_path])
}

deny contains msg if {
    alarm_entries[alarm_path] = entry
    is_object(entry)
    is_number(entry.alarm_threshold_event_count)
    entry.alarm_threshold_event_count < 0
    msg := sprintf("thresholds.json.example: %s alarm_threshold_event_count must be >= 0", [alarm_path])
}

deny contains msg if {
    alarm_entries[alarm_path] = entry
    is_object(entry)
    has_key(entry, "alarm_threshold_window_minutes")
    not is_number(entry.alarm_threshold_window_minutes)
    msg := sprintf("thresholds.json.example: %s alarm_threshold_window_minutes must be numeric", [alarm_path])
}

deny contains msg if {
    alarm_entries[alarm_path] = entry
    is_object(entry)
    is_number(entry.alarm_threshold_window_minutes)
    entry.alarm_threshold_window_minutes <= 0
    msg := sprintf("thresholds.json.example: %s alarm_threshold_window_minutes must be > 0", [alarm_path])
}

all_numeric(metric) if {
    is_number(metric.good)
    is_number(metric.warn)
    is_number(metric.critical)
    is_number(metric.alarm)
}

is_monotonic(metric) if {
    metric.good < metric.warn
    metric.warn < metric.critical
    metric.critical < metric.alarm
}

is_alarm_key(key) if {
    alarm_keys[_] == key
}

has_key(entry, key) if {
    object.keys(entry)[_] == key
}
