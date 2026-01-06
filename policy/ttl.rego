package config.ttl

import rego.v1

allowed_keys := {
    "ping",
    "servers",
    "apps",
    "app",
    "serverData",
    "databases",
    "libraries",
    "appLogs",
    "serverProcesses",
    "readme",
    "errorlog",
    "serverMetricsChart",
}

is_ttl_config if {
    is_object(input)
    every _, v in input {
        not is_object(v)
        not is_array(v)
    }
}

deny contains msg if {
    is_ttl_config
    key := object.keys(input)[_]
    not allowed_keys[key]
    msg := sprintf("ttl.json.example: key %q is not allowed", [key])
}

deny contains msg if {
    is_ttl_config
    key := object.keys(input)[_]
    value := input[key]
    not is_positive_int(value)
    msg := sprintf("ttl.json.example: %s must be a positive integer", [key])
}

is_positive_int(value) if {
    is_number(value)
    value > 0
    value == floor(value)
}
