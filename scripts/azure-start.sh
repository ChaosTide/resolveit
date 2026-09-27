#!/bin/sh
set -eu
sed -i 's|root /home/site/wwwroot;|root /home/site/wwwroot/public;|g' /etc/nginx/sites-available/default
php /home/site/wwwroot/scripts/azure-migrate.php
service nginx reload
