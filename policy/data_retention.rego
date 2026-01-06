package config.data_retention

import rego.v1

is_data_retention_config if {
    input.raw_retention_days
}

is_data_retention_config if {
    input.aggregation
}

deny contains msg if {
    is_data_retention_config
    value := input.raw_retention_days
    not is_number(value)
    msg := "data_retention.json.example: raw_retention_days must be a positive number"
}

deny contains msg if {
    is_data_retention_config
    value := input.raw_retention_days
    is_number(value)
    value <= 0
    msg := "data_retention.json.example: raw_retention_days must be a positive number"
}

deny contains msg if {
    is_data_retention_config
    is_object(input.aggregation)
    bucket := object.keys(input.aggregation)[_]
    lag := object.get(input.aggregation[bucket], "lag_minutes", null)
    not is_number(lag)
    msg := sprintf("data_retention.json.example: aggregation.%s.lag_minutes must be a positive number", [bucket])
}

deny contains msg if {
    is_data_retention_config
    is_object(input.aggregation)
    bucket := object.keys(input.aggregation)[_]
    lag := object.get(input.aggregation[bucket], "lag_minutes", null)
    is_number(lag)
    lag <= 0
    msg := sprintf("data_retention.json.example: aggregation.%s.lag_minutes must be a positive number", [bucket])
}

deny contains msg if {
    is_data_retention_config
    limit := object.get(input.pruning, "batch_limit", null)
    not is_number(limit)
    msg := "data_retention.json.example: pruning.batch_limit must be a positive number"
}

deny contains msg if {
    is_data_retention_config
    limit := object.get(input.pruning, "batch_limit", null)
    is_number(limit)
    limit <= 0
    msg := "data_retention.json.example: pruning.batch_limit must be a positive number"
}
