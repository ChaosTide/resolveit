# Apache 2.4 + PHP 8.3 image for running the ticket system on Azure App Service.
# Build from the project root:  docker build -f deploy/docker/Dockerfile -t cts4911-tickets .
FROM php:8.3-apache-bookworm

# Microsoft ODBC Driver 18 and the pdo_sqlsrv extension for Azure SQL.
# ACCEPT_EULA=Y accepts the Microsoft ODBC Driver for SQL Server license.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg unixodbc-dev $PHPIZE_DEPS; \
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg; \
    curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list -o /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; \
    ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18; \
    pecl install pdo_sqlsrv; \
    docker-php-ext-enable pdo_sqlsrv; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS gnupg; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/docker/php-cts4911.ini "$PHP_INI_DIR/conf.d/zz-cts4911.ini"
COPY deploy/docker/apache-cts4911.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod headers \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

WORKDIR /var/www/app
COPY app/ app/
COPY public/ public/
COPY database/ database/
COPY scripts/ scripts/
COPY deploy/docker/entrypoint.sh /usr/local/bin/cts4911-entrypoint
RUN chmod 755 /usr/local/bin/cts4911-entrypoint && rm -f app/config.php

ENV APP_ENV=azure
EXPOSE 80
ENTRYPOINT ["cts4911-entrypoint"]
