package config.thresholds

import rego.v1

threshold_keys := ["good", "warn", "critical", "alarm"]

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
