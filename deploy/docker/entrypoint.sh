#!/bin/sh
# Creates or upgrades the Azure SQL schema, then runs Apache in the foreground.
set -eu
if [ -n "${DB_SERVER:-}" ]; then
    php /var/www/app/scripts/azure-migrate.php
else
    echo "DB_SERVER is not set; skipping database migration." >&2
fi
exec apache2-foreground
