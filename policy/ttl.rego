package config.ttl

import rego.v1

# Mirrors Mata\Constants\CacheType::ttlConfigKeys(). The enum is the source of truth;
# TtlConfigKeysAreAlignedTest fails if this list drifts from it. Historically this list
# was copied from the example config rather than from code, which is how errorlog
# outlived its consumer and health never appeared at all.
allowed_keys := {
    "serverData",
    "serverInfo",
    "serverProcesses",
    "serverMetricsChart",
    "apps",
    "libraries",
    "appLogs",
    "sessions",
    "ping",
    "health",
    "readme",
    "databases",
    "cronjobs",
    "audit",
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
    not is_valid_ttl(value)
    msg := sprintf("ttl.json.example: %s must be a positive integer or -1 (disabled)", [key])
}

is_valid_ttl(value) if {
    is_number(value)
    value > 0
    value == floor(value)
}

is_valid_ttl(value) if value == -1
