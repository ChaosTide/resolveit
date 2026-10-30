#!/bin/sh
# Prepares the database, then runs Apache in the foreground.
#   APP_ENV=docker: local Docker Compose stack; creates the database, website login, tables, and admin.
#   APP_ENV=azure:  Azure App Service; creates or upgrades the Azure SQL schema.
set -eu
if [ "${APP_ENV:-}" = "docker" ]; then
    php /var/www/app/scripts/docker-init.php
elif [ -n "${DB_SERVER:-}" ]; then
    php /var/www/app/scripts/azure-migrate.php
else
    echo "DB_SERVER is not set; skipping database migration." >&2
fi
exec apache2-foreground
