package config.servers

import rego.v1

required_fields := ["api_url", "title"]

is_servers_config if {
    input._README
}

is_servers_config if {
    input._CONFIGURATION_NOTES
}

is_metadata_key(key) if {
    startswith(key, "_")
}

server_entries[server_name] := server_config if {
    is_servers_config
    input[server_name] = server_config
    not is_metadata_key(server_name)
    is_object(server_config)
}

deny contains msg if {
    server_entries[server_name] = server_config
    field := required_fields[_]
    value := object.get(server_config, field, "")
    not is_valid_string(value)
    msg := sprintf("servers.json.example: server %q missing required field: %s", [server_name, field])
}

deny contains msg if {
    server_entries[server_name] = server_config
    object.get(server_config, "api_key", null) != null
    msg := sprintf("servers.json.example: server %q must not set api_key; node keys belong in servers.secrets.json", [server_name])
}

is_valid_string(value) if {
    is_string(value)
    value != ""
}

deny contains msg if {
    server_entries[server_name] = server_config
    api_url := server_config.api_url
    not is_string(api_url)
    msg := sprintf("servers.json.example: server %q api_url must be a string URL", [server_name])
}

deny contains msg if {
    server_entries[server_name] = server_config
    api_url := server_config.api_url
    is_string(api_url)
    not valid_api_url(api_url)
    msg := sprintf("servers.json.example: server %q api_url must be a valid http(s) URL", [server_name])
}

deny contains msg if {
    server_entries[server_name] = server_config
    api_url := server_config.api_url
    is_string(api_url)
    startswith(lower(api_url), "http://")
    not allow_http(server_config)
    msg := sprintf("servers.json.example: server %q api_url must use https or set allow_http", [server_name])
}

deny contains msg if {
    server_entries[server_name] = server_config
    primary := server_config.primary_color
    is_string(primary)
    not regex.match("^#[0-9a-fA-F]{6}$", primary)
    msg := sprintf("servers.json.example: server %q primary_color must be hex #RRGGBB", [server_name])
}

valid_api_url(url) if {
    regex.match("^https?://[^\\s/]+(/.*)?$", url)
}

allow_http(config) if {
    value := config.allow_http
    is_boolean(value)
    value
}

allow_http(config) if {
    value := config.allow_http
    is_string(value)
    lower(value) == "on"
}

allow_http(config) if {
    value := config.allow_http
    is_string(value)
    lower(value) == "true"
}

allow_http(config) if {
    value := config.allow_http
    is_string(value)
    lower(value) == "yes"
}

allow_http(config) if {
    value := config.allow_http
    is_string(value)
    lower(value) == "1"
}
