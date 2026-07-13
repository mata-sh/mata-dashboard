#!/bin/sh

set -u

ACTION=${1:-help}
ENVIRONMENT=${2:-}
CONFIG_DIR="/var/www/html/config/$ENVIRONMENT"
USERS_FILE="$CONFIG_DIR/users.json"
LOCK_FILE="$USERS_FILE.lock"
AUDIT_FILE=/var/www/html/logs/user-management-audit.log

case "$ACTION" in
    list|create|unlock|delete|passwd) ;;
    *)
        echo "ERROR: Unsupported user-management action: $ACTION" >&2
        exit 2
        ;;
esac

case "$ENVIRONMENT" in
    dev|demo|prod) ;;
    *)
        echo "ERROR: Environment must be dev, demo, or prod." >&2
        exit 2
        ;;
esac

# Root prepares only the exact writable paths, then PHP runs as www-data.
mkdir -p "$CONFIG_DIR" /var/www/html/logs
ORIGINAL_CONFIG_OWNER=$(stat -c '%u:%g' "$CONFIG_DIR")
ORIGINAL_CONFIG_MODE=$(stat -c '%a' "$CONFIG_DIR")

restore_config_dir() {
    chown "$ORIGINAL_CONFIG_OWNER" "$CONFIG_DIR" || true
    chmod "$ORIGINAL_CONFIG_MODE" "$CONFIG_DIR" || true
}
trap 'restore_config_dir' EXIT

chown www-data:www-data "$CONFIG_DIR" /var/www/html/logs
chmod 0750 "$CONFIG_DIR"
chmod 0775 /var/www/html/logs

if [ ! -e "$USERS_FILE" ]; then
    printf '[]' > "$USERS_FILE"
fi
: > "$LOCK_FILE"
touch "$AUDIT_FILE"
chown www-data:www-data "$USERS_FILE" "$LOCK_FILE" "$AUDIT_FILE"
chmod 0600 "$USERS_FILE" "$LOCK_FILE"
chmod 0640 "$AUDIT_FILE"

runuser -u www-data -- env APP_ENV="$ENVIRONMENT" \
    php /var/www/html/scripts/user-management/manage-users.php "$ACTION" "--env=$ENVIRONMENT"
status=$?

# Keep later admin runs and the read-only production web process compatible.
for file in "$USERS_FILE" "$LOCK_FILE"; do
    if [ -e "$file" ]; then
        chown www-data:www-data "$file"
        chmod 0600 "$file"
    fi
done
if [ -e "$AUDIT_FILE" ]; then
    chown www-data:www-data "$AUDIT_FILE"
    chmod 0640 "$AUDIT_FILE"
fi
exit "$status"
