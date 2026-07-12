package config.data_retention

import rego.v1

retention_fields := [
    ["metrics", "raw_retention_days"],
    ["metrics", "hourly_retention_days"],
    ["metrics", "daily_retention_days"],
    ["events", "retention_days"],
    ["alarms", "retention_days"],
]

aggregation_fields := [
    ["aggregation", "hourly", "lag_minutes"],
    ["aggregation", "hourly", "catch_up_hours"],
    ["aggregation", "daily", "lag_minutes"],
]

data_retention_paths := [
    ["metrics", "raw_retention_days"],
    ["metrics", "hourly_retention_days"],
    ["metrics", "daily_retention_days"],
    ["events", "retention_days"],
    ["alarms", "retention_days"],
    ["aggregation", "hourly", "lag_minutes"],
    ["aggregation", "hourly", "catch_up_hours"],
    ["aggregation", "daily", "lag_minutes"],
    ["pruning", "batch_limit"],
]

is_data_retention_config if {
    is_object(input)
    path := data_retention_paths[_]
    object.get(input, path, {"_missing": true}) != {"_missing": true}
}

deny contains msg if {
    is_data_retention_config
    field := retention_fields[_]
    value := object.get(input, field, null)
    not is_number(value)
    msg := sprintf("data_retention.json.example: %s must be a non-negative number", [concat(".", field)])
}

deny contains msg if {
    is_data_retention_config
    field := retention_fields[_]
    value := object.get(input, field, null)
    is_number(value)
    value < 0
    msg := sprintf("data_retention.json.example: %s must be a non-negative number", [concat(".", field)])
}

deny contains msg if {
    is_data_retention_config
    field := aggregation_fields[_]
    value := object.get(input, field, null)
    not is_number(value)
    msg := sprintf("data_retention.json.example: %s must be a positive number", [concat(".", field)])
}

deny contains msg if {
    is_data_retention_config
    field := aggregation_fields[_]
    value := object.get(input, field, null)
    is_number(value)
    value <= 0
    msg := sprintf("data_retention.json.example: %s must be a positive number", [concat(".", field)])
}

deny contains msg if {
    is_data_retention_config
    value := object.get(input, ["pruning", "batch_limit"], null)
    not is_number(value)
    msg := "data_retention.json.example: pruning.batch_limit must be a positive number"
}

deny contains msg if {
    is_data_retention_config
    value := object.get(input, ["pruning", "batch_limit"], null)
    is_number(value)
    value <= 0
    msg := "data_retention.json.example: pruning.batch_limit must be a positive number"
}
