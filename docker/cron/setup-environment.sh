#!/bin/sh
set -eu

cron_env=/etc/mata/cron.env
required='DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD'

install -d -m 0750 -o root -g www-data /etc/mata
umask 027
: > "$cron_env"

for key in $required; do
    value=$(printenv "$key" || true)
    if [ -z "$value" ]; then
        echo "Missing required cron database setting: $key" >&2
        exit 1
    fi
    escaped=$(printf '%s' "$value" | sed "s/'/'\\\\''/g")
    printf "export %s='%s'\n" "$key" "$escaped" >> "$cron_env"
done

chown root:www-data "$cron_env"
chmod 0640 "$cron_env"
