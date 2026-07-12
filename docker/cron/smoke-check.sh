#!/bin/sh
set -eu

# Run from inside the web container after mata-cron-setup. This validates the
# same restricted environment and user used by the installed crontab.
su -s /bin/sh www-data -c '. /etc/mata/cron.env && cd /var/www/html && /usr/local/bin/php scripts/cron_database_probe.php'
