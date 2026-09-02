#!/bin/sh

set -eu

cd /var/www/html

/usr/local/bin/cm-wait-for-migrations

exec docker-php-serversideup-entrypoint supervisord -c /etc/supervisor/conf.d/supervisord.conf
