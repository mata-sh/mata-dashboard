#!/bin/sh

set -u

ACTION=${1:-help}
ENVIRONMENT=${2:-}
USERS_DIRECTORY=/var/lib/mata-dashboard
USERS_FILE="$USERS_DIRECTORY/users.json"
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

# Root provisions the private named volume; PHP performs all user-file writes.
install -d -o www-data -g www-data -m 0700 "$USERS_DIRECTORY"
mkdir -p /var/www/html/logs

touch "$LOCK_FILE" "$AUDIT_FILE"
if [ -e "$USERS_FILE" ]; then
    chown www-data:www-data "$USERS_FILE"
    chmod 0600 "$USERS_FILE"
fi
chown www-data:www-data "$LOCK_FILE" "$AUDIT_FILE"
chmod 0600 "$LOCK_FILE"
chmod 0640 "$AUDIT_FILE"

runuser -u www-data -- env APP_ENV="$ENVIRONMENT" MATA_USERS_FILE="$USERS_FILE" \
    php /var/www/html/scripts/user-management/manage-users.php "$ACTION" "--env=$ENVIRONMENT"
