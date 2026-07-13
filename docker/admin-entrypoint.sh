#!/bin/sh

set -u

ACTION=${1:-help}
ENVIRONMENT=${2:-}

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

mkdir -p "/var/www/html/config/$ENVIRONMENT" /var/www/html/logs
php /var/www/html/scripts/user-management/manage-users.php "$ACTION" "--env=$ENVIRONMENT"
status=$?

for file in \
    "/var/www/html/config/$ENVIRONMENT/users.json" \
    "/var/www/html/config/$ENVIRONMENT/users.json.lock"; do
    if [ -e "$file" ]; then
        chown www-data:www-data "$file"
        chmod 0600 "$file"
    fi
done

if [ -e /var/www/html/logs/user-management-audit.log ]; then
    chown www-data:www-data /var/www/html/logs/user-management-audit.log
    chmod 0640 /var/www/html/logs/user-management-audit.log
fi

exit "$status"
